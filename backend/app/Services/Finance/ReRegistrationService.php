<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\FinanceAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceReRegistration;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daftar Ulang / re-registrasi tahun ajaran (§2–§7 Phase 2).
 *
 * Prinsip yang dijaga:
 *  - TIDAK ada mesin tagihan baru: billing & invoice diproduksi
 *    `FinanceBillingService` Phase 1 (paket atau manual), lalu ditautkan ke baris
 *    `finance_re_registrations`. Jurnal double-entry tetap diposting mesin ledger
 *    lewat billing/invoice — tidak ada "accounting palsu" di sini (§50).
 *  - Idempoten (§24): unique (`student_id`, `next_academic_year_id`). Generate
 *    berulang untuk santri+tahun yang sama tidak pernah menduplikasi; baris
 *    `eligible`/`cancelled`/`expired` dihidupkan kembali, yang sudah
 *    `billed`..`completed` dilewati.
 *  - Status workflow (§6) ELIGIBLE→BILLED→UNPAID→PARTIAL→PAID→COMPLETED.
 *    `paid` diturunkan dari invoice (bukan dipercaya mentah); `completed` adalah
 *    aksi eksplisit yang sekaligus mengaktifkan tahun ajaran berikutnya (§7).
 *  - Aturan tunggakan TIDAK di-hardcode (§4): `allow_re_registration_with_outstanding`
 *    dibaca dari FinanceSettingsService.
 */
class ReRegistrationService
{
    /** Status riwayat akademik default saat aktivasi tahun berikutnya (§7). */
    public const DEFAULT_HISTORY_STATUS = 'naik';

    /** Status riwayat yang menutup hak daftar ulang (§4: academic status valid). */
    private const CLOSED_HISTORY_STATUSES = ['lulus', 'pindah', 'keluar'];

    public function __construct(
        private readonly FinanceBillingService $billing,
        private readonly FinanceSettingsService $settings,
        private readonly FinanceNumberingService $numbering,
        private readonly AccountingPolicyService $policy,
        private readonly FinanceNotificationService $notifications,
    ) {}

    // ==================== Konfigurasi & tahun ajaran (§3) ====================

    /**
     * Selesaikan tahun ajaran berjalan & tujuan dari config → settings → auto.
     * Auto: berjalan = yang `is_active`; tujuan = tahun berikutnya berdasar
     * `start_date` (fallback `name`). Tidak di-hardcode (§3, §4).
     *
     * @param  array<string, mixed>  $config
     * @return array{current: AcademicYear|null, next: AcademicYear}
     */
    public function resolveAcademicYears(array $config = []): array
    {
        $current = $this->resolveYear(
            $config['current_academic_year_id'] ?? null,
            (int) $this->settings->get('re_registration_current_academic_year_id', 0),
            fn (): ?AcademicYear => AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->first(),
        );

        $next = $this->resolveYear(
            $config['next_academic_year_id'] ?? null,
            (int) $this->settings->get('re_registration_next_academic_year_id', 0),
            fn (): ?AcademicYear => $this->guessNextYear($current),
        );

        if ($next === null) {
            throw ValidationException::withMessages([
                'next_academic_year_id' => 'Tahun ajaran tujuan daftar ulang tidak tersedia. Buat lebih dulu atau tentukan eksplisit.',
            ]);
        }

        if ($current !== null && (int) $current->id === (int) $next->id) {
            throw ValidationException::withMessages([
                'next_academic_year_id' => 'Tahun ajaran tujuan tidak boleh sama dengan tahun berjalan.',
            ]);
        }

        return ['current' => $current, 'next' => $next];
    }

    private function resolveYear(mixed $explicit, int $fromSettings, callable $auto): ?AcademicYear
    {
        $id = (int) ($explicit ?: $fromSettings);

        if ($id > 0) {
            return AcademicYear::query()->find($id);
        }

        return $auto();
    }

    private function guessNextYear(?AcademicYear $current): ?AcademicYear
    {
        if ($current === null) {
            return AcademicYear::query()->orderByDesc('start_date')->first();
        }

        return AcademicYear::query()
            ->where('start_date', '>', $current->start_date)
            ->orderBy('start_date')
            ->first()
            ?? AcademicYear::query()->where('name', '>', $current->name)->orderBy('name')->first();
    }

