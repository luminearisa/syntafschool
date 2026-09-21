<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbDocumentReviewRequest;
use App\Http\Requests\Ppdb\PpdbDocumentUploadRequest;
use App\Http\Resources\PpdbApplicantDocumentResource;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Services\Ppdb\PpdbDocumentService;
use App\Services\Ppdb\PpdbPortalAccess;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Dokumen calon — unggah ber-versi (portal) dan tinjauan (panitia).
 *
 * Aturan penting yang ditegakkan di sini:
 * - kepemilikan diperiksa lewat `PpdbPortalAccess` SEBELUM berkas apa pun
 *   disentuh,
 * - persyaratan yang boleh diunggah hanya yang berlaku pada scope
 *   periode/program/gelombang pendaftaran (`PpdbDocumentService::findRequirement`),
 * - unggah ulang TIDAK menimpa versi lama: versi baru dibuat, versi lama
 *   menjadi `is_active = false` dan tetap bisa dibaca (audit),
 * - seluruh penulisan (versi dokumen, status kelengkapan, riwayat, notifikasi)
 *   ditangani `PpdbDocumentService` + `PpdbRegistrationStateMachine`; controller
 *   ini tidak pernah menulis kolom `status`/`document_status` langsung.
 */
class PpdbApplicantDocumentController extends BaseApiController
{
    public function __construct(
        protected PpdbPortalAccess $access,
        protected PpdbDocumentService $documents,
        protected PpdbRegistrationQueryService $queries,
    ) {
    }

    // =====================================================================
    // PORTAL ORANG TUA
    // =====================================================================

    /**
     * GET /ppdb/portal/registrations/{registration}/documents
     *
     * Daftar persyaratan yang berlaku untuk pendaftaran ini + dokumen aktif +
     * SELURUH versinya (termasuk catatan tinjauan panitia), sehingga orang tua
     * tahu butir mana yang harus diunggah ulang.
     */
    public function index(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $account = $this->access->account($this->user($request));

        if ($account === null) {
            abort(403, 'Akun PPDB Anda tidak ditemukan. Silakan daftar terlebih dahulu.');
        }

        // Hanya-baca: pemilik data boleh melihat dokumennya meski akun sedang
        // tidak aktif; unggah (tulis) menuntut akun aktif.
        if (! $this->access->owns($account, $registration)) {
            abort(403, 'Pendaftaran tersebut bukan milik Anda.');
        }

        $registration->loadMissing(['period:id,name,status', 'program:id,name', 'wave:id,name', 'applicant:id,full_name']);

        return $this->successResponse([
            'registration' => $this->registrationContext($registration),
            ...$this->documents->annotated($registration),
        ], 'Daftar dokumen pendaftaran berhasil diambil');
    }

