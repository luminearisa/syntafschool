<?php

namespace App\Services\Ppdb;

use App\Http\Resources\PpdbApplicantResource;
use App\Http\Resources\PpdbParentAccountResource;
use App\Http\Resources\PpdbPublicPeriodResource;
use App\Http\Resources\PpdbRegistrationHistoryResource;
use App\Http\Resources\PpdbRegistrationResource;
use App\Models\PpdbApplicant;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPayment;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationHistory;
use App\Models\PpdbSelectionParticipant;
use App\Models\PpdbSelectionStage;
use App\Models\PpdbWave;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pembaca & aggregator data pendaftaran: label status, langkah progres, aksi
 * berikutnya, query daftar (portal & admin), susunan detail, dashboard portal,
 * dan kuota periode.
 *
 * Kelas ini hanya MEMBACA — perubahan status selalu lewat
 * `PpdbRegistrationStateMachine`.
 */
class PpdbRegistrationQueryService
{
    public const STATUS_LABELS = [
        'draft' => 'Draft (belum dikirim)',
        'submitted' => 'Terkirim',
        'under_review' => 'Sedang diteliti',
        'need_revision' => 'Perlu revisi',
        'verified' => 'Terverifikasi',
        'selection' => 'Ikut seleksi',
        'passed' => 'Diterima',
        'failed' => 'Belum diterima',
        'waitlisted' => 'Daftar tunggu',
        're_registration' => 'Daftar ulang',
        'completed' => 'Selesai (resmi siswa)',
        'cancelled' => 'Dibatalkan',
    ];

    public const PAYMENT_LABELS = [
        'unpaid' => 'Belum dibayar',
        'pending' => 'Menunggu verifikasi pembayaran',
        'paid' => 'Lunas',
        'failed' => 'Pembayaran gagal',
        'expired' => 'Kedaluwarsa',
        'cancelled' => 'Dibatalkan',
        'refunded' => 'Dana dikembalikan',
    ];

    /** Nama mudah dibaca untuk tipe tahap seleksi (kolom `type` pada model). */
    public const STAGE_TYPE_LABELS = [
        'administrative' => 'Seleksi administrasi',
        'academic_test' => 'Tes akademik',
        'interview' => 'Wawancara',
        'tahfidz' => 'Tes tahfidz',
        'psychological_test' => 'Psikotes',
        'health_check' => 'Cek kesehatan',
        'other' => 'Tahap lainnya',
    ];

    public const DOCUMENT_LABELS = [
        'not_uploaded' => 'Belum ada dokumen',
        'under_review' => 'Dokumen sedang ditinjau',
        'approved' => 'Dokumen disetujui',
        'rejected' => 'Ada dokumen ditolak',
    ];

    public const DOCUMENT_ITEM_LABELS = [
        'uploaded' => 'Terunggah, menunggu tinjauan',
        'under_review' => 'Sedang ditinjau',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'not_uploaded' => 'Belum diunggah',
    ];

    /** Aksi yang diminta dari calon pada tiap status. */
    public const NEXT_ACTIONS = [
        'draft' => ['key' => 'lengkapi_pendaftaran', 'label' => 'Lengkapi data & dokumen, lalu kirim pendaftaran'],
        'submitted' => ['key' => 'tunggu_verifikasi', 'label' => 'Pendaftaran menunggu verifikasi panitia'],
        'under_review' => ['key' => 'tunggu_verifikasi', 'label' => 'Berkas Anda sedang diteliti panitia'],
        'need_revision' => ['key' => 'perbaiki_berkas', 'label' => 'Perbaiki sesuai catatan panitia lalu kirim ulang'],
        'verified' => ['key' => 'tunggu_seleksi', 'label' => 'Menunggu jadwal seleksi'],
        'selection' => ['key' => 'ikuti_seleksi', 'label' => 'Ikuti tahap seleksi sesuai jadwal'],
        'passed' => ['key' => 'daftar_ulang', 'label' => 'Anda diterima — lakukan daftar ulang'],
        'waitlisted' => ['key' => 'pantau_pengumuman', 'label' => 'Anda masuk daftar tunggu, pantau pengumuman'],
        're_registration' => ['key' => 'selesaikan_daftar_ulang', 'label' => 'Selesaikan daftar ulang sebelum batas waktu'],
        'completed' => ['key' => 'selesai', 'label' => 'Proses PPDB selesai'],
        'failed' => ['key' => 'selesai', 'label' => 'Pendaftaran berakhir, coba lagi pada periode berikutnya'],
        'cancelled' => ['key' => 'selesai', 'label' => 'Pendaftaran dibatalkan'],
    ];

