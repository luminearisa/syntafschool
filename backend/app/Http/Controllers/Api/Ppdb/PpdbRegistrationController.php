<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbApplicantRequest;
use App\Http\Requests\Ppdb\PpdbRegistrationStoreRequest;
use App\Http\Requests\Ppdb\PpdbRegistrationTransitionRequest;
use App\Http\Requests\Ppdb\PpdbRegistrationUpdateRequest;
use App\Http\Requests\Ppdb\PpdbRequestRevisionRequest;
use App\Http\Resources\PpdbRegistrationDetailResource;
use App\Http\Resources\PpdbRegistrationResource;
use App\Models\PpdbApplicant;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbWave;
use App\Models\User;
use App\Services\Ppdb\PpdbAnswerService;
use App\Services\Ppdb\PpdbNumberingService;
use App\Services\Ppdb\PpdbParentAccountService;
use App\Services\Ppdb\PpdbPortalAccess;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use App\Services\Ppdb\PpdbRegistrationStateMachine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran PPDB: portal orang tua (index/store/show/update/submit) dan
 * panitia (daftar, detail, riwayat, transisi, revisi, verifikasi, tolak, batal).
 *
 * Pembagian kerja:
 * - kepemilikan data portal  -> `PpdbPortalAccess` (403, bukan 404: ID tebakan
 *   memang ada, hanya bukan milik pemanggil),
 * - baca/agregasi            -> `PpdbRegistrationQueryService`,
 * - perubahan status         -> `PpdbRegistrationStateMachine` (SATU-SATUNYA
 *   jalur yang boleh menulis `status`; ia juga yang menulis riwayat + notifikasi),
 * - jawaban formulir         -> `PpdbAnswerService` (+ aturan per-key dari
 *   `PpdbScopeService`, jadi tidak ada field yang di-hardcode).
 *
 * PENTING: `submit` TIDAK membuat tagihan. Tagihan dibuat idempoten oleh
 * `PpdbInvoiceController::forRegistration` (modul pembayaran) saat orang tua
 * membuka halaman pembayaran, supaya tidak ada dua pencipta invoice.
 */
class PpdbRegistrationController extends BaseApiController
{
    public function __construct(
        protected PpdbPortalAccess $access,
        protected PpdbRegistrationQueryService $queries,
        protected PpdbAnswerService $answers,
        protected PpdbRegistrationStateMachine $machine,
        protected PpdbNumberingService $numbering,
        protected PpdbParentAccountService $accounts,
    ) {
    }

    // =====================================================================
    // PORTAL ORANG TUA
    // =====================================================================

    /** GET /ppdb/portal/registrations — hanya pendaftaran milik akun pemanggil. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->reader($request);

        $registrations = $this->paginate($request, $this->queries->portalQuery($account, $request));

        $rows = $registrations instanceof LengthAwarePaginator
            ? $registrations->getCollection()
            : $registrations;

        return $this->successResponse(
            PpdbRegistrationResource::collection($registrations)->additional([
                'meta' => $this->queries->counts($rows) + $this->pagination($registrations),
            ]),
            'Daftar pendaftaran berhasil diambil',
        );
    }

    /**
     * Kunci paginasi untuk `meta`.
     *
     * `additional(['meta' => ...])` MENIMPA meta bawaan paginator, jadi
     * `current_page`/`last_page`/`total` harus ditulis ulang di sini — tanpa
     * itu halaman daftar hanya melihat hitungan baris pada halaman ini.
     *
     * @param  LengthAwarePaginator|Collection<int, PpdbRegistration>  $registrations
     * @return array<string, int>
     */
    private function pagination(LengthAwarePaginator|Collection $registrations): array
    {
        if (! $registrations instanceof LengthAwarePaginator) {
            return [
                'current_page' => 1,
                'per_page' => max(1, $registrations->count()),
                'last_page' => 1,
                'total' => $registrations->count(),
            ];
        }

        return [
            'current_page' => $registrations->currentPage(),
            'per_page' => $registrations->perPage(),
            'last_page' => $registrations->lastPage(),
            'total' => $registrations->total(),
        ];
    }

