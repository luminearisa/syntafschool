<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbSelectionParticipantRequest;
use App\Http\Requests\Ppdb\PpdbSelectionScoreRequest;
use App\Http\Requests\Ppdb\PpdbSelectionStageRequest;
use App\Http\Requests\Ppdb\PpdbSelectionWeightsRequest;
use App\Http\Resources\PpdbInvoiceResource;
use App\Http\Resources\PpdbSelectionParticipantResource;
use App\Http\Resources\PpdbSelectionStageResource;
use App\Models\PpdbInvoice;
use App\Models\PpdbRegistration;
use App\Models\PpdbSelectionParticipant;
use App\Models\PpdbSelectionStage;
use App\Models\User;
use App\Services\Ppdb\PpdbInvoiceService;
use App\Services\Ppdb\PpdbPortalAccess;
use App\Services\Ppdb\PpdbSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Seleksi PPDB: tahap, peserta, nilai, dan skor gabungan.
 *
 * Sisi admin (`ppdb.selection.manage`): CRUD tahap, daftarkan peserta, lihat
 * calon yang belum terdaftar, catat kehadiran, beri nilai, dan susun bobot.
 * Sisi portal: `forRegistration` — orang tua hanya melihat tahap + nilainya
 * sendiri, tidak pernah nilai calon lain.
 *
 * Dua aturan yang dijaga di sini karena bentuknya bukan sekadar CRUD:
 * 1. **Bobot** (keputusan pemilik + audit §26): total satu grup tahap wajib
 *    tepat 100% dan tidak pernah dinormalisasi otomatis. Penulisan per-tahap
 *    hanya menolak total > 100% (tahap memang disusun satu per satu), sedangkan
 *    `PUT selection-stages/weights` menuntut grupnya pas 100% sebelum menyimpan.
 *    Grup yang belum pas menolak dihitung skor gabungannya.
 * 2. **Skor gabungan** dihitung dari kolom `weight` di database, bukan angka di
 *    dalam kode; rumusnya ikut dikembalikan supaya UI menampilkannya apa adanya.
 */
class PpdbSelectionController extends BaseApiController
{
    public function __construct(
        protected PpdbSelectionService $selection,
        protected PpdbPortalAccess $access,
        protected PpdbInvoiceService $invoices,
    ) {
    }

    /**
     * GET /ppdb/selection-stages — daftar tahap per periode/program.
     *
     * Sekalian mengembalikan total bobot tiap grup yang tampil di halaman, jadi
     * panitia tahu grup mana yang belum 100% tanpa membuka editor bobot.
     */
    public function index(Request $request): JsonResponse
    {
        $stages = $this->selection->query($request)
            ->paginate($this->perPage($request))
            ->appends($request->query());

        $summaries = [];

        foreach ($stages->getCollection() as $stage) {
            $key = $stage->period_id.':'.($stage->program_id ?? 0);

            $summaries[$key] ??= $this->selection->weightSummary(
                (int) $stage->period_id,
                $stage->program_id === null ? null : (int) $stage->program_id,
            );
        }

        return $this->successResponse([
            'stages' => PpdbSelectionStageResource::collection($stages),
            'weight_groups' => $summaries,
            'pagination' => [
                'current_page' => $stages->currentPage(),
                'last_page' => $stages->lastPage(),
                'per_page' => $stages->perPage(),
                'total' => $stages->total(),
            ],
            'required_weight_total' => PpdbSelectionService::REQUIRED_WEIGHT_TOTAL,
        ], 'Daftar tahap seleksi berhasil diambil');
    }

    /** POST /ppdb/selection-stages. */
    public function store(PpdbSelectionStageRequest $request): JsonResponse
    {
        $stage = $this->selection->create($request->validated());

        return $this->createdResponse(
            new PpdbSelectionStageResource($stage),
            'Tahap seleksi berhasil dibuat',
        );
    }

    /** GET /ppdb/selection-stages/{selectionStage} — tahap + total bobot grupnya. */
    public function show(PpdbSelectionStage $selectionStage): JsonResponse
    {
        $selectionStage->load(['period:id,name,code', 'program:id,name,code']);

        $selectionStage->loadCount([
            'participants',
            'participants as scored_participants_count' => fn ($q) => $q->where('status', PpdbSelectionParticipant::STATUS_SCORED),
        ]);

        return $this->successResponse([
            'stage' => new PpdbSelectionStageResource($selectionStage),
            'weights' => $this->selection->weightSummary(
                (int) $selectionStage->period_id,
                $selectionStage->program_id === null ? null : (int) $selectionStage->program_id,
            ),
            'score_scale' => $this->selection->scoreScale($selectionStage),
        ], 'Detail tahap seleksi berhasil diambil');
    }

    /** PUT /ppdb/selection-stages/{selectionStage}. */
    public function update(PpdbSelectionStageRequest $request, PpdbSelectionStage $selectionStage): JsonResponse
    {
        $stage = $this->selection->update($selectionStage, $request->validated());

        return $this->successResponse(
            new PpdbSelectionStageResource($stage),
            'Tahap seleksi berhasil diperbarui',
        );
    }