    /** Field identitas calon yang wajib ada sebelum formulir dianggap lengkap. */
    public const APPLICANT_REQUIRED = [
        'full_name' => 'Nama lengkap calon',
        'birth_date' => 'Tanggal lahir calon',
        'gender_id' => 'Jenis kelamin calon',
    ];

    public function __construct(
        private readonly PpdbScopeService $scope,
        private readonly PpdbAnswerService $answers,
        private readonly PpdbDocumentService $documents,
    ) {
    }

    // =====================================================================
    // Label & aksi (statis: dipakai resource & service lain)
    // =====================================================================

    public static function statusLabel(?string $status): string
    {
        $status = (string) $status;

        return self::STATUS_LABELS[$status] ?? Str::of($status)->replace('_', ' ')->title()->toString();
    }

    public static function paymentLabel(?string $status): string
    {
        $status = (string) $status;

        return self::PAYMENT_LABELS[$status] ?? Str::of($status)->replace('_', ' ')->title()->toString();
    }

    public static function documentLabel(?string $status): string
    {
        $status = (string) $status;

        return self::DOCUMENT_LABELS[$status] ?? Str::of($status)->replace('_', ' ')->title()->toString();
    }

    public static function documentItemLabel(?string $status): string
    {
        $status = (string) $status;

        return self::DOCUMENT_ITEM_LABELS[$status === '' ? 'not_uploaded' : $status]
            ?? Str::of($status)->replace('_', ' ')->title()->toString();
    }

    /**
     * @return array{key: string, label: string, status: string|null, status_label: string}
     */
    public static function nextAction(?string $status): array
    {
        $action = self::NEXT_ACTIONS[(string) $status] ?? ['key' => 'tunggu', 'label' => 'Menunggu proses panitia'];

        return $action + ['status' => $status, 'status_label' => self::statusLabel($status)];
    }

    /**
     * @param  list<string>  $statuses
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $statuses): array
    {
        return array_map(
            static fn (string $status): array => ['value' => $status, 'label' => self::statusLabel($status)],
            $statuses,
        );
    }

    /**
     * Alur status terpendek dari status sekarang ke target — dipakai menjelaskan
     * error 422 saat transisi langsung tidak diizinkan.
     *
     * @return list<string>
     */
    public static function pathTo(string $from, string $to): array
    {
        if ($from === $to) {
            return [$from];
        }

        $queue = [[$from, [$from]]];
        $seen = [$from => true];

        while ($queue !== []) {
            [$current, $trail] = array_shift($queue);

            foreach (PpdbRegistration::TRANSITIONS[$current] ?? [] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }

                if ($next === $to) {
                    return [...$trail, $next];
                }

                $seen[$next] = true;
                $queue[] = [$next, [...$trail, $next]];
            }
        }