    /**
     * POST /ppdb/portal/registrations — daftar anak KEDUA & seterusnya.
     *
     * Semua pendaftaran menempel pada `parent_account_id` yang sama; tidak ada
     * `users` baru yang dibuat di sini.
     */
    public function store(PpdbRegistrationStoreRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $account = $this->access->requireAccount($user);
        $data = $request->validated();

        $applicant = PpdbApplicant::query()->findOrFail((int) $data['applicant_id']);
        $this->access->applicant($user, $applicant);

        $period = PpdbPeriod::query()->findOrFail((int) $data['period_id']);
        $program = PpdbProgram::query()->findOrFail((int) $data['program_id']);
        $wave = empty($data['wave_id']) ? null : PpdbWave::query()->findOrFail((int) $data['wave_id']);

        $errors = [];
        $this->assertSelectionInPeriod($period, $program, $wave, $errors);
        $this->assertRegistrationWindowOpen($period, $wave, $errors);
        $this->assertApplicantFree($applicant, $period, null, $errors);
        $this->assertQuotaAvailable($period, $program, $wave, null, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $registration = DB::transaction(function () use ($data, $account, $applicant, $period, $program, $wave, $user): PpdbRegistration {
            $registration = PpdbRegistration::create([
                // Nomor diambil dari `document_sequences` (atomik) — bukan string buatan sendiri.
                'registration_number' => $this->numbering->next('registration'),
                'period_id' => $period->id,
                'wave_id' => $wave?->id,
                'program_id' => $program->id,
                'applicant_id' => $applicant->id,
                'parent_account_id' => $account->id,
                // `status` tidak ditulis manual: bawaan tabel sudah `draft` dan
                // hanya mesin status yang boleh memindahkannya.
                'notes' => $data['notes'] ?? null,
            ])->refresh();

            $this->machine->record(
                $registration,
                'created',
                'Pendaftaran dibuat dari portal orang tua.',
                $user,
                null,
                PpdbRegistration::STATUS_DRAFT,
            );

            return $registration;
        });

        return $this->createdResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration)),
            'Pendaftaran berhasil dibuat. Lengkapi formulir lalu kirim sebelum batas waktu.',
        );
    }

    /** GET /ppdb/portal/registrations/{registration} — detail + skema + progres. */
    public function show(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $this->readable($request, $registration);

        return $this->successResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration)),
            'Detail pendaftaran berhasil diambil',
        );
    }

    /**
     * PUT /ppdb/portal/registrations/{registration} — AUTOSAVE draf.
     *
     * Hanya sah selama status ada di `PpdbRegistration::EDITABLE_STATUSES`
     * (draft, need_revision). Aturan wajib dilepas di FormRequest; kelengkapan
     * ditegakkan saat `submit`.
     */
    public function update(PpdbRegistrationUpdateRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $user = $this->user($request);
        $this->access->registration($user, $registration);
        $this->assertEditable($registration);

        $data = $request->validated();
        $changed = [];

        if (array_key_exists('applicant', $data)) {
            $this->assertApplicantNotLockedElsewhere($registration);
            $attributes = PpdbApplicantRequest::portalOnly((array) $data['applicant']);

            if ($attributes !== []) {
                $this->accounts->updateApplicant(
                    $this->access->applicant($user, $registration->applicant ?? PpdbApplicant::findOrFail($registration->applicant_id)),
                    $attributes,
                );
                $changed[] = 'biodata calon';
            }
        }

        if (array_key_exists('fields', $data) || array_key_exists('questions', $data)) {
            $saved = $this->answers->replace(
                $registration,
                (array) ($data['fields'] ?? []),
                (array) ($data['questions'] ?? []),
            );

            $changed[] = $saved['saved'].' jawaban formulir';

            if ($saved['ignored'] !== []) {
                $changed[] = count($saved['ignored']).' kunci di luar formulir diabaikan';
            }
        }

        $this->machine->record(
            $registration,
            'draft_saved',
            'Perubahan draf disimpan dari portal: '.($changed === [] ? 'tidak ada kolom baru' : implode(', ', $changed)).'.',
            $user,
        );

        $fresh = $registration->fresh();

        return $this->successResponse(
            new PpdbRegistrationDetailResource($fresh, $this->queries->detail($fresh)),
            'Draft pendaftaran berhasil disimpan',
        );
    }

    /**
     * POST /ppdb/portal/registrations/{registration}/submit.
     *
     * PINTU KELUAR DARI STATUS DRAF. Yang diperiksa sebelum transisi
     * `draft|need_revision -> submitted`:
     * 1. status memang boleh mengirim (`TRANSITIONS`),
     * 2. periode & gelombang masih membuka pendaftaran, program+gelombang dipilih
     *    dan benar-benar berada dalam scope periode,
     * 3. kuota program & gelombang belum penuh,
     * 4. field wajib formulir, pertanyaan wajib, dokumen wajib, dan biodata calon
     *    sudah lengkap (`PpdbRegistrationQueryService::blockers()`),
     * 5. tagihan TIDAK dibuat di sini (kontrak modul pembayaran).
     */
    public function submit(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $user = $this->user($request);
        $this->access->registration($user, $registration);

        $registration->loadMissing(['applicant', 'period', 'program', 'wave']);

        $errors = [];

        if (! in_array(PpdbRegistration::STATUS_SUBMITTED, $registration->allowedTransitions(), true)) {
            $errors['status'] = [$this->submitBlockedMessage($registration)];
        }

        $period = $registration->period;
        $program = $registration->program;
        $wave = $registration->wave;

        if ($period === null || $program === null) {
            $errors['program_id'] = ['Pendaftaran belum punya periode/program yang sah. Hubungi panitia.'];
        } else {
            $this->assertSelectionInPeriod($period, $program, $wave, $errors);
            $this->assertRegistrationWindowOpen($period, $wave, $errors);
            $this->assertQuotaAvailable($period, $program, $wave, $registration->id, $errors);

            if ($wave === null) {
                $errors['wave_id'] = ['Pilih gelombang pendaftaran terlebih dahulu sebelum mengirim.'];
            }
        }

        // `blockers()` juga mengembalikan angka ringkasan (total/ready) — hanya
        // empat sumbu daftar penghambat yang punya pesan.
        $allBlockers = $this->queries->blockers($registration);

        foreach (['fields', 'questions', 'documents', 'applicant'] as $axis) {
            foreach ($this->blockerMessages($axis, $allBlockers[$axis] ?? []) as $key => $messages) {
                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $submitted = DB::transaction(fn (): PpdbRegistration => $this->machine->transition(
            $registration,
            PpdbRegistration::STATUS_SUBMITTED,
            $this->note($request, 'Pendaftaran dikirim oleh orang tua.'),
            $user,
        ));

        return $this->successResponse(
            new PpdbRegistrationDetailResource($submitted, $this->queries->detail($submitted)),
            'Pendaftaran berhasil dikirim dan menunggu verifikasi panitia.',
        );
    }

    // =====================================================================
    // PANITIA
    // =====================================================================

    /** GET /ppdb/registrations — daftar pendaftaran lintas akun (filter lengkap). */
    public function adminIndex(Request $request): JsonResponse
    {
        $registrations = $this->paginate($request, $this->queries->adminQuery($request));

        $rows = $registrations instanceof LengthAwarePaginator
            ? $registrations->getCollection()
            : $registrations;

        return $this->successResponse(
            PpdbRegistrationResource::collection($registrations)->additional([
                'meta' => $this->queries->counts($rows) + $this->pagination($registrations) + [
                    'filter_options' => [
                        'statuses' => PpdbRegistrationQueryService::options(PpdbRegistration::STATUSES),
                    ],
                ],
            ]),
            'Daftar pendaftaran berhasil diambil',
        );
    }

    /** GET /ppdb/registrations/{registration} — detail + riwayat + tagihan + seleksi. */
    public function adminShow(PpdbRegistration $registration): JsonResponse
    {
        return $this->successResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration, true)),
            'Detail pendaftaran berhasil diambil',
        );
    }

    /** GET /ppdb/registrations/{registration}/history — rekam jejak penuh. */
    public function history(PpdbRegistration $registration): JsonResponse
    {
        return $this->successResponse(
            $this->queries->histories($registration),
            'Riwayat pendaftaran berhasil diambil',
        );
    }

    /**
     * POST /ppdb/registrations/{registration}/transition — panitia memindahkan
     * status. Transisi ilegal dibalas 422 beserta jalur status yang diperlukan.
     */
    public function transition(PpdbRegistrationTransitionRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $data = $request->validated();
        $to = (string) $data['status'];

        $this->assertTransitionAllowed($registration, $to);

        $registration = $this->machine->transition(
            $registration,
            $to,
            isset($data['note']) ? (string) $data['note'] : null,
            $request->user(),
            (bool) ($data['notify'] ?? true),
        );

        return $this->successResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration, true)),
            'Status pendaftaran berhasil diperbarui',
        );
    }

    /**
     * POST /ppdb/registrations/{registration}/request-revision.
     *
     * `revision_note` yang dilihat orang tua = catatan panitia + daftar butir
     * yang ditandai (field/pertanyaan/dokumen) beserta alasannya, supaya calon
     * tahu PERSIS apa yang harus diperbaiki.
     */
    public function requestRevision(PpdbRequestRevisionRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $data = $request->validated();
        $note = $this->renderRevisionNote((string) $data['note'], (array) ($data['targets'] ?? []));

        $this->assertTransitionAllowed($registration, PpdbRegistration::STATUS_NEED_REVISION);

        $registration = DB::transaction(function () use ($registration, $note, $data, $request): PpdbRegistration {
            $this->machine->record(
                $registration,
                'revision.requested',
                $note,
                $request->user(),
            );

            return $this->machine->transition(
                $registration,
                PpdbRegistration::STATUS_NEED_REVISION,
                $note,
                $request->user(),
                (bool) ($data['notify'] ?? true),
            );
        });

        return $this->successResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration, true)),
            'Permintaan revisi terkirim. Calon dapat memperbaiki datanya kembali.',
        );
    }

    /** POST /ppdb/registrations/{registration}/verify — berkas dinyatakan sesuai. */
    public function verify(Request $request, PpdbRegistration $registration): JsonResponse
    {
        return $this->moveTo($request, $registration, PpdbRegistration::STATUS_VERIFIED, 'Pendaftaran berhasil diverifikasi');
    }

    /**
     * POST /ppdb/registrations/{registration}/reject — berkas ditolak panitia.
     *
     * Keputusan: `PpdbRegistration::STATUSES` tidak punya status "rejected", jadi
     * penolakan dipetakan ke `cancelled` (final) lewat mesin status, dengan
     * alasan WAJIB dan baris riwayat `registration.rejected` agar jejak "ditolak"
     * tetap terbaca berbeda dari pembatalan biasa.
     */
    public function reject(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'notify' => ['nullable', 'boolean'],
        ], [], ['note' => 'alasan penolakan']);

        $this->assertTransitionAllowed($registration, PpdbRegistration::STATUS_CANCELLED);

        $this->machine->record($registration, 'registration.rejected', (string) $data['note'], $request->user());

        $registration = $this->machine->transition(
            $registration,
            PpdbRegistration::STATUS_CANCELLED,
            'Ditolak panitia: '.(string) $data['note'],
            $request->user(),
            (bool) ($data['notify'] ?? true),
        );

        return $this->successResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration, true)),
            'Pendaftaran ditolak dan alasannya dikirim ke orang tua',
        );
    }

    /** POST /ppdb/registrations/{registration}/cancel — pembatalan final. */
    public function cancel(Request $request, PpdbRegistration $registration): JsonResponse
    {
        return $this->moveTo($request, $registration, PpdbRegistration::STATUS_CANCELLED, 'Pendaftaran berhasil dibatalkan');
    }

    // =====================================================================
    // HELPER VALIDASI ALUR
    // =====================================================================

    /**
     * Kunci tulis: hanya status di `EDITABLE_STATUSES` yang boleh diubah calon.
     */
    private function assertEditable(PpdbRegistration $registration): void
    {
        if ($registration->isEditable()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => sprintf(
                'Pendaftaran tidak dapat diubah lagi karena berstatus "%s". '
                .'Perubahan hanya dibuka ketika panitia meminta revisi.',
                PpdbRegistrationQueryService::statusLabel((string) $registration->status),
            ),
        ]);
    }

    /**
     * Program & gelombang harus milik periode yang sama dan masih aktif
     * (scope — bukan sekadar ID yang ada di tabel).
     *
     * @param  array<string, list<string>>  $errors
     */
    private function assertSelectionInPeriod(PpdbPeriod $period, PpdbProgram $program, ?PpdbWave $wave, array &$errors): void
    {
        if ((int) $program->period_id !== (int) $period->id) {
            $errors['program_id'][] = 'Program "'.$program->name.'" tidak dibuka pada periode "'.$period->name.'".';
        }

        if ($program->status !== PpdbProgram::STATUS_ACTIVE) {
            $errors['program_id'][] = 'Program "'.$program->name.'" sedang tidak aktif.';
        }

        if ($wave === null) {
            return;
        }

        if ((int) $wave->period_id !== (int) $period->id) {
            $errors['wave_id'][] = 'Gelombang "'.$wave->name.'" bukan bagian dari periode ini.';
        }

        if ($wave->status !== PpdbWave::STATUS_ACTIVE) {
            $errors['wave_id'][] = 'Gelombang "'.$wave->name.'" sedang tidak aktif.';
        }
    }

    /**
     * Jendela pendaftaran: periode harus `open` + dalam rentang tanggal, dan
     * gelombang (bila dipilih) juga sedang dibuka hari ini.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function assertRegistrationWindowOpen(PpdbPeriod $period, ?PpdbWave $wave, array &$errors): void
    {
        if (! $period->isRegistrationWindowOpen()) {
            $errors['period_id'][] = sprintf(
                'Pendaftaran pada periode "%s" sedang ditutup%s. Rentang pendaftaran: %s s.d. %s.',
                $period->name,
                $period->status === PpdbPeriod::STATUS_OPEN
                    ? ''
                    : ' (status periode saat ini: '.$period->status.')',
                $period->registration_start?->toDateString() ?? 'belum ditetapkan',
                $period->registration_end?->toDateString() ?? 'belum ditetapkan',
            );
        }

        if ($wave !== null && ! $wave->isOpenOn()) {
            $errors['wave_id'][] = sprintf(
                'Gelombang "%s" tidak menerima pendaftaran hari ini (%s s.d. %s).',
                $wave->name,
                $wave->registration_start?->toDateString() ?? 'kapan saja',
                $wave->registration_end?->toDateString() ?? 'tanpa batas',
            );
        }
    }

    /**
     * Satu calon hanya satu pendaftaran "hidup" per periode; pendaftaran yang
     * sudah dibatalkan tidak mengunci calon (anak boleh daftar lagi).
     *
     * @param  array<string, list<string>>  $errors
     */
    private function assertApplicantFree(PpdbApplicant $applicant, PpdbPeriod $period, ?int $ignoreId, array &$errors): void
    {
        $existing = PpdbRegistration::query()
            ->where('applicant_id', $applicant->id)
            ->where('period_id', $period->id)
            ->where('status', '!=', PpdbRegistration::STATUS_CANCELLED)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($existing === null) {
            return;
        }

        $errors['applicant_id'][] = sprintf(
            'Calon "%s" sudah punya pendaftaran pada periode ini (%s, status %s). '
            .'Ubah pendaftaran yang sudah ada atau hubungi panitia untuk membatalkannya.',
            (string) $applicant->full_name,
            (string) $existing->registration_number,
            PpdbRegistrationQueryService::statusLabel((string) $existing->status),
        );
    }

    /**
     * Kuota program & gelombang (kedua sumbu diuji terpisah; `quota` null/0 =
     * tidak dibatasi, sama seperti konvensi modul seleksi).
     *
     * $ignore dipakai saat submit: pendaftaran yang sedang dikirim sudah dihitung
     * oleh bacaan kuota, jadi tidak boleh menghitung dirinya sendiri.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function assertQuotaAvailable(
        PpdbPeriod $period,
        PpdbProgram $program,
        ?PpdbWave $wave,
        ?int $ignore,
        array &$errors,
    ): void {
        foreach ([['program', $program->id, $program->quota, $program->name, 'program_id'],
            ['wave', $wave?->id, $wave?->quota, $wave?->name, 'wave_id']] as [$axis, $scopeId, $quota, $name, $key]) {
            $limit = $quota === null ? 0 : (int) $quota;

            if ($scopeId === null || $limit <= 0) {
                continue;
            }

            $taken = PpdbRegistration::query()
                ->where($axis.'_id', $scopeId)
                ->where('period_id', $period->id)
                ->where('status', '!=', PpdbRegistration::STATUS_CANCELLED)
                ->when($ignore !== null, fn (Builder $q) => $q->whereKeyNot($ignore))
                ->count();

            if ($taken >= $limit) {
                $errors[$key][] = sprintf(
                    'Kuota %s "%s" sudah penuh (%d/%d pendaftar). Pilih %s lain yang masih ada kursi.',
                    $axis === 'program' ? 'program' : 'gelombang',
                    (string) $name,
                    $taken,
                    $limit,
                    $axis === 'program' ? 'program' : 'gelombang',
                );
            }
        }
    }

    /**
     * Petakan penghambat kelengkapan menjadi pesan per kunci formulir, sehingga
     * UI dapat menandai field/pertanyaan/dokumen yang kurang.
     *
     * @return array<string, list<string>>
     */
    private function blockerMessages(string $axis, mixed $items): array
    {
        $items = (array) $items;
        $errors = [];

        foreach ($items as $item) {
            if ($axis === 'applicant') {
                $errors['applicant'][] = 'Lengkapi data calon: '.$item;

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            if ($axis === 'documents') {
                $errors['documents'][] = sprintf('Dokumen wajib "%s" belum diunggah.', (string) ($item['name'] ?? $item['id'] ?? ''));

                continue;
            }

            $label = (string) ($item['label'] ?? $item['key'] ?? '');
            $key = ($axis === 'fields' ? 'fields.' : 'questions.').(string) ($item['key'] ?? '');

            $errors[$key][] = sprintf(
                '%s "%s" wajib diisi sebelum pendaftaran dikirim.',
                $axis === 'fields' ? 'Isian' : 'Pertanyaan',
                $label,
            );
        }

        return $errors;
    }

    /** Pesan 422 ketika status sekarang tidak mengizinkan pengiriman. */
    private function submitBlockedMessage(PpdbRegistration $registration): string
    {
        if ($registration->status === PpdbRegistration::STATUS_SUBMITTED) {
            return 'Pendaftaran ini sudah dikirim. Tidak perlu dikirim ulang.';
        }

        return sprintf(
            'Pendaftaran berstatus "%s" tidak dapat dikirim. Status yang boleh mengirim: %s.',
            PpdbRegistrationQueryService::statusLabel((string) $registration->status),
            implode(', ', array_map(
                [PpdbRegistrationQueryService::class, 'statusLabel'],
                [PpdbRegistration::STATUS_DRAFT, PpdbRegistration::STATUS_NEED_REVISION],
            )),
        );
    }

    /**
     * Validasi transisi sebelum memanggil mesin status, agar panitia melihat
     * jalur yang benar (mis. submitted -> under_review -> verified).
     */
    private function assertTransitionAllowed(PpdbRegistration $registration, string $to): void
    {
        if (in_array($to, $registration->allowedTransitions(), true)) {
            return;
        }

        $path = PpdbRegistrationQueryService::pathTo((string) $registration->status, $to);

        throw ValidationException::withMessages([
            'status' => $path === []
                ? sprintf(
                    'Status "%s" tidak dapat diubah langsung menjadi "%s" dan tidak ada jalurnya.',
                    PpdbRegistrationQueryService::statusLabel((string) $registration->status),
                    PpdbRegistrationQueryService::statusLabel($to),
                )
                : 'Perubahan status harus bertahap: '.implode(' -> ', array_map(
                    [PpdbRegistrationQueryService::class, 'statusLabel'],
                    $path,
                )).'.',
        ]);
    }

    /** Satu jalur transisi umum (verify/cancel) + respons detail admin. */
    private function moveTo(Request $request, PpdbRegistration $registration, string $to, string $message): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $this->assertTransitionAllowed($registration, $to);

        $registration = $this->machine->transition(
            $registration,
            $to,
            isset($data['note']) ? (string) $data['note'] : null,
            $request->user(),
            (bool) ($data['notify'] ?? true),
        );

        return $this->successResponse(
            new PpdbRegistrationDetailResource($registration, $this->queries->detail($registration, true)),
            $message,
        );
    }

    /**
     * Catatan revisi yang dilihat orang tua: ringkasan panitia + butir yang
     * ditandai (field / pertanyaan / dokumen) beserta alasannya.
     *
     * @param  list<array<string, mixed>>  $targets
     */
    private function renderRevisionNote(string $note, array $targets): string
    {
        if ($targets === []) {
            return $note;
        }

        $labels = [
            'field' => 'Isian formulir',
            'question' => 'Pertanyaan',
            'document' => 'Dokumen',
        ];

        $lines = [];

        foreach ($targets as $target) {
            $type = (string) ($target['type'] ?? '');
            $identifier = (string) ($target['key'] ?? $target['id'] ?? '');
            $reason = trim((string) ($target['reason'] ?? ''));

            $lines[] = '- '.($labels[$type] ?? $type).' '.$identifier
                .($reason === '' ? '' : ': '.$reason);
        }

        return $note."\n\nButir yang harus diperbaiki:\n".implode("\n", $lines);
    }

    /** Biodata calon tidak boleh diedit lewat draf ini bila pendaftaran lain sudah terkirim. */
    private function assertApplicantNotLockedElsewhere(PpdbRegistration $registration): void
    {
        $locked = PpdbRegistration::query()
            ->where('applicant_id', $registration->applicant_id)
            ->whereKeyNot($registration->id)
            ->whereNotIn('status', [...PpdbRegistration::EDITABLE_STATUSES, PpdbRegistration::STATUS_CANCELLED])
            ->exists();

        if ($locked) {
            throw ValidationException::withMessages([
                'applicant' => 'Biodata calon ini tidak dapat diubah dari pendaftaran ini karena sudah dipakai '
                    .'pendaftaran lain yang sedang diproses panitia.',
            ]);
        }
    }

    private function note(Request $request, string $default): ?string
    {
        $note = trim((string) $request->input('note', ''));

        return $note === '' ? $default : $note;
    }

    /** @param  Builder<PpdbRegistration>  $query */
    private function paginate(Request $request, Builder $query): LengthAwarePaginator|Collection
    {
        if ($request->boolean('all')) {
            return $query->get();
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $query->paginate($perPage)->appends($request->query());
    }

    /**
     * Kunci baca: data harus milik akun pemanggil. Akun nonaktif/tidak diverifikasi
     * tetap boleh melihat datanya sendiri (read-only); penulisan menuntut
     * `PpdbPortalAccess::registration()` yang memeriksa status akun.
     */
    private function readable(Request $request, PpdbRegistration $registration): void
    {
        $account = $this->reader($request);

        if (! $this->access->owns($account, $registration)) {
            abort(403, 'Pendaftaran tersebut bukan milik Anda.');
        }

        $registration->loadMissing($this->queries->relations());
    }

    private function reader(Request $request): PpdbParentAccount
    {
        $account = $this->access->account($this->user($request));

        if ($account === null) {
            abort(403, 'Akun PPDB Anda tidak ditemukan. Silakan daftar terlebih dahulu.');
        }

        return $account;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Anda harus masuk sebagai orang tua/wali untuk mengakses portal ini.');
        }

        return $user;
    }
}