    // ==================== Kelayakan (§4) ====================

    /**
     * Query santri yang BERHAK daftar ulang ke tahun tujuan (§4):
     * aktif, status akademik valid, terdaftar di tahun berjalan, belum lulus/pindah/keluar,
     * dan (bila kebijakan ketat) tidak punya tunggakan.
     *
     * @param  array{current?: AcademicYear|null, next?: AcademicYear|null, program_id?: int|null,
     *               level?: string|null, class_id?: int|null}  $config
     */
    public function eligibleQuery(array $config = []): Builder
    {
        $years = ($config['current'] ?? null) instanceof AcademicYear
            ? ['current' => $config['current'], 'next' => $config['next'] ?? null]
            : $this->resolveAcademicYears($config);

        $current = $years['current'];
        $next = $years['next'];
        $allowOutstanding = (bool) $this->settings->get('allow_re_registration_with_outstanding', false);

        // Invoice milik pendaftaran ulang tahun tujuan ini TIDAK dihitung tunggakan
        // (§4): tagihan daftar ulang justru dibuat proses ini. Tanpa pengecualian,
        // santri yang sudah di-generate akan terlempar dari "eligible" saat re-run
        // dan idempotensi (§24) tidak bisa melaporkan already_generated dengan benar.
        $ownReRegInvoices = FinanceReRegistration::query()
            ->when($next !== null, fn (Builder $q) => $q->where('next_academic_year_id', $next->id))
            ->whereNotNull('invoice_id')
            ->select('invoice_id');

        return Student::query()
            ->where('is_active', true)
            // Status akademik valid: tanpa status, atau status yang masih aktif.
            ->where(function (Builder $q): void {
                $q->whereNull('status_id')
                    ->orWhereHas('status', fn (Builder $s) => $s->where('is_active', true));
            })
            // Terdaftar pada tahun berjalan (bila diketahui).
            ->when($current !== null, function (Builder $q) use ($current): void {
                $q->whereHas('classStudents', fn (Builder $cs) => $cs
                    ->where('academic_year_id', $current->id)
                    ->where('status', 'aktif'));
            })
            // Belum lulus/pindah/keluar pada tahun berjalan.
            ->whereDoesntHave('histories', function (Builder $h) use ($current): void {
                $h->whereIn('status', self::CLOSED_HISTORY_STATUSES)
                    ->when($current !== null, fn (Builder $q) => $q->where('academic_year_id', $current->id));
            })
            // Filter segmen opsional (§3: program/jenjang/kelas).
            ->when(! empty($config['program_id']), fn (Builder $q) => $q->where('program_id', (int) $config['program_id']))
            ->when(! empty($config['class_id']), fn (Builder $q) => $q->whereHas(
                'classStudents',
                fn (Builder $cs) => $cs->where('school_class_id', (int) $config['class_id']),
            ))
            // Kebijakan tunggakan (§4) — jangan hardcode.
            ->when(! $allowOutstanding, fn (Builder $q) => $q->whereDoesntHave(
                'invoices',
                fn (Builder $inv) => $inv->where('remaining_amount', '>', 0)
                    ->where('status', '!=', FinanceInvoice::STATUS_CANCELLED)
                    ->whereNotIn('finance_invoices.id', $ownReRegInvoices),
            ))
            ->orderBy('full_name');
    }

    // ==================== Pratinjau & generasi (§5) ====================