        return [];
    }

    // =====================================================================
    // Query daftar
    // =====================================================================

    /** Pendaftaran milik satu akun orang tua (portal). */
    public function portalQuery(PpdbParentAccount $account, Request $request): Builder
    {
        return PpdbRegistration::query()
            ->with($this->relations())
            ->where('parent_account_id', $account->id)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('payment_status'), fn (Builder $q) => $q->where('payment_status', (string) $request->input('payment_status')))
            ->when($request->filled('document_status'), fn (Builder $q) => $q->where('document_status', (string) $request->input('document_status')))
            ->when($request->filled('period_id'), fn (Builder $q) => $q->where('period_id', (int) $request->input('period_id')))
            ->when($request->filled('applicant_id'), fn (Builder $q) => $q->where('applicant_id', (int) $request->input('applicant_id')))
            ->when($request->filled('search'), fn (Builder $q) => $this->applySearch($q, (string) $request->input('search')))
            ->latest('id');
    }

    /** Pendaftaran untuk panitia (admin) dengan filter lengkap. */
    public function adminQuery(Request $request): Builder
    {
        return PpdbRegistration::query()
            ->with($this->relations())
            ->when($request->filled('period_id'), fn (Builder $q) => $q->where('period_id', (int) $request->input('period_id')))
            ->when($request->filled('wave_id'), fn (Builder $q) => $q->where('wave_id', (int) $request->input('wave_id')))
            ->when($request->filled('program_id'), fn (Builder $q) => $q->where('program_id', (int) $request->input('program_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('payment_status'), fn (Builder $q) => $q->where('payment_status', (string) $request->input('payment_status')))
            ->when($request->filled('document_status'), fn (Builder $q) => $q->where('document_status', (string) $request->input('document_status')))
            ->when($request->filled('applicant_id'), fn (Builder $q) => $q->where('applicant_id', (int) $request->input('applicant_id')))
            ->when($request->filled('parent_account_id'), fn (Builder $q) => $q->where('parent_account_id', (int) $request->input('parent_account_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', (string) $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', (string) $request->input('date_to')))
            ->when($request->filled('submitted_from'), fn (Builder $q) => $q->whereDate('submitted_at', '>=', (string) $request->input('submitted_from')))
            ->when($request->filled('submitted_to'), fn (Builder $q) => $q->whereDate('submitted_at', '<=', (string) $request->input('submitted_to')))
            ->when($request->filled('search'), fn (Builder $q) => $this->applySearch($q, (string) $request->input('search')))
            ->latest('id');
    }

    /**
     * Pencarian pada nomor pendaftaran, nama/NIK/NISN calon, dan identitas
     * orang tua.
     *
     * @param  Builder<PpdbRegistration>  $query
     */
    public function applySearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $scope) use ($like): void {
            $scope->where('registration_number', 'like', $like)
                ->orWhereHas('applicant', fn (Builder $a) => $a
                    ->where('full_name', 'like', $like)
                    ->orWhere('nik', 'like', $like)
                    ->orWhere('nisn', 'like', $like))
                ->orWhereHas('parentAccount.user', fn (Builder $u) => $u
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like));
        });
    }

    /** @return list<string> */
    public function relations(): array
    {
        return ['applicant', 'program', 'wave', 'period', 'parentAccount.user', 'parentAccount.guardian'];
    }

    // =====================================================================
    // Kelengkapan & detail
    // =====================================================================

    /**
     * Hal yang masih menghambat pengiriman pendaftaran.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array{fields: list<array<string, mixed>>, questions: list<array<string, mixed>>, documents: list<array<string, mixed>>, applicant: list<string>, total: int, ready: bool}
     */
    public function blockers(PpdbRegistration $registration, ?array $schema = null): array
    {
        $schema ??= $this->scope->schemaForRegistration($registration);
        $answers = $this->answers->keyed($registration, $schema);

        $missingFields = $this->answers->missingRequiredFields($schema, $answers['fields']);
        $missingQuestions = $this->answers->missingRequiredQuestions($schema, $answers['questions']);
        $missingApplicant = $this->missingApplicantData($registration);

        $requiredDocuments = $this->documents->requirementsFor($registration)
            ->where('required', true)
            ->all();

        $uploaded = PpdbApplicantDocument::query()
            ->where('registration_id', $registration->id)
            ->active()
            ->pluck('requirement_id')
            ->map(static fn (?int $id): int => (int) $id)
            ->all();

        $missingDocuments = array_values(array_filter(
            $requiredDocuments,
            static fn (array $requirement): bool => ! in_array((int) $requirement['id'], $uploaded, true),
        ));

        $total = count($missingFields) + count($missingQuestions) + count($missingDocuments) + count($missingApplicant);

        return [
            'fields' => $missingFields,
            'questions' => $missingQuestions,
            'documents' => array_map(
                static fn (array $requirement): array => ['id' => $requirement['id'], 'name' => $requirement['name']],
                $missingDocuments,
            ),
            'applicant' => $missingApplicant,
            'total' => $total,
            'ready' => $total === 0,
        ];
    }

    /**
     * @return list<string>
     */
    public function missingApplicantData(PpdbRegistration $registration): array
    {
        $applicant = $registration->applicant ?? PpdbApplicant::find($registration->applicant_id);
        $missing = [];

        foreach (self::APPLICANT_REQUIRED as $column => $label) {
            if (empty($applicant?->{$column})) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Langkah progres formulir (stepper UI): data calon → formulir → pertanyaan
     * → dokumen → review → bayar → kirim.
     *
     * @param  array<string, mixed>  $schema
     * @param  array{fields: array<string, mixed>, questions: array<string, mixed>}  $answers
     * @param  array<string, mixed>  $documents
     * @return list<array<string, mixed>>
     */
    public function progress(PpdbRegistration $registration, array $schema, array $answers, array $documents): array
    {
        $requiredFields = array_values(array_filter(
            $schema['fields'],
            static fn (array $field): bool => (bool) $field['required'] && $field['type'] !== 'file',
        ));
        $answeredFields = array_values(array_filter(
            $requiredFields,
            fn (array $field): bool => ! $this->isBlank($answers['fields'][$field['key']] ?? null),
        ));

        $requiredQuestions = array_values(array_filter(
            $schema['questions'],
            static fn (array $question): bool => (bool) $question['required'],
        ));
        $answeredQuestions = array_values(array_filter(
            $requiredQuestions,
            fn (array $question): bool => ! $this->isBlank($answers['questions'][(string) $question['id']] ?? null),
        ));

        $summary = $documents['summary'];
        $missingApplicant = $this->missingApplicantData($registration);
        $feesDue = (float) ($schema['fee_total'] ?? 0) > 0;
        $paid = in_array((string) $registration->payment_status, [
            PpdbRegistration::PAYMENT_PAID,
            PpdbRegistration::PAYMENT_REFUNDED,
        ], true);

        $steps = [
            $this->step('data_calon', 'Data Calon', $missingApplicant === [], [
                'missing' => $missingApplicant,
            ]),
            $this->step(
                'formulir',
                'Formulir Pendaftaran',
                count($answeredFields) === count($requiredFields),
                [
                    'answered' => count($answeredFields),
                    'required_total' => count($requiredFields),
                    'total' => count($schema['fields']),
                ],
            ),
            $this->step('pertanyaan', 'Pertanyaan Tambahan', count($answeredQuestions) === count($requiredQuestions), [
                'answered' => count($answeredQuestions),
                'required_total' => count($requiredQuestions),
                'total' => count($schema['questions']),
            ]),
            $this->step('dokumen', 'Unggah Dokumen', (bool) $summary['complete'], [
                'uploaded' => (int) $summary['uploaded'],
                'required_total' => (int) $summary['required_total'],
                'total' => (int) $summary['total'],
                'missing' => $summary['missing'],
            ]),
        ];

        $readyToReview = ! in_array(false, array_map(static fn (array $step): bool => $step['done'], $steps), true);

        $steps[] = $this->step('review', 'Periksa & Konfirmasi', $readyToReview, [
            'hint' => $readyToReview
                ? 'Semua data sudah lengkap, silakan kirim pendaftaran.'
                : 'Lengkapi seluruh langkah sebelumnya sebelum mengirim.',
        ]);

        $steps[] = $this->step('bayar', 'Pembayaran', ! $feesDue || $paid, [
            'applicable' => $feesDue,
            'payment_status' => $registration->payment_status,
            'payment_status_label' => self::paymentLabel((string) $registration->payment_status),
            'amount_due' => $feesDue ? (float) $schema['fee_total'] : 0.0,
            'hint' => $feesDue
                ? 'Tagihan dibuat oleh panitia setelah pendaftaran dikirim; cek menu Pembayaran.'
                : 'Tidak ada biaya pada pilihan program & gelombang ini.',
        ]);

        $steps[] = $this->step('kirim', 'Kirim Pendaftaran', ! in_array(
            (string) $registration->status,
            [PpdbRegistration::STATUS_DRAFT, PpdbRegistration::STATUS_NEED_REVISION],
            true,
        ), [
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
        ]);

        return $steps;
    }

    /**
     * Susunan lengkap satu pendaftaran untuk portal & admin.
     *
     * @return array<string, mixed>
     */
    public function detail(PpdbRegistration $registration, bool $admin = false): array
    {
        $registration->loadMissing([
            'applicant.gender', 'applicant.religion', 'applicant.targetProgram',
            'parentAccount.user', 'parentAccount.guardian', 'period', 'wave', 'program',
        ]);

        $schema = $this->scope->schemaForRegistration($registration);
        $answers = $this->answers->keyed($registration, $schema, $admin);
        $documents = $this->documents->annotated($registration);
        $blockers = $this->blockers($registration, $schema);

        return array_filter([
            'schema' => $schema,
            'answers' => $answers,
            'documents' => $documents,
            'progress' => $this->progress($registration, $schema, $answers, $documents),
            'blockers' => $blockers,
            'can_submit' => $registration->isEditable() && $blockers['total'] === 0,
            'next_action' => self::nextAction((string) $registration->status),
            'allowed_transitions' => self::options($registration->allowedTransitions()),
            'editable' => $registration->isEditable(),
            'document_upload_allowed' => $this->documents->canUpload($registration),
            'validation_rules' => $this->scope->validationRules(
                (int) $registration->period_id,
                $registration->program_id === null ? null : (int) $registration->program_id,
                $registration->wave_id === null ? null : (int) $registration->wave_id,
            ),
            'applicant' => $this->applicantPayload($registration->applicant),
            'parent' => $this->parentPayload($registration->parentAccount),
            'quota_override' => $admin ? $this->quotaOverride($registration) : null,
            'histories' => $admin ? $this->histories($registration) : null,
            'invoices' => $admin ? $this->paymentSummary($registration) : null,
            'selection_participants' => $admin ? $this->selectionSummary($registration) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Penetapan lulus di atas kuota hanya sah dengan hak khusus + alasan tertulis,
     * jadi panitia harus bisa membacanya kembali. Sengaja tidak dikirim ke portal:
     * pemilik pendaftaran melihat hasilnya, bukan pertimbangan internal pimpinan.
     *
     * @return array<string, mixed>
     */
    private function quotaOverride(PpdbRegistration $registration): array
    {
        $reason = $registration->quota_override_reason;

        return [
            'applied' => filled($reason),
            'reason' => $reason,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function histories(PpdbRegistration $registration): array
    {
        return PpdbRegistrationHistory::query()
            ->with('changedBy')
            ->where('registration_id', $registration->id)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (PpdbRegistrationHistory $history): array => PpdbRegistrationHistoryResource::row($history))
            ->all();
    }

    /**
     * Ringkasan tagihan & pembayaran, dibaca langsung dari model invoice/payment
     * (modul pembayaran tetap pemilik datanya).
     *
     * @return array<string, mixed>
     */
    public function paymentSummary(PpdbRegistration $registration): array
    {
        $invoices = PpdbInvoice::query()
            ->where('registration_id', $registration->id)
            ->with(['items', 'payments'])
            ->orderByDesc('id')
            ->get();

        return [
            'invoice_count' => $invoices->count(),
            'total_amount' => round((float) $invoices->sum('total_amount'), 2),
            'paid_amount' => round((float) $invoices->sum('paid_amount'), 2),
            'outstanding_amount' => round((float) $invoices->sum(
                static fn (PpdbInvoice $invoice): float => $invoice->outstandingAmount()
            ), 2),
            'last_due_date' => $invoices->max('due_date')?->toDateString(),
            'payment_status' => $registration->payment_status,
            'payment_status_label' => self::paymentLabel((string) $registration->payment_status),
            'invoices' => $invoices->map(static fn (PpdbInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'amount' => (float) $invoice->amount,
                'discount_amount' => (float) $invoice->discount_amount,
                'total_amount' => (float) $invoice->total_amount,
                'paid_amount' => (float) $invoice->paid_amount,
                'outstanding_amount' => $invoice->outstandingAmount(),
                'due_date' => $invoice->due_date?->toDateString(),
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'notes' => $invoice->notes,
                'items' => $invoice->items->map(static fn (mixed $item): array => [
                    'name' => $item->name,
                    'amount' => (float) $item->amount,
                    'fee_id' => $item->fee_id,
                ])->all(),
                'payments' => $invoice->payments->map(static fn (PpdbPayment $payment): array => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'amount' => (float) $payment->amount,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'proof_path' => $payment->proof_path,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'verified_at' => $payment->verified_at?->toIso8601String(),
                    'note' => $payment->note,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function selectionSummary(PpdbRegistration $registration): array
    {
        $participants = PpdbSelectionParticipant::query()
            ->with('stage')
            ->where('registration_id', $registration->id)
            ->get();

        return [
            'result' => $registration->selection_result,
            'announced_at' => $registration->announced_at?->toIso8601String(),
            'stage_count' => $participants->count(),
            'total_score' => round((float) $participants->sum('score'), 2),
            'stages' => $participants->map(static fn (PpdbSelectionParticipant $participant): array => [
                'id' => $participant->id,
                'stage_id' => $participant->stage_id,
                'stage_name' => $participant->stage?->name,
                'stage_type' => $participant->stage?->type,
                'scheduled_at' => $participant->stage?->scheduled_at?->toIso8601String(),
                'location' => $participant->stage?->location,
                'status' => $participant->status,
                'score' => $participant->score === null ? null : (float) $participant->score,
                'passing_score' => $participant->stage?->passing_score === null ? null : (float) $participant->stage->passing_score,
                'result' => $participant->result,
                'attended_at' => $participant->attended_at?->toIso8601String(),
            ])->all(),
        ];
    }

    // =====================================================================
    // Dashboard portal
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    public function dashboard(PpdbParentAccount $account, int $unreadNotifications): array
    {
        $registrations = PpdbRegistration::query()
            ->with($this->relations())
            ->where('parent_account_id', $account->id)
            ->latest('id')
            ->get();

        $period = $this->dashboardPeriod($registrations);

        return [
            'parent' => $this->parentPayload($account),
            'applicant_count' => (int) $account->applicants()->count(),
            'registration_count' => $registrations->count(),
            'applications' => $registrations
                ->map(fn (PpdbRegistration $registration): array => $this->applicationRow($registration))
                ->all(),
            'counts' => $this->counts($registrations),
            'period' => $period === null ? null : $this->periodState($period),
            'deadlines' => $period === null ? [] : $this->deadlines($period),
            'unread_notifications' => $unreadNotifications,
            'empty_state' => $registrations->isEmpty() ? [
                'title' => 'Belum ada pendaftaran',
                'description' => 'Tambahkan data anak (calon peserta didik) terlebih dahulu, lalu buat pendaftaran pada periode yang sedang dibuka.',
            ] : null,
        ];
    }

    /**
     * Baris pendaftaran pada dashboard portal.
     *
     * @return array<string, mixed>
     */
    public function applicationRow(PpdbRegistration $registration): array
    {
        return PpdbRegistrationResource::row($registration);
    }

    /**
     * Jumlah pendaftaran per status, dihitung atas baris yang diberikan.
     *
     * Pada daftar berpaginasi pemanggilnya mengirim baris halaman ini saja, jadi
     * angka `total` di sini ikut diganti kunci paginasi sejati oleh
     * `PpdbRegistrationController::pagination()`.
     *
     * @param  Collection<int, PpdbRegistration>  $registrations
     * @return array<string, mixed>
     */
    public function counts(Collection $registrations): array
    {
        $byStatus = array_fill_keys(PpdbRegistration::STATUSES, 0);
        $byPayment = array_fill_keys(PpdbRegistration::PAYMENT_STATUSES, 0);
        $byDocument = array_fill_keys(PpdbRegistration::DOCUMENT_STATUSES, 0);
        $needsAction = 0;

        foreach ($registrations as $registration) {
            $byStatus[$registration->status] = ($byStatus[$registration->status] ?? 0) + 1;
            $byPayment[$registration->payment_status] = ($byPayment[$registration->payment_status] ?? 0) + 1;
            $byDocument[$registration->document_status] = ($byDocument[$registration->document_status] ?? 0) + 1;

            if ($registration->isEditable() || $registration->status === PpdbRegistration::STATUS_PASSED) {
                $needsAction++;
            }
        }

        return [
            'total' => $registrations->count(),
            'needs_action' => $needsAction,
            'by_status' => $byStatus,
            'by_payment_status' => $byPayment,
            'by_document_status' => $byDocument,
            'summary' => array_map(
                static fn (array $option): array => [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'count' => $byStatus[$option['value']] ?? 0,
                ],
                self::options(PpdbRegistration::STATUSES),
            ),
        ];
    }

    /**
     * Tenggat mendatang dari sebuah periode (pendaftaran, pengumuman, daftar
     * ulang, dan batas tiap gelombang aktif).
     *
     * @return list<array<string, mixed>>
     */
    public function deadlines(PpdbPeriod $period): array
    {
        $sources = [
            ['key' => 'registration_end', 'label' => 'Batas akhir pendaftaran', 'date' => $period->registration_end, 'urgent' => 7],
            ['key' => 'selection_announcement_date', 'label' => 'Pengumuman hasil seleksi', 'date' => $period->selection_announcement_date, 'urgent' => 3],
            ['key' => 're_registration_start', 'label' => 'Awal daftar ulang', 'date' => $period->re_registration_start, 'urgent' => 3],
            ['key' => 're_registration_end', 'label' => 'Batas akhir daftar ulang', 'date' => $period->re_registration_end, 'urgent' => 3],
        ];

        foreach ($period->waves()->active()->get() as $wave) {
            if ($wave->registration_end !== null) {
                $sources[] = [
                    'key' => 'wave_registration_end',
                    'label' => 'Batas akhir gelombang '.$wave->name,
                    'date' => $wave->registration_end,
                    'urgent' => 7,
                    'wave_id' => $wave->id,
                ];
            }
        }

        $rows = [];

        foreach ($sources as $source) {
            $date = $source['date'];

            if ($date === null) {
                continue;
            }

            $days = (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

            $rows[] = [
                'key' => $source['key'],
                'label' => $source['label'],
                'date' => $date->toDateString(),
                'days_left' => $days,
                'state' => $days < 0 ? 'passed' : ($days <= $source['urgent'] ? 'soon' : 'upcoming'),
                'wave_id' => $source['wave_id'] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return array_values(array_filter($rows, static fn (array $row): bool => $row['days_left'] >= 0));
    }

    /**
     * Kuota satu periode: daya tampung program/gelombang vs pendaftar aktif.
     *
     * @return array<string, mixed>
     */
    public function quotaSummary(PpdbPeriod $period): array
    {
        return $this->quotaSummaries([$period])[(int) $period->id];
    }

    /**
     * Kuota banyak periode sekaligus (dipakai daftar publik agar tidak N+1).
     *
     * @param  array<int, PpdbPeriod>  $periods
     * @return array<int, array<string, mixed>>
     */
    public function quotaSummaries(array $periods): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (PpdbPeriod $period): ?int => (int) $period->id,
            $periods,
        )));

        if ($ids === []) {
            return [];
        }

        $programs = PpdbProgram::query()->with('educationLevel')->whereIn('period_id', $ids)->active()->orderBy('sort_order')->get()->groupBy('period_id');
        $waves = PpdbWave::query()->whereIn('period_id', $ids)->active()->orderBy('priority')->get()->groupBy('period_id');
        $counts = $this->registrationCounts($ids);

        $summaries = [];

        foreach ($periods as $period) {
            $id = (int) $period->id;
            $periodCounts = $counts[$id] ?? ['total' => 0, 'by_program' => [], 'by_wave' => []];

            $programQuota = 0;
            $openPrograms = 0;

            $programRows = ($programs[$id] ?? collect())->map(static function (PpdbProgram $program) use ($periodCounts, &$programQuota, &$openPrograms): array {
                $registered = (int) ($periodCounts['by_program'][(int) $program->id] ?? 0);
                $quota = $program->quota === null ? null : (int) $program->quota;
                $remaining = $quota === null ? null : max(0, $quota - $registered);

                if ($quota !== null) {
                    $programQuota += $quota;
                }

                if ($remaining === null || $remaining > 0) {
                    $openPrograms++;
                }

                return [
                    'id' => $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                    'description' => $program->description,
                    'education_level_id' => $program->education_level_id,
                    // Halaman publik perlu menyebut jenjang (PAUD/SD/SMP/SMA) dan
                    // satu-satunya sumber namanya adalah master `education_levels`,
                    // yang tidak boleh dibaca publik lewat /master/*.
                    'education_level' => $program->educationLevel === null ? null : [
                        'id' => $program->educationLevel->id,
                        'name' => $program->educationLevel->name,
                        'jenjang' => $program->educationLevel->jenjang,
                    ],
                    'quota' => $quota,
                    'registered' => $registered,
                    'remaining' => $remaining,
                    'is_full' => $remaining === 0,
                ];
            })->all();

            $waveRows = ($waves[$id] ?? collect())->map(static function (PpdbWave $wave) use ($periodCounts): array {
                $registered = (int) ($periodCounts['by_wave'][(int) $wave->id] ?? 0);
                $quota = $wave->quota === null ? null : (int) $wave->quota;

                return [
                    'id' => $wave->id,
                    'code' => $wave->code,
                    'name' => $wave->name,
                    'priority' => (int) $wave->priority,
                    'quota' => $quota,
                    'registered' => $registered,
                    'remaining' => $quota === null ? null : max(0, $quota - $registered),
                    'registration_start' => $wave->registration_start?->toDateString(),
                    'registration_end' => $wave->registration_end?->toDateString(),
                    'description' => $wave->description,
                    'is_open' => $wave->isOpenOn(),
                ];
            })->all();

            $summaries[$id] = [
                'period_id' => $id,
                'total_quota' => $programQuota > 0 ? $programQuota : null,
                'registered' => (int) $periodCounts['total'],
                'remaining' => $programQuota > 0 ? max(0, $programQuota - (int) $periodCounts['total']) : null,
                'open_program_count' => $openPrograms,
                'programs' => $programRows,
                'waves' => $waveRows,
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    public function periodState(PpdbPeriod $period): array
    {
        $period->loadMissing('academicYear');

        return PpdbPublicPeriodResource::summary($period, $this->quotaSummary($period)) + [
            'offerings' => $this->scope->offerings((int) $period->id),
        ];
    }

    /**
     * Isi halaman publik satu periode: ringkasan + kuota + dokumen + biaya +
     * tahap seleksi. Hanya data yang memang untuk konsumsi publik.
     *
     * @return array<string, mixed>
     */
    public function publicPeriodPayload(PpdbPeriod $period): array
    {
        $quota = $this->quotaSummary($period);

        $globalDocuments = $this->scope->documentRequirements((int) $period->id)->all();
        $globalFees = $this->scope->fees((int) $period->id)->all();

        $programs = array_map(function (array $program) use ($period): array {
            $documents = $this->scope->documentRequirements((int) $period->id, (int) $program['id'])->all();
            $fees = $this->scope->fees((int) $period->id, (int) $program['id'])->all();

            return $program + [
                'documents' => $documents,
                'fees' => $fees,
                'fee_total' => round((float) array_sum(array_column($fees, 'amount')), 2),
            ];
        }, $quota['programs']);

        $stages = $period->selectionStages()
            ->whereIn('status', [
                PpdbSelectionStage::STATUS_SCHEDULED,
                PpdbSelectionStage::STATUS_ONGOING,
                PpdbSelectionStage::STATUS_DONE,
            ])
            ->orderBy('order')
            ->get()
            ->map(static fn (PpdbSelectionStage $stage): array => [
                'id' => $stage->id,
                'name' => $stage->name,
                'type' => $stage->type,
                'type_label' => self::STAGE_TYPE_LABELS[$stage->type] ?? $stage->type,
                'program_id' => $stage->program_id,
                'scheduled_at' => $stage->scheduled_at?->toIso8601String(),
                'location' => $stage->location,
                'status' => $stage->status,
                'notes' => $stage->notes,
            ])
            ->all();

        return [
            'quota' => [
                'total_quota' => $quota['total_quota'],
                'registered' => $quota['registered'],
                'remaining' => $quota['remaining'],
                'open_program_count' => $quota['open_program_count'],
            ],
            'programs' => $programs,
            'waves' => $quota['waves'],
            'documents' => $globalDocuments,
            'fees' => $globalFees,
            'fee_total' => round((float) array_sum(array_column($globalFees, 'amount')), 2),
            'selection_stages' => $stages,
            'faq' => self::faqList($period->faq),
            'deadlines' => $this->deadlines($period),
        ];
    }

    /**
     * FAQ boleh disimpan sebagai larik `{q,a}` atau `{question,answer}`.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faqList(mixed $faq): array
    {
        if (! is_array($faq)) {
            return [];
        }

        $rows = [];

        foreach ($faq as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = (string) ($item['question'] ?? $item['q'] ?? $item['title'] ?? '');
            $answer = (string) ($item['answer'] ?? $item['a'] ?? $item['body'] ?? '');

            if ($question === '') {
                continue;
            }

            $rows[] = ['question' => $question, 'answer' => $answer];
        }

        return $rows;
    }

    /**
     * Identitas orang tua (dibaca dari users + guardians, tidak disalin).
     *
     * @return array<string, mixed>
     */
    public function parentPayload(?PpdbParentAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        $account->loadMissing(['user', 'guardian']);

        return PpdbParentAccountResource::row($account);
    }

    /**
     * Biodata calon lengkap (dipakai detail pendaftaran).
     *
     * @return array<string, mixed>|null
     */
    public function applicantPayload(?PpdbApplicant $applicant): ?array
    {
        return $applicant === null ? null : PpdbApplicantResource::row($applicant);
    }

    /**
     * Periode acuan dashboard: periode pendaftaran terakhir, bila tidak ada
     * pakai periode terakhir yang masih dibuka.
     *
     * @param  Collection<int, PpdbRegistration>  $registrations
     */
    private function dashboardPeriod(Collection $registrations): ?PpdbPeriod
    {
        $periodId = $registrations->sortByDesc('id')->first()?->period_id;

        if ($periodId !== null) {
            $period = PpdbPeriod::find((int) $periodId);

            if ($period !== null) {
                return $period;
            }
        }

        return PpdbPeriod::query()->open()->latest('id')->first()
            ?? PpdbPeriod::query()->publiclyVisible()->latest('id')->first();
    }

    /**
     * Jumlah pendaftar aktif (bukan yang dibatalkan) per periode, program, gelombang.
     *
     * @param  list<int>  $periodIds
     * @return array<int, array{total: int, by_program: array<int, int>, by_wave: array<int, int>}>
     */
    private function registrationCounts(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }

        $rows = PpdbRegistration::query()
            ->selectRaw('period_id, program_id, wave_id, COUNT(*) as aggregate')
            ->whereIn('period_id', $periodIds)
            ->where('status', '!=', PpdbRegistration::STATUS_CANCELLED)
            ->groupBy('period_id', 'program_id', 'wave_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $periodId = (int) $row->period_id;
            $count = (int) $row->aggregate;

            // Ditulis langsung ke `$counts`, bukan ke salinan lokal: `$bucket =
            // $counts[$id] ??= […]` membuat seluruh penambahan hilang sehingga
            // kuota selalu terbaca kosong.
            $counts[$periodId] ??= ['total' => 0, 'by_program' => [], 'by_wave' => []];
            $counts[$periodId]['total'] += $count;

            if ($row->program_id !== null) {
                $programId = (int) $row->program_id;
                $counts[$periodId]['by_program'][$programId] = ($counts[$periodId]['by_program'][$programId] ?? 0) + $count;
            }

            if ($row->wave_id !== null) {
                $waveId = (int) $row->wave_id;
                $counts[$periodId]['by_wave'][$waveId] = ($counts[$periodId]['by_wave'][$waveId] ?? 0) + $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $key, string $label, bool $done, array $extra = []): array
    {
        return ['key' => $key, 'label' => $label, 'done' => $done] + $extra;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