    /**
     * DELETE /ppdb/selection-stages/{selectionStage} — soft delete, dan ditolak
     * bila masih ada pesertanya (jejak penilaian tidak boleh hilang).
     */
    public function destroy(PpdbSelectionStage $selectionStage): JsonResponse
    {
        $this->selection->delete($selectionStage);

        return $this->successResponse(null, 'Tahap seleksi berhasil dihapus');
    }

    /**
     * GET /ppdb/selection-stages/weights — total bobot satu grup tahap.
     *
     * `program_id` kosong = grup tahap yang berlaku untuk semua program.
     */
    public function weights(PpdbSelectionWeightsRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->weightsPayload($request->periodId(), $request->programId()),
            'Total bobot tahap seleksi berhasil diambil',
        );
    }

    /**
     * PUT /ppdb/selection-stages/weights — simpan bobot grup sekaligus.
     *
     * Aksi ini satu-satunya jalan menulis bobot yang menuntut total **tepat
     * 100%** (keputusan pemilik, tanpa normalisasi otomatis): seluruh tahap grup
     * harus ikut terkirim, dan tidak ada satu baris pun disimpan bila totalnya
     * meleset.
     */
    public function syncWeights(PpdbSelectionWeightsRequest $request): JsonResponse
    {
        $this->selection->applyWeights(
            $request->periodId(),
            $request->programId(),
            $request->weightMap(),
        );

        // Ringkasan dibaca ulang sesudah simpan: `summary.valid` adalah bukti total
        // grup sekarang benar-benar 100%, bukan echo dari angka yang dikirim.
        return $this->successResponse(
            $this->weightsPayload($request->periodId(), $request->programId()),
            'Bobot tahap seleksi berhasil disimpan',
        );
    }

    /**
     * GET /ppdb/selection-stages/{selectionStage}/candidates — calon terverifikasi
     * yang belum terdaftar pada tahap ini.
     */
    public function candidates(Request $request, PpdbSelectionStage $selectionStage): JsonResponse
    {
        $candidates = $this->selection->candidatesQuery($selectionStage)
            ->paginate($this->perPage($request))
            ->appends($request->query());

        $rows = collect($candidates->items())->map(static fn (PpdbRegistration $registration): array => [
            'id' => $registration->id,
            'registration_number' => $registration->registration_number,
            'status' => $registration->status,
            'payment_status' => $registration->payment_status,
            'program_id' => $registration->program_id,
            'wave_id' => $registration->wave_id,
            'applicant_name' => $registration->applicant?->full_name,
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
        ])->all();

        return $this->successResponse([
            'candidates' => $rows,
            'pagination' => [
                'current_page' => $candidates->currentPage(),
                'last_page' => $candidates->lastPage(),
                'per_page' => $candidates->perPage(),
                'total' => $candidates->total(),
            ],
            'selectable_statuses' => PpdbSelectionService::SELECTABLE_STATUSES,
        ], 'Daftar calon peserta seleksi berhasil diambil');
    }

    /**
     * POST /ppdb/selection-stages/{selectionStage}/participants — daftarkan peserta.
     *
     * Baris yang tidak bisa masuk (status belum terverifikasi / sudah terdaftar)
     * dikembalikan sebagai `skipped`, bukan menggagalkan seluruh permintaan.
     */
    public function registerParticipants(
        PpdbSelectionParticipantRequest $request,
        PpdbSelectionStage $selectionStage,
    ): JsonResponse {
        $result = $this->selection->registerParticipants(
            $selectionStage,
            (array) $request->validated('registration_ids'),
        );

        $skipped = count($result['skipped']);

        return $this->successResponse(
            $result,
            $skipped === 0
                ? count($result['created']).' calon berhasil didaftarkan pada tahap ini'
                : count($result['created']).' calon didaftarkan, '.$skipped.' baris dilewati (lihat `skipped`)',
        );
    }

    /**
     * GET /ppdb/selection-stages/{selectionStage}/participants — peserta + skor
     * gabungan berbobot tiap calon.
     */
    public function participants(Request $request, PpdbSelectionStage $selectionStage): JsonResponse
    {
        $participants = $this->selection->participantsQuery($selectionStage, $request)
            ->paginate($this->perPage($request))
            ->appends($request->query());

        // Skor gabungan butuh seluruh tahap calon ini, bukan hanya tahap yang
        // sedang dibuka — dihitung sekali per halaman lewat service.
        $this->selection->attachComposites($participants->getCollection());

        return $this->successResponse(
            PpdbSelectionParticipantResource::collection($participants),
            'Daftar peserta seleksi berhasil diambil',
        );
    }

    /**
     * POST /ppdb/selection-participants/{selectionParticipant}/score — isi nilai.
     *
     * Hasil otomatis diturunkan dari `passing_score` tahap bila tidak dikirim
     * eksplisit; nilai di luar skala tahap ditolak.
     */
    public function score(PpdbSelectionScoreRequest $request, PpdbSelectionParticipant $selectionParticipant): JsonResponse
    {
        $participant = $this->selection->score(
            $selectionParticipant,
            $request->validated(),
            $request->user(),
        );

        return $this->successResponse(
            new PpdbSelectionParticipantResource($this->withComposite($participant)),
            'Nilai peserta seleksi berhasil disimpan',
        );
    }

    /**
     * POST /ppdb/selection-participants/{selectionParticipant}/attend — catat
     * kehadiran tanpa (belum) memberi nilai.
     */
    public function attend(Request $request, PpdbSelectionParticipant $selectionParticipant): JsonResponse
    {
        $data = $request->validate([
            'attended_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $participant = $this->selection->markAttended(
            $selectionParticipant,
            filled($data['attended_at'] ?? null) ? Carbon::parse($data['attended_at']) : null,
            $request->user(),
            $data['note'] ?? null,
        );

        return $this->successResponse(
            new PpdbSelectionParticipantResource($this->withComposite($participant)),
            'Kehadiran peserta seleksi berhasil dicatat',
        );
    }

    /**
     * GET /ppdb/portal/registrations/{registration}/selection — halaman hasil
     * seleksi orang tua.
     *
     * Hanya tahap + nilai milik pendaftaran itu sendiri, skor gabungan, jendela
     * daftar ulang, dan total bobot grup (agar UI bisa menjelaskan kenapa skor
     * gabungan belum tersedia). Kepemilikan diperiksa lewat `PpdbPortalAccess`.
     */
    public function forRegistration(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $user = $this->actor($request);

        $this->access->registration($user, $registration);

        $registration->loadMissing([
            'applicant:id,full_name,nickname',
            'program:id,name,code',
            'period:id,name,code,re_registration_start,re_registration_end',
        ]);

        $summary = $this->selection->selectionSummary($registration);

        $reRegistration = $this->invoices->forRegistration($registration, PpdbInvoice::TYPE_RE_REGISTRATION)->first();

        return $this->successResponse([
            'registration_id' => $registration->id,
            'registration_number' => $registration->registration_number,
            'status' => $registration->status,
            'selection_result' => $registration->selection_result,
            'announced_at' => $registration->announced_at?->toIso8601String(),
            'applicant_name' => $registration->applicant?->full_name,
            'program' => $registration->program?->name,
            'stages' => $summary['stages'],
            'composite' => $summary['composite'],
            'weights' => $summary['weights'],
            'formula' => $summary['formula'],
            're_registration' => [
                'open' => in_array($registration->status, [
                    PpdbRegistration::STATUS_RE_REGISTRATION,
                    PpdbRegistration::STATUS_COMPLETED,
                ], true),
                'start' => $registration->period?->re_registration_start?->toDateString(),
                'end' => $registration->period?->re_registration_end?->toDateString(),
                'invoice' => $reRegistration === null ? null : new PpdbInvoiceResource(
                    $reRegistration->loadMissing(['items', 'payments.paymentMethod'])
                ),
            ],
        ], 'Hasil seleksi berhasil diambil');
    }

    /**
     * @return array<string, mixed>
     */
    private function weightsPayload(int $periodId, ?int $programId): array
    {
        $stages = PpdbSelectionStage::query()
            ->where('period_id', $periodId)
            ->when(
                $programId === null,
                fn ($q) => $q->whereNull('program_id'),
                fn ($q) => $q->where(fn ($s) => $s->whereNull('program_id')->orWhere('program_id', $programId)),
            )
            ->orderBy('order')
            ->get();

        return [
            'period_id' => $periodId,
            'program_id' => $programId,
            'stages' => $stages->map(static fn (PpdbSelectionStage $stage): array => [
                'stage_id' => $stage->id,
                'name' => $stage->name,
                'type' => $stage->type,
                'program_id' => $stage->program_id,
                'weight' => round((float) $stage->weight, 2),
            ])->all(),
            'summary' => $this->selection->weightSummary($periodId, $programId),
            'required_total' => PpdbSelectionService::REQUIRED_WEIGHT_TOTAL,
        ];
    }

    /**
     * Peserta + skor gabungan + relasi yang dibutuhkan resource respons.
     */
    private function withComposite(PpdbSelectionParticipant $participant): PpdbSelectionParticipant
    {
        $participant->loadMissing([
            'registration.applicant:id,full_name,nickname',
            'stage:id,name,type,weight,passing_score',
            'scoredBy:id,name',
        ]);

        $this->selection->attachComposites([$participant]);

        return $participant;
    }

    private function perPage(Request $request): int
    {
        return max(1, min(200, (int) $request->query('per_page', 15)));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Autentikasi diperlukan untuk mengakses data ini.');

        return $user;
    }
}