    /**
     * Pratinjau generasi massal (§5): berapa yang berhak, sudah dibuat, akan
     * dibuat, dan dilewati — TANPA menulis apa pun.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function preview(array $config, ?User $actor = null): array
    {
        $years = $this->resolveAcademicYears($config);
        $config['current'] = $years['current'];
        $config['next'] = $years['next'];

        $eligible = $this->eligibleQuery($config)->get(['id', 'full_name', 'nis']);

        $existing = FinanceReRegistration::query()
            ->where('next_academic_year_id', $years['next']->id)
            ->whereIn('student_id', $eligible->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $willGenerate = 0;
        $alreadyGenerated = 0;
        $skipped = 0;
        $rows = [];

        foreach ($eligible as $student) {
            $reg = $existing->get((int) $student->id);
            $revivable = $reg !== null && in_array($reg->status, [
                FinanceReRegistration::STATUS_ELIGIBLE,
                FinanceReRegistration::STATUS_CANCELLED,
                FinanceReRegistration::STATUS_EXPIRED,
            ], true);

            if ($reg === null || $revivable) {
                $willGenerate++;
                $state = 'will_generate';
            } else {
                $alreadyGenerated++;
                $skipped++;
                $state = 'already_generated';
            }

            $rows[] = [
                'student_id' => (int) $student->id,
                'full_name' => $student->full_name,
                'nis' => $student->nis,
                'state' => $state,
                'status' => $reg?->status,
                'registration_number' => $reg?->registration_number,
            ];
        }

        return [
            'current_academic_year_id' => $years['current']?->id,
            'current_academic_year' => $years['current']?->name,
            'next_academic_year_id' => (int) $years['next']->id,
            'next_academic_year' => $years['next']->name,
            'eligible' => $eligible->count(),
            'already_generated' => $alreadyGenerated,
            'will_generate' => $willGenerate,
            'skipped' => $skipped,
            'students' => $rows,
        ];
    }

    /**
     * Generasi massal (§5). Idempoten per santri: yang sudah punya pendaftaran
     * aktif dilewati, bukan diduplikasi.
     *
     * @param  array<string, mixed>  $config
     * @return array{generated: int, skipped: int, failed: int, re_registrations: list<int>}
     */
    public function generateBulk(array $config, ?User $actor = null): array
    {
        $years = $this->resolveAcademicYears($config);
        $config['current'] = $years['current'];
        $config['next'] = $years['next'];

        $students = $this->eligibleQuery($config)->get();

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $ids = [];

        foreach ($students as $student) {
            try {
                $before = FinanceReRegistration::query()
                    ->where('student_id', $student->id)
                    ->where('next_academic_year_id', $years['next']->id)
                    ->value('status');

                $reg = $this->generateForStudent($student, $config, $actor);
                $ids[] = (int) $reg->id;

                $wasActive = $before !== null && ! in_array($before, [
                    FinanceReRegistration::STATUS_ELIGIBLE,
                    FinanceReRegistration::STATUS_CANCELLED,
                    FinanceReRegistration::STATUS_EXPIRED,
                ], true);

                $wasActive ? $skipped++ : $generated++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped, 'failed' => $failed, 're_registrations' => $ids];
    }

    /**
     * Generasi (atau hidupkan kembali) pendaftaran satu santri (§5). Idempoten
     * lewat unique (student, next_academic_year). Membuat billing + invoice Phase 1
     * lalu menautkannya; invoice dibuat SEBELUM pembayaran (§2).
     *
     * @param  array<string, mixed>  $config
     */
    public function generateForStudent(Student $student, array $config, ?User $actor = null): FinanceReRegistration
    {
        $years = ($config['next'] ?? null) instanceof AcademicYear
            ? ['current' => $config['current'] ?? null, 'next' => $config['next']]
            : $this->resolveAcademicYears($config);

        $next = $years['next'];
        $current = $years['current'];

        $registration = DB::transaction(function () use ($student, $config, $actor, $next, $current): FinanceReRegistration {
            $existing = FinanceReRegistration::query()
                ->where('student_id', $student->id)
                ->where('next_academic_year_id', $next->id)
                ->lockForUpdate()
                ->first();

            // Sudah aktif (billed..completed) → kembalikan apa adanya (idempoten §24).
            if ($existing !== null && in_array($existing->status, [
                FinanceReRegistration::STATUS_BILLED,
                FinanceReRegistration::STATUS_UNPAID,
                FinanceReRegistration::STATUS_PARTIAL,
                FinanceReRegistration::STATUS_PAID,
                FinanceReRegistration::STATUS_COMPLETED,
            ], true)) {
                return $existing->fresh(['student', 'billing', 'invoice', 'nextAcademicYear', 'currentAcademicYear', 'feePackage']);
            }

            $dueDate = $this->carbonize($config['due_date'] ?? null);
            $costCenterId = $config['cost_center_id'] ?? null;
            $attempt = max(1, (int) ($existing?->generation_attempt ?? 1));

            [$billing, $invoice] = $this->produceBillingAndInvoice($student, $config, $next, $dueDate, $costCenterId, $actor, $attempt);

            $amount = round((float) $invoice->total, 2);
            $discount = round((float) $billing->discount_amount + (float) $billing->scholarship_amount, 2);
            $revenueAccountId = $this->resolveRevenueAccountId($config, $invoice);
            // Sekadar catatan akun di workflow daftar ulang; yang benar-benar
            // memposting piutang adalah jurnal invoice (§20, §6).
            $receivableAccountId = $this->accountIdByCode(
                (string) $this->settings->get(AccountingPolicyService::KEY_RECEIVABLE),
            );

            $attributes = [
                'student_id' => $student->id,
                'current_academic_year_id' => $current?->id,
                'next_academic_year_id' => $next->id,
                'fee_package_id' => $config['fee_package_id'] ?? null,
                'billing_id' => $billing->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'discount_amount' => $discount,
                'paid_amount' => round((float) $invoice->paid_amount, 2),
                'due_date' => $dueDate,
                'cost_center_id' => $costCenterId,
                'revenue_account_id' => $revenueAccountId,
                'receivable_account_id' => $receivableAccountId,
                'status' => $this->statusFromInvoice($invoice),
                'billed_at' => Carbon::now(),
                'notes' => $config['notes'] ?? null,
                'created_by' => $actor?->id ?? $existing?->created_by,
                'cancel_reason' => null,
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();

                return $existing->fresh(['student', 'billing', 'invoice', 'nextAcademicYear', 'currentAcademicYear', 'feePackage']);
            }

            $attributes['registration_number'] = $this->numbering->next(FinanceNumberingService::CODE_RE_REGISTRATION);

            try {
                $reg = FinanceReRegistration::create($attributes);
            } catch (QueryException $e) {
                // Balapan generate bersamaan: unique (student, next_year) already taken.
                $reg = FinanceReRegistration::query()
                    ->where('student_id', $student->id)
                    ->where('next_academic_year_id', $next->id)
                    ->firstOrFail();
            }

            return $reg->fresh(['student', 'billing', 'invoice', 'nextAcademicYear', 'currentAcademicYear', 'feePackage']);
        });

        $this->notifications->reRegistrationBilled($registration);

        return $registration;
    }

    // ==================== Penyelesaian & aktivasi akademik (§7) ====================

    /**
     * Selesaikan pendaftaran (§7): pastikan invoice LUNAS, tandai `completed`,
     * lalu aktifkan tahun ajaran berikutnya (riwayat akademik). Invoice paid
     * TIDAK otomatis berarti workflow selesai — completion adalah aksi eksplisit.
     */
    public function complete(FinanceReRegistration $registration, ?User $actor = null, array $options = []): FinanceReRegistration
    {
        $completed = DB::transaction(function () use ($registration, $actor, $options): FinanceReRegistration {
            $reg = FinanceReRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if ($reg->status === FinanceReRegistration::STATUS_COMPLETED) {
                return $reg->fresh(['student', 'invoice', 'nextAcademicYear']);
            }

            if ($reg->status === FinanceReRegistration::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    're_registration' => 'Pendaftaran yang dibatalkan tidak bisa diselesaikan. Buka kembali lebih dulu.',
                ]);
            }

            $invoice = $reg->invoice_id !== null
                ? FinanceInvoice::query()->whereKey($reg->invoice_id)->lockForUpdate()->first()
                : null;

            if ($invoice !== null && (float) $invoice->remaining_amount > 0.0001) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice daftar ulang belum lunas; tidak bisa diselesaikan.',
                ]);
            }

            $reg->paid_amount = $invoice !== null ? round((float) $invoice->paid_amount, 2) : $reg->paid_amount;
            $reg->status = FinanceReRegistration::STATUS_COMPLETED;
            $reg->paid_at = $reg->paid_at ?? ($invoice?->updated_at ?? Carbon::now());
            $reg->completed_at = Carbon::now();
            $reg->save();

            $this->activateNextYear($reg, $actor, $options);

            return $reg->fresh(['student', 'invoice', 'nextAcademicYear', 'currentAcademicYear']);
        });

        $this->notifications->reRegistrationCompleted($completed);

        return $completed;
    }

    /**
     * Aktifkan santri pada tahun ajaran tujuan (§7). Membuat riwayat akademik
     * (status `naik` default, dapat dioverride) dan — bila kelas tujuan diberikan —
     * penempatan rombel. Idempoten: unique (student, academic_year) dijaga.
     *
     * @param  array{history_status?: string, next_class_id?: int|null, education_level_id?: int|null}  $options
     */
    private function activateNextYear(FinanceReRegistration $reg, ?User $actor, array $options = []): void
    {
        $historyStatus = (string) ($options['history_status'] ?? self::DEFAULT_HISTORY_STATUS);

        if (! in_array($historyStatus, StudentHistory::STATUSES, true)) {
            $historyStatus = self::DEFAULT_HISTORY_STATUS;
        }

        $nextYearId = (int) $reg->next_academic_year_id;
        $studentId = (int) $reg->student_id;

        StudentHistory::query()->firstOrCreate(
            ['student_id' => $studentId, 'academic_year_id' => $nextYearId],
            [
                'school_class_id' => $options['next_class_id'] ?? null,
                'education_level_id' => $options['education_level_id'] ?? null,
                'status' => $historyStatus,
                'note' => 'Aktivasi daftar ulang '.$reg->registration_number,
                'created_by' => $actor?->id,
            ],
        );

        if (! empty($options['next_class_id'])) {
            $classId = (int) $options['next_class_id'];
            $schoolClass = SchoolClass::query()->find($classId);

            ClassStudent::query()->firstOrCreate(
                ['student_id' => $studentId, 'academic_year_id' => $nextYearId],
                ['school_class_id' => $schoolClass?->id ?? $classId, 'status' => 'aktif'],
            );
        }

        $reg->activated = true;
        $reg->activated_at = Carbon::now();
        $reg->save();
    }

    // ==================== Batal & buka kembali (§5, §6) ====================

    /**
     * Batalkan pendaftaran (§6). Hanya boleh bila belum ada uang masuk: billing/
     * invoice terkait ikut dibatalkan (reversal akrual oleh mesin billing §15).
     * Baris TIDAK dihapus — jejak audit dipertahankan.
     */
    public function cancel(FinanceReRegistration $registration, string $reason, ?User $actor = null): FinanceReRegistration
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($registration, $reason, $actor): FinanceReRegistration {
            $reg = FinanceReRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if ($reg->status === FinanceReRegistration::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['re_registration' => 'Pendaftaran sudah dibatalkan.']);
            }

            if ($reg->status === FinanceReRegistration::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    're_registration' => 'Pendaftaran yang sudah selesai tidak bisa dibatalkan.',
                ]);
            }

            if ((float) $reg->paid_amount > 0.0001) {
                throw ValidationException::withMessages([
                    're_registration' => 'Sudah ada pembayaran. Batalkan pembayaran lebih dulu, bukan pendaftarannya.',
                ]);
            }

            if ($reg->billing_id !== null) {
                $billing = FinanceBilling::query()->whereKey($reg->billing_id)->first();

                if ($billing !== null && $billing->status !== FinanceBilling::STATUS_CANCELLED) {
                    $this->billing->cancelBilling($billing, $reason, $actor);
                }
            }

            $reg->status = FinanceReRegistration::STATUS_CANCELLED;
            $reg->cancel_reason = $reason;
            $reg->save();

            return $reg->fresh(['student', 'invoice', 'nextAcademicYear']);
        });
    }

    /**
     * Buka kembali pendaftaran `expired`/`cancelled` (§5). Bila invoice masih
     * berlaku, status disinkronkan dari invoice; bila billing sudah dibatalkan,
     * baris dikembalikan ke `eligible` agar bisa di-generate ulang.
     */
    public function reopen(FinanceReRegistration $registration, ?User $actor = null): FinanceReRegistration
    {
        return DB::transaction(function () use ($registration): FinanceReRegistration {
            $reg = FinanceReRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if (! in_array($reg->status, [FinanceReRegistration::STATUS_EXPIRED, FinanceReRegistration::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages([
                    're_registration' => 'Hanya pendaftaran kedaluwarsa/batal yang bisa dibuka kembali.',
                ]);
            }

            $invoice = $reg->invoice_id !== null ? FinanceInvoice::query()->find($reg->invoice_id) : null;
            $billing = $reg->billing_id !== null ? FinanceBilling::query()->find($reg->billing_id) : null;

            if ($billing !== null && $billing->status === FinanceBilling::STATUS_CANCELLED) {
                // Tagihan sudah dibatalkan: lepas tautan, kembalikan ke eligible.
                // Naikkan attempt agar generate berikutnya memakai reference paket
                // baru — billing batal yang lama tidak dikembalikan mesin idempoten.
                $reg->billing_id = null;
                $reg->invoice_id = null;
                $reg->amount = 0;
                $reg->paid_amount = 0;
                $reg->status = FinanceReRegistration::STATUS_ELIGIBLE;
                $reg->generation_attempt = (int) $reg->generation_attempt + 1;
            } elseif ($invoice !== null && $invoice->status !== FinanceInvoice::STATUS_CANCELLED) {
                $reg->status = $this->statusFromInvoice($invoice);
                $reg->paid_amount = round((float) $invoice->paid_amount, 2);
            } else {
                $reg->status = FinanceReRegistration::STATUS_ELIGIBLE;
            }

            $reg->cancel_reason = null;
            $reg->activated = false;
            $reg->activated_at = null;
            $reg->completed_at = null;
            $reg->save();

            return $reg->fresh(['student', 'invoice', 'nextAcademicYear']);
        });
    }

    /**
     * Sinkronkan status `paid_amount`/status dari invoice terkait. Dipanggil oleh
     * mesin pembayaran setelah alokasi (§6, §32) agar `unpaid→partial→paid`
     * bergerak otomatis tanpa mempercayai angka yang disimpan mentah.
     */
    public function syncFromInvoice(FinanceInvoice $invoice): void
    {
        $regs = FinanceReRegistration::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [
                FinanceReRegistration::STATUS_BILLED,
                FinanceReRegistration::STATUS_UNPAID,
                FinanceReRegistration::STATUS_PARTIAL,
                FinanceReRegistration::STATUS_PAID,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($regs as $reg) {
            $reg->paid_amount = round((float) $invoice->paid_amount, 2);
            $reg->status = $this->statusFromInvoice($invoice);
            $reg->paid_at = $invoice->isSettled() ? ($reg->paid_at ?? Carbon::now()) : null;
            $reg->save();
        }
    }

    /**
     * Tandai pendaftaran terbuka yang lewat batas (due_date) jadi `expired` (§6).
     * Dijalankan terjadwal, bukan per-request.
     *
     * @return int jumlah baris yang ditandai
     */
    public function markExpired(?Carbon $asOf = null): int
    {
        $date = ($asOf ?? Carbon::now())->toDateString();

        $stale = FinanceReRegistration::query()
            ->whereIn('status', [
                FinanceReRegistration::STATUS_BILLED,
                FinanceReRegistration::STATUS_UNPAID,
                FinanceReRegistration::STATUS_PARTIAL,
            ])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $date)
            ->get();

        $updated = FinanceReRegistration::query()
            ->whereIn('id', $stale->pluck('id'))
            ->update(['status' => FinanceReRegistration::STATUS_EXPIRED, 'updated_at' => Carbon::now()]);

        foreach ($stale as $reg) {
            $this->notifications->reRegistrationExpired($reg);
        }

        return $updated;
    }

    // ==================== Internal ====================

    /**
     * Produksi billing + invoice Phase 1 untuk daftar ulang. Memakai paket bila
     * `fee_package_id` diberikan (mesin paket, idempoten by reference §14); jika
     * tidak, billing manual satu baris (§10) dari fee_type/amount config.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: FinanceBilling, 1: FinanceInvoice}
     */
    private function produceBillingAndInvoice(
        Student $student,
        array $config,
        AcademicYear $next,
        ?string $dueDate,
        ?int $costCenterId,
        ?User $actor,
        int $attempt = 1,
    ): array {
        // `attempt` membedakan generasi ulang setelah cancel: billing paket lama
        // (sudah dibatalkan) punya reference ':1', yang baru ':2' dst. — sehingga
        // idempotensi paket (§14) tidak mengembalikan billing batal.
        $periodKey = 'rereg:'.$next->id.':'.$attempt;

        if (! empty($config['fee_package_id'])) {
            $package = FinanceFeePackage::query()->findOrFail((int) $config['fee_package_id']);

            $billing = $this->billing->generateForStudent($student, $package, $periodKey, [
                'due_date' => $dueDate,
                'cost_center_id' => $costCenterId,
                'notes' => $config['notes'] ?? ('Daftar Ulang '.$next->name),
                'created_by' => $actor?->id,
            ]);
        } else {
            $amount = round((float) ($config['amount'] ?? 0), 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Tentukan paket tagihan (fee_package_id) atau nominal daftar ulang (amount).',
                ]);
            }

            $feeTypeId = $this->resolveFeeTypeId($config);

            $billing = $this->billing->createManual([
                'customer_type' => 'student',
                'customer_id' => $student->id,
                'student_id' => $student->id,
                'cost_center_id' => $costCenterId,
                'due_date' => $dueDate,
                'notes' => $config['notes'] ?? ('Daftar Ulang '.$next->name),
                'created_by' => $actor?->id,
                'items' => [[
                    'fee_type_id' => $feeTypeId,
                    'description' => 'Daftar Ulang '.$next->name,
                    'amount' => $amount,
                    'quantity' => 1,
                ]],
            ]);

            // Idempotensi manual: createManual tidak punya reference unik, jadi
            // tautkan ke baris re-registration (unique student+year) di pemanggil.
        }

        // `notify: false`: daftar ulang mengirim satu notifikasi khusus
        // (re_registration_billed), bukan notifikasi invoice terbit yang ganda.
        $invoice = $this->billing->createInvoice($billing, $actor, notify: false);

        return [$billing->fresh(), $invoice];
    }

    private function resolveFeeTypeId(array $config): ?int
    {
        if (! empty($config['fee_type_id'])) {
            return (int) $config['fee_type_id'];
        }

        return FinanceFeeType::query()->where('code', 'DAFTAR_ULANG')->value('id');
    }

    private function resolveRevenueAccountId(array $config, FinanceInvoice $invoice): ?int
    {
        if (! empty($config['revenue_account_id'])) {
            return (int) $config['revenue_account_id'];
        }

        // Turunkan dari baris invoice (akun pendapatan fee type) bila ada.
        $accountId = $invoice->items()->whereNotNull('account_id')->value('account_id');

        return $accountId !== null ? (int) $accountId : null;
    }

    private function accountIdByCode(string $code): ?int
    {
        $id = FinanceAccount::query()->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Turunkan status workflow dari keadaan invoice (§6). */
    private function statusFromInvoice(FinanceInvoice $invoice): string
    {
        if ($invoice->status === FinanceInvoice::STATUS_CANCELLED) {
            return FinanceReRegistration::STATUS_ELIGIBLE;
        }

        $paid = (float) $invoice->paid_amount;
        $total = (float) $invoice->total;

        if ($total <= 0.0001 || $invoice->isSettled()) {
            return FinanceReRegistration::STATUS_PAID;
        }

        if ($paid > 0.0001) {
            return FinanceReRegistration::STATUS_PARTIAL;
        }

        return FinanceReRegistration::STATUS_UNPAID;
    }

    private function carbonize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateString() : Carbon::parse($value)->toDateString();
    }
}