    /**
     * POST /ppdb/portal/registrations/{registration}/documents
     *
     * Batas ekstensi/ukuran sudah divalidasi `PpdbDocumentUploadRequest` dari
     * baris konfigurasi persyaratan (bukan nilai hardcode).
     */
    public function store(PpdbDocumentUploadRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $user = $this->user($request);
        $registration = $this->access->registration($user, $registration);
        $data = $request->validated();

        $requirement = $this->documents->findRequirement($registration, (int) $data['requirement_id']);

        if ($requirement === null) {
            throw ValidationException::withMessages([
                'requirement_id' => 'Persyaratan dokumen tersebut tidak berlaku untuk program dan gelombang '
                    .'pendaftaran ini. Muat ulang halaman, lalu pilih persyaratan yang tersedia.',
            ]);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Berkas dokumen wajib diunggah.',
            ]);
        }

        $document = $this->documents->upload($registration, $requirement, $file, $user);

        // Versi lama dibaca ulang supaya respons menunjukkan seluruh riwayat
        // (versi baru + versi lama yang kini non-aktif).
        $payload = $this->documents->annotated($registration->refresh());
        $slot = collect($payload['requirements'])->first(
            static fn (array $row): bool => (int) $row['id'] === (int) $document->requirement_id
        );

        return $this->createdResponse([
            'document' => $this->documents->present($document),
            'previous_versions' => array_values(array_filter(
                $slot['versions'] ?? [],
                static fn (array $row): bool => (int) $row['id'] !== (int) $document->id
                    && ! (bool) $row['is_active']
            )),
            'requirement' => $slot ?? ['id' => $document->requirement_id, 'name' => $document->requirement_name],
            'summary' => $payload['summary'],
            'registration' => $this->registrationContext($registration),
        ], sprintf(
            'Dokumen "%s" versi %d berhasil diunggah dan menunggu tinjauan panitia.',
            (string) $document->requirement_name,
            (int) $document->version,
        ));
    }

    // =====================================================================
    // PANITIA
    // =====================================================================

    /**
     * GET /ppdb/documents — antrean tinjauan dokumen lintas pendaftar.
     *
     * Bawaan hanya versi aktif; `?all_versions=1` menampilkan seluruh versi
     * (jejak unggah ulang).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = $this->adminQuery($request);

        $counts = $this->statusCounts($request);
        $documents = $this->paginate($request, $query);

        $rows = $documents instanceof LengthAwarePaginator
            ? $documents->getCollection()
            : $documents;

        return $this->successResponse(
            PpdbApplicantDocumentResource::collection($documents)->additional([
                'meta' => [
                    'counts' => $counts,
                    'total_returned' => $rows->count(),
                    'showing_inactive_versions' => $request->boolean('all_versions'),
                    'filter_options' => [
                        'statuses' => PpdbRegistrationQueryService::options(PpdbApplicantDocument::STATUSES),
                    ],
                    'current_page' => $documents instanceof LengthAwarePaginator ? $documents->currentPage() : 1,
                    'per_page' => $documents instanceof LengthAwarePaginator ? $documents->perPage() : $rows->count(),
                    'last_page' => $documents instanceof LengthAwarePaginator ? $documents->lastPage() : 1,
                ],
            ]),
            'Daftar dokumen berhasil diambil',
        );
    }

    /**
     * POST /ppdb/documents/{document}/review — setujui / tolak satu dokumen.
     *
     * Hanya versi aktif yang boleh ditinjau: status kelengkapan pendaftaran
     * dihitung dari versi aktif, jadi menilai versi lama akan menghasilkan data
     * yang menyesatkan.
     */
    public function review(PpdbDocumentReviewRequest $request, PpdbApplicantDocument $document): JsonResponse
    {
        $data = $request->validated();
        $status = (string) $data['status'];
        $note = isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null;

        $this->assertReviewable($document);

        $document->loadMissing('registration.applicant');

        $reviewed = $this->documents->review($document, $status, $note, $this->user($request));
        $registration = $reviewed->registration;

        return $this->successResponse([
            'document' => $this->documents->present($reviewed),
            'versions' => $this->versionsOf($reviewed),
            'registration' => $registration === null ? null : $this->registrationContext($registration),
        ], sprintf(
            'Dokumen "%s" %s%s.',
            (string) $reviewed->requirement_name,
            PpdbRegistrationQueryService::documentItemLabel($status),
            $status === PpdbApplicantDocument::STATUS_REJECTED ? ' dan orang tua telah menerima alasannya' : '',
        ));
    }

    // =====================================================================
    // HELPER
    // =====================================================================

    /**
     * Versi aktif saja? Hanya bila panitia memang belum menggantinya.
     */
    private function assertReviewable(PpdbApplicantDocument $document): void
    {
        if ((bool) $document->is_active) {
            return;
        }

        throw ValidationException::withMessages([
            'document' => sprintf(
                'Dokumen "%s" versi %d sudah diganti oleh versi terbaru, sehingga tidak dapat ditinjau lagi. '
                .'Tinjau versi yang aktif pada persyaratan yang sama.',
                (string) $document->requirement_name,
                (int) $document->version,
            ),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function versionsOf(PpdbApplicantDocument $document): array
    {
        return PpdbApplicantDocument::query()
            ->where('registration_id', $document->registration_id)
            ->where('requirement_id', $document->requirement_id)
            ->orderByDesc('version')
            ->get()
            ->map(static fn (PpdbApplicantDocument $row): array => PpdbApplicantDocumentResource::row($row))
            ->all();
    }

    /**
     * Ringkasan status pendaftaran setelah dokumen disinkronkan mesin status.
     *
     * @return array<string, mixed>
     */
    private function registrationContext(PpdbRegistration $registration): array
    {
        return [
            'id' => (int) $registration->id,
            'registration_number' => $registration->registration_number,
            'applicant_name' => $registration->applicant?->full_name,
            'status' => $registration->status,
            'status_label' => PpdbRegistrationQueryService::statusLabel((string) $registration->status),
            'document_status' => $registration->document_status,
            'document_status_label' => PpdbRegistrationQueryService::documentLabel((string) $registration->document_status),
            'program_name' => $registration->program?->name,
            'wave_name' => $registration->wave?->name,
            'period_name' => $registration->period?->name,
            'revision_note' => $registration->revision_note,
            'can_upload' => $this->documents->canUpload($registration),
        ];
    }

    /** @return Builder<PpdbApplicantDocument> */
    private function adminQuery(Request $request, bool $withRelations = true): Builder
    {
        $query = PpdbApplicantDocument::query()
            ->when($withRelations, static fn (Builder $q) => $q->with([
                'registration:id,registration_number,status,document_status,period_id,program_id,wave_id',
                'applicant:id,full_name',
                'requirement:id,name,code',
            ]))
            ->when(! $request->boolean('all_versions'), fn (Builder $q) => $q->where('is_active', true))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('requirement_id'), fn (Builder $q) => $q->where('requirement_id', (int) $request->input('requirement_id')))
            ->when($request->filled('period_id'), fn (Builder $q) => $q->whereHas('registration', fn (Builder $r) => $r->where('period_id', (int) $request->input('period_id'))))
            ->when($request->filled('program_id'), fn (Builder $q) => $q->whereHas('registration', fn (Builder $r) => $r->where('program_id', (int) $request->input('program_id'))))
            ->when($request->filled('wave_id'), fn (Builder $q) => $q->whereHas('registration', fn (Builder $r) => $r->where('wave_id', (int) $request->input('wave_id'))))
            ->when($request->filled('registration_status'), fn (Builder $q) => $q->whereHas('registration', fn (Builder $r) => $r->where('status', (string) $request->input('registration_status'))))
            ->when($request->filled('registration_id'), fn (Builder $q) => $q->where('registration_id', (int) $request->input('registration_id')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';

                $q->where(static function (Builder $inner) use ($term): void {
                    $inner->where('requirement_name', 'like', $term)
                        ->orWhereHas('registration', fn (Builder $r) => $r->where('registration_number', 'like', $term))
                        ->orWhereHas('applicant', fn (Builder $a) => $a->where('full_name', 'like', $term));
                });
            })
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id');

        return $query;
    }

    /**
     * Hitung jumlah per status tinjauan pada filter yang sama (tanpa paging
     * maupun eager load, karena query ini di-group).
     *
     * @return array<string, int>
     */
    private function statusCounts(Request $request): array
    {
        $rows = $this->adminQuery($request, false)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts = [];

        foreach (PpdbApplicantDocument::STATUSES as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /** @param  Builder<PpdbApplicantDocument>  $query */
    private function paginate(Request $request, Builder $query): LengthAwarePaginator|Collection
    {
        if ($request->boolean('all')) {
            return $query->get();
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $query->paginate($perPage)->appends($request->query());
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, $request->routeIs('ppdb.portal.*')
                ? 'Anda harus masuk sebagai orang tua/wali untuk mengakses portal ini.'
                : 'Anda harus masuk untuk meninjau dokumen.');
        }

        return $user;
    }
}
