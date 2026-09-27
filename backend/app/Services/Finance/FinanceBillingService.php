<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\FinanceBilling;
use App\Models\FinanceBillingItem;
use App\Models\FinanceDiscount;
use App\Models\FinanceFeeItem;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceInvoiceItem;
use App\Models\FinanceOperation;
use App\Models\FinanceScholarship;
use App\Models\FinancialTransaction;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin penagihan (§13–§18) + penerbitan invoice & pengakuan pendapatan (§16, §46).
 *
 * Alur: paket tagihan → billing per santri (idempoten lewat `reference` unik §14)
 * → invoice → jurnal pengakuan lewat `JournalPostingService`. Service ini mengatur
 * dokumen, bukan susunan debit/kredit — pemetaan akun & basis akuntansi milik
 * lapisan akuntansi (Phase 3 §6).
 *
 * Semua operasi penting dibungkus `DB::transaction` + `lockForUpdate` agar dua
 * permintaan bersamaan tidak menerbitkan dua billing/invoice untuk periode sama.
 */
class FinanceBillingService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly JournalPostingService $posting,
        private readonly AccountingPolicyService $policy,
        private readonly FinanceNotificationService $notifications,
        private readonly FinanceOutstandingRecalculator $balances,
        private readonly FinanceSettingsService $settings,
        private readonly FinanceOperationService $operations,
    ) {}

    /**
     * Terbitkan billing satu santri dari satu paket untuk satu periode (§13, §14).
     *
     * Idempoten: kombinasi (santri, paket, periode) dipetakan ke `reference` unik.
     * Bila sudah ada, billing lama dikembalikan apa adanya — tidak pernah dobel.
     *
     * @param  array{period_start?: Carbon|string|null, period_end?: Carbon|string|null,
     *               due_date?: Carbon|string|null, cost_center_id?: int|null,
     *               notes?: string|null, created_by?: int|null}  $options
     */
    public function generateForStudent(
        Student $student,
        FinanceFeePackage $package,
        string $periodKey,
        array $options = [],
    ): FinanceBilling {
        $reference = $this->referenceKey($student->id, $package->id, $periodKey);

        return DB::transaction(function () use ($student, $package, $reference, $options): FinanceBilling {
            // Kunci baris billing existing (bila ada) agar generasi bersamaan serial.
            $existing = FinanceBilling::query()->where('reference', $reference)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $items = $package->activeItems()->with('feeType')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'fee_package_id' => "Paket {$package->code} tidak punya baris tagihan aktif.",
                ]);
            }

            $discounts = $this->applicableDiscounts($student);
            $scholarships = $this->applicableScholarships($student);

            $billing = FinanceBilling::create([
                'billing_number' => $this->numbering->next(FinanceNumberingService::CODE_BILLING),
                'customer_type' => 'student',
                'customer_id' => $student->id,
                'student_id' => $student->id,
                'source' => 'package',
                'reference' => $reference,
                'period_start' => $this->carbonize($options['period_start'] ?? null),
                'period_end' => $this->carbonize($options['period_end'] ?? null),
                'due_date' => $this->carbonize($options['due_date'] ?? null),
                'cost_center_id' => $options['cost_center_id'] ?? null,
                'status' => FinanceBilling::STATUS_ISSUED,
                'notes' => $options['notes'] ?? null,
                'created_by' => $options['created_by'] ?? null,
                // total/remaining diisi setelah baris dihitung di bawah.
                'subtotal' => 0, 'discount_amount' => 0, 'scholarship_amount' => 0,
                'tax' => 0, 'total' => 0, 'paid_amount' => 0, 'remaining_amount' => 0,
            ]);

            $this->buildBillingItems($billing, $items, $discounts, $scholarships);
            $this->recalculateBilling($billing);

            return $billing->fresh('items.feeType');
        });
    }

    /**
     * Terbitkan billing untuk banyak santri sekaligus (§14). Idempoten per santri:
     * yang sudah punya billing periode ini dilewati, bukan diduplikasi.
     *
     * @param  iterable<Student>  $students
     * @return array{generated: int, skipped: int, billings: list<int>}
     */
    public function generateBulk(
        FinanceFeePackage $package,
        iterable $students,
        string $periodKey,
        array $options = [],
    ): array {
        $generated = 0;
        $skipped = 0;
        $ids = [];

        foreach ($students as $student) {
            $reference = $this->referenceKey($student->id, $package->id, $periodKey);
            $already = FinanceBilling::query()->where('reference', $reference)->exists();

            $billing = $this->generateForStudent($student, $package, $periodKey, $options);
            $ids[] = (int) $billing->id;
            $already ? $skipped++ : $generated++;
        }

        return ['generated' => $generated, 'skipped' => $skipped, 'billings' => $ids];
    }

    /**
     * Generate SPP terjadwal (§7): satu paket pilihan ditagihkan ke seluruh santri
     * aktif untuk jendela periode yang memuat hari acuan, lalu — bila konfigurasi
     * mengizinkan — invoice-nya diterbitkan.
     *
     * Yang menentukan kapan tagihan baru lahir adalah jendela periodenya, bukan
     * kapan perintah dijalankan: bulanan = satu billing per bulan, triwulan /
     * semester / tahun ajaran = satu billing per jendela itu. Karena kombinasi
     * (santri, paket, periode) sudah menjadi `reference` unik dan createInvoice()
     * mengembalikan invoice yang ada, perintah ini boleh dijalankan setiap hari
     * tanpa melahirkan tagihan atau pengakuan pendapatan kedua.
     *
     * Satu invoice yang gagal (mis. periodenya sudah ditutup) tidak membatalkan
     * seluruh generasi — yang gagal dihitung dan alasannya dilaporkan, sisanya
     * tetap terbit. Scheduler yang berhenti di baris pertama justru meninggalkan
     * separuh santri tanpa tagihan.
     *
     * @return array{enabled: bool, cycle: string, period_key: string|null, generated: int, skipped: int, invoices: int, already_invoiced: int, failed: int, reason: string|null}
     */
    public function generateScheduled(?Carbon $asOf = null, ?User $actor = null): array
    {
        $date = $asOf ?? Carbon::now();
        $cycle = (string) $this->settings->get('spp_auto_generate_cycle', 'monthly');

        $report = [
            'enabled' => (bool) $this->settings->get('spp_auto_generate_enabled', false),
            'cycle' => $cycle,
            'period_key' => null,
            'generated' => 0,
            'skipped' => 0,
            'invoices' => 0,
            'already_invoiced' => 0,
            'failed' => 0,
            'reason' => null,
        ];

        if (! $report['enabled']) {
            $report['reason'] = 'Generate SPP otomatis tidak aktif (Settings → Keuangan, kunci spp_auto_generate_enabled).';

            return $report;
        }

        $packageId = (int) $this->settings->get('spp_auto_generate_fee_package_id', 0);
        $package = $packageId > 0
            ? FinanceFeePackage::query()->where('is_active', true)->find($packageId)
            : null;

        if ($package === null) {
            $report['reason'] = 'Paket SPP otomatis belum dipilih atau tidak aktif (kunci spp_auto_generate_fee_package_id).';

            return $report;
        }

        $window = $this->scheduledWindow($cycle, $date);

        if ($window === null) {
            $report['reason'] = $cycle === 'monthly' || $cycle === 'quarterly'
                ? "Siklus \"{$cycle}\" tidak menghasilkan jendela periode untuk {$date->toDateString()}."
                : "Kalender akademik (tahun ajaran atau semester aktif) belum diatur untuk siklus \"{$cycle}\".";

            return $report;
        }

        $report['period_key'] = $window['key'];

        $students = Student::query()->active()->orderBy('id')->get();

        if ($students->isEmpty()) {
            $report['reason'] = 'Tidak ada santri aktif untuk ditagih.';

            return $report;
        }

        $dueAfterDays = max(0, min(365, (int) $this->settings->get('spp_due_after_days', 10)));

        $result = $this->generateBulk($package, $students, $window['key'], [
            'period_start' => $window['start'],
            'period_end' => $window['end'],
            'due_date' => $window['start']->copy()->addDays($dueAfterDays),
            'notes' => "Diterbitkan otomatis oleh jadwal SPP ({$cycle}).",
            'created_by' => $actor?->id,
        ]);

        $report['generated'] = $result['generated'];
        $report['skipped'] = $result['skipped'];

        if ($result['billings'] === [] || ! (bool) $this->settings->get('spp_auto_issue_invoice', true)) {
            return $report;
        }

        foreach (FinanceBilling::query()->whereIn('id', $result['billings'])->get() as $billing) {
            try {
                // Semua billing yang ada di jendela ini ikut diperiksa, bukan hanya
                // yang baru lahir: run sebelumnya bisa berhenti di sini (misalnya
                // periodenya masih tertutup) dan tagihannya harus tetap terbit pada
                // jalan berikutnya. Karena `createInvoice()` mengembalikan invoice
                // yang sudah ada, status "sudah terbit" dibaca sebelum memanggilnya
                // — kalau tidak, laporan berbunyi menghasilkan sesuatu padahal
                // tidak ada yang baru.
                $sudahTerbit = FinanceInvoice::query()->where('billing_id', $billing->id)->exists();

                $this->createInvoice($billing, $actor);

                $sudahTerbit ? $report['already_invoiced']++ : $report['invoices']++;
            } catch (ValidationException $e) {
                $report['failed']++;
                $report['reason'] ??= $this->firstValidationError($e);
            } catch (\Throwable $e) {
                // Penolakan bisnis (periode tertutup, master belum lengkap) sudah
                // dilaporkan lewat `reason` di atas. Yang sampai di sini adalah
                // kegagalan tak terduga — umumnya penerbitan jurnal — dan ia tidak
                // boleh mematikan seluruh generasi massal: tagihan santri lain di
                // periode yang sama tetap harus terbit (§28). Transaksinya sendiri
                // sudah di-rollback, jadi tidak ada paruh invoice yang tertinggal;
                // yang perlu adalah jejaknya, dan itu sebabnya baris operasinya
                // ditandai boleh diulang (§29).
                $report['failed']++;
                $report['reason'] ??= 'Penerbitan invoice gagal pada sebagian tagihan.';

                $this->operations->recordIncident(
                    FinanceOperation::EVENT_JOURNAL_POSTING,
                    $e,
                    ['billing_id' => (int) $billing->id, 'period_key' => $report['period_key']],
                    ['type' => FinanceBilling::class, 'id' => (int) $billing->id, 'number' => (string) $billing->reference],
                    retryable: true,
                    actor: $actor,
                );
            }
        }

        return $report;
    }

    /**
     * Jendela periode SPP yang memuat tanggal acuan, menurut siklus konfigurasi.
     * `key` masuk ke `reference` billing, jadi ia harus stabil: tanggal berbeda
     * di dalam satu jendela menghasilkan kunci yang sama.
     *
     * @return array{key: string, start: Carbon, end: Carbon}|null
     */
    protected function scheduledWindow(string $cycle, Carbon $date): ?array
    {
        if ($cycle === 'monthly') {
            return [
                'key' => 'SPP-'.$date->format('Y-m'),
                'start' => $date->copy()->startOfMonth(),
                'end' => $date->copy()->endOfMonth(),
            ];
        }

        if ($cycle === 'quarterly') {
            $quarter = (int) ceil($date->month / 3);

            return [
                'key' => 'SPP-'.$date->year.'-Q'.$quarter,
                'start' => Carbon::create($date->year, $quarter * 3 - 2, 1)->startOfMonth(),
                'end' => Carbon::create($date->year, $quarter * 3, 1)->endOfMonth(),
            ];
        }

        $year = AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->first();

        if ($year === null || $year->start_date === null || $year->end_date === null) {
            return null;
        }

        $start = $year->start_date->copy()->startOfDay();
        $end = $year->end_date->copy()->endOfDay();

        if ($date->lt($start) || $date->gt($end)) {
            return null;
        }

        if ($cycle === 'academic_year') {
            return ['key' => 'SPP-'.$year->name, 'start' => $start, 'end' => $end];
        }

        if ($cycle !== 'semester') {
            return null;
        }

        $semester = Semester::query()
            ->where('academic_year_id', $year->id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderBy('start_date')
            ->first();

        if ($semester !== null) {
            return [
                'key' => 'SPP-'.$year->name.'-'.$semester->name,
                'start' => $semester->start_date->copy()->startOfDay(),
                'end' => $semester->end_date->copy()->endOfDay(),
            ];
        }

        // Kalender akademik belum punya baris semester: tahun ajaran dibelah dua.
        // Kuncinya tetap stabil karena hanya bergantung pada tanggal tahun ajaran,
        // bukan pada hari perintah dijalankan.
        $midpoint = $start->copy()->addDays((int) floor($start->diffInDays($end, false) / 2))->endOfDay();

        return $date->lte($midpoint)
            ? ['key' => 'SPP-'.$year->name.'-Semester 1', 'start' => $start, 'end' => $midpoint]
            : ['key' => 'SPP-'.$year->name.'-Semester 2', 'start' => $midpoint->copy()->addDay()->startOfDay(), 'end' => $end];
    }

    /** Pesan validasi pertama dari invoice yang gagal, untuk laporan scheduler (§29). */
    protected function firstValidationError(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            if ($messages !== []) {
                return (string) reset($messages);
            }
        }

        return $e->getMessage();
    }

    /**
     * Buat billing ad-hoc (sumber manual) dari baris bebas (§13).
     *
     * @param  array{customer_type?: string, customer_id: int, student_id?: int|null,
     *               cost_center_id?: int|null, due_date?: Carbon|string|null,
     *               notes?: string|null, created_by?: int|null,
     *               items: list<array{fee_type_id?: int|null, description: string,
     *               amount: float, quantity?: int}>}  $data
     */
    public function createManual(array $data): FinanceBilling
    {
        return DB::transaction(function () use ($data): FinanceBilling {
            $billing = FinanceBilling::create([
                'billing_number' => $this->numbering->next(FinanceNumberingService::CODE_BILLING),
                'customer_type' => $data['customer_type'] ?? 'student',
                'customer_id' => $data['customer_id'],
                'student_id' => $data['student_id'] ?? null,
                'source' => 'manual',
                'reference' => null,
                'due_date' => $this->carbonize($data['due_date'] ?? null),
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'status' => FinanceBilling::STATUS_ISSUED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'subtotal' => 0, 'discount_amount' => 0, 'scholarship_amount' => 0,
                'tax' => 0, 'total' => 0, 'paid_amount' => 0, 'remaining_amount' => 0,
            ]);

            foreach ($data['items'] as $item) {
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $amount = round((float) $item['amount'], 2);
                $total = round($amount * $qty, 2);

                FinanceBillingItem::create([
                    'billing_id' => $billing->id,
                    'fee_type_id' => $item['fee_type_id'] ?? null,
                    'description' => $item['description'],
                    'amount' => $amount,
                    'quantity' => $qty,
                    'discount_amount' => 0,
                    'scholarship_amount' => 0,
                    'total' => $total,
                ]);
            }

            $this->recalculateBilling($billing);

            return $billing->fresh('items.feeType');
        });
    }

    /**
     * Kunci idempotensi "tagihan lain" (§10): satu jenis tagihan untuk satu santri
     * pada satu periode hanya boleh sekali. Mencegah dobel saat bulk diulang.
     */
    public function otherReferenceKey(int $feeTypeId, int $studentId, string $periodKey): string
    {
        return sprintf('oth:ft:%d|stu:%d|per:%s', $feeTypeId, $studentId, $periodKey);
    }

    /**
     * Preview "tagihan lain" massal (§10): pilih jenis tagihan → target santri →
     * hitung yang akan dibuat vs yang sudah ada (exclude existing) tanpa menulis.
     *
     * @param  array{fee_type_id: int, period_key: string, amount?: float|null, students: iterable<Student>}  $config
     * @return array{fee_type_id: int, period_key: string, amount: float, total: int, will_generate: int, already_billed: int, students: list<array{student_id: int, full_name: string, nis: string|null, state: string, billing_number: string|null}>}
     */
    public function previewOtherBilling(array $config): array
    {
        $feeTypeId = (int) $config['fee_type_id'];
        $periodKey = (string) $config['period_key'];
        $feeType = FinanceFeeType::query()->findOrFail($feeTypeId);
        $amount = round((float) ($config['amount'] ?? $feeType->default_amount), 2);

        $rows = [];
        $willGenerate = 0;
        $alreadyBilled = 0;

        foreach ($config['students'] as $student) {
            $reference = $this->otherReferenceKey($feeTypeId, (int) $student->id, $periodKey);
            $existing = FinanceBilling::query()->where('reference', $reference)->first();

            if ($existing !== null) {
                $alreadyBilled++;
                $state = 'already_billed';
                $billingNumber = $existing->billing_number;
            } else {
                $willGenerate++;
                $state = 'new';
                $billingNumber = null;
            }

            $rows[] = [
                'student_id' => (int) $student->id,
                'full_name' => $student->full_name,
                'nis' => $student->nis,
                'state' => $state,
                'billing_number' => $billingNumber,
            ];
        }

        return [
            'fee_type_id' => $feeTypeId,
            'fee_type_name' => $feeType->name,
            'period_key' => $periodKey,
            'amount' => $amount,
            'total' => count($rows),
            'will_generate' => $willGenerate,
            'already_billed' => $alreadyBilled,
            'students' => $rows,
        ];
    }

    /**
     * Buat "tagihan lain" untuk satu santri dari satu jenis tagihan (§10, §12).
     * Idempoten lewat `reference` unik; billing yang sudah ada dikembalikan apa
     * adanya. Setiap baris menyimpan fee_type + amount sehingga invoice bisa
     * snapshot akun pendapatan per jenis (§12, §16).
     *
     * @param  array{description?: string|null, quantity?: int|null, due_date?: Carbon|string|null,
     *               cost_center_id?: int|null, notes?: string|null, created_by?: int|null}  $options
     */
    public function generateOtherForStudent(
        Student $student,
        int $feeTypeId,
        float $amount,
        string $periodKey,
        array $options = [],
    ): FinanceBilling {
        return DB::transaction(function () use ($student, $feeTypeId, $amount, $periodKey, $options): FinanceBilling {
            $reference = $this->otherReferenceKey($feeTypeId, (int) $student->id, $periodKey);

            $existing = FinanceBilling::query()->where('reference', $reference)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $feeType = FinanceFeeType::query()->findOrFail($feeTypeId);
            $quantity = max(1, (int) ($options['quantity'] ?? 1));
            $amount = round($amount, 2);

            $billing = FinanceBilling::create([
                'billing_number' => $this->numbering->next(FinanceNumberingService::CODE_BILLING),
                'customer_type' => 'student',
                'customer_id' => $student->id,
                'student_id' => $student->id,
                'source' => 'other',
                'reference' => $reference,
                'due_date' => $this->carbonize($options['due_date'] ?? null),
                'cost_center_id' => $options['cost_center_id'] ?? null,
                'status' => FinanceBilling::STATUS_ISSUED,
                'notes' => $options['notes'] ?? null,
                'created_by' => $options['created_by'] ?? null,
                'subtotal' => 0, 'discount_amount' => 0, 'scholarship_amount' => 0,
                'tax' => 0, 'total' => 0, 'paid_amount' => 0, 'remaining_amount' => 0,
            ]);

            FinanceBillingItem::create([
                'billing_id' => $billing->id,
                'fee_type_id' => $feeTypeId,
                'description' => $options['description'] ?? $feeType->name,
                'amount' => $amount,
                'quantity' => $quantity,
                'discount_amount' => 0,
                'scholarship_amount' => 0,
                'total' => round($amount * $quantity, 2),
            ]);

            $this->recalculateBilling($billing);

            return $billing->fresh('items.feeType');
        });
    }

    /**
     * Generate "tagihan lain" massal (§10): idempoten per (jenis, santri, periode).
     * Yang sudah ada dilewati (exclude existing), tidak pernah dobel.
     *
     * @param  iterable<Student>  $students
     * @param  array{description?: string|null, quantity?: int|null, due_date?: Carbon|string|null,
     *               cost_center_id?: int|null, notes?: string|null, created_by?: int|null}  $options
     * @return array{generated: int, skipped: int, billings: list<int>}
     */
    public function generateOtherBulk(
        int $feeTypeId,
        iterable $students,
        float $amount,
        string $periodKey,
        array $options = [],
    ): array {
        $generated = 0;
        $skipped = 0;
        $ids = [];

        foreach ($students as $student) {
            $reference = $this->otherReferenceKey($feeTypeId, (int) $student->id, $periodKey);
            $already = FinanceBilling::query()->where('reference', $reference)->exists();

            $billing = $this->generateOtherForStudent($student, $feeTypeId, $amount, $periodKey, $options);
            $ids[] = (int) $billing->id;
            $already ? $skipped++ : $generated++;
        }

        return ['generated' => $generated, 'skipped' => $skipped, 'billings' => $ids];
    }

    /**
     * Terbitkan invoice dari billing (§16) + jurnal akrual pendapatan (§46).
     *
     * Satu billing → maksimal satu invoice (idempoten). Jurnal: debit piutang
     * (1300) sebesar total, credit akun pendapatan per jenis tagihan — sehingga
     * pendapatan diakui saat invoice terbit, bukan saat uang masuk.
     */
    public function createInvoice(FinanceBilling $billing, ?User $actor = null, bool $notify = true): FinanceInvoice
    {
        return DB::transaction(function () use ($billing, $actor, $notify): FinanceInvoice {
            $locked = FinanceBilling::query()->whereKey($billing->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === FinanceBilling::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'billing' => 'Billing dibatalkan tidak bisa diterbitkan invoice-nya.',
                ]);
            }

            $existing = FinanceInvoice::query()->where('billing_id', $locked->id)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $locked->load('items.feeType.account');

            $invoice = FinanceInvoice::create([
                'invoice_number' => $this->numbering->next(FinanceNumberingService::CODE_INVOICE),
                'billing_id' => $locked->id,
                'customer_type' => $locked->customer_type,
                'customer_id' => $locked->customer_id,
                'student_id' => $locked->student_id,
                'issue_date' => Carbon::now()->toDateString(),
                'due_date' => $locked->due_date,
                'cost_center_id' => $locked->cost_center_id,
                'subtotal' => $locked->subtotal,
                'discount_amount' => $locked->discount_amount,
                'tax' => $locked->tax,
                'total' => $locked->total,
                'paid_amount' => 0,
                'remaining_amount' => $locked->total,
                'status' => FinanceInvoice::STATUS_ISSUED,
                'notes' => $locked->notes,
                'created_by' => $actor?->id ?? $locked->created_by,
            ]);

            // Akun pendapatan tiap baris dari pemetaan fee type (§20); keputusan
            // debit/kredit milik JournalPostingService, bukan service dokumen ini.
            foreach ($locked->items as $item) {
                FinanceInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'fee_type_id' => $item->fee_type_id,
                    'account_id' => $this->policy->revenueAccountId($item->feeType),
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->amount,
                    'discount_amount' => round((float) $item->discount_amount + (float) $item->scholarship_amount, 2),
                    'tax' => 0,
                    'total' => $item->total,
                ]);
            }

            $locked->invoice_id = $invoice->id;
            $locked->save();

            $this->posting->invoiceIssuance($invoice, $actor);

            if ($notify) {
                $this->notifications->invoiceIssued($invoice);
            }

            return $invoice->fresh('items.account');
        });
    }

    /**
     * Batalkan billing (§15): hanya boleh bila belum ada pembayaran. Invoice terkait
     * ikut dibatalkan dan jurnal akrualnya dibalik (reversal) agar buku besar utuh.
     */
    public function cancelBilling(FinanceBilling $billing, string $reason, ?User $actor = null): FinanceBilling
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($billing, $reason, $actor): FinanceBilling {
            $locked = FinanceBilling::query()->whereKey($billing->id)->lockForUpdate()->firstOrFail();

            if ((float) $locked->paid_amount > 0) {
                throw ValidationException::withMessages([
                    'billing' => 'Billing sudah punya pembayaran. Batalkan pembayaran lebih dulu, bukan billing-nya.',
                ]);
            }

            FinanceStatusMachine::assert($locked, FinanceBilling::STATUS_CANCELLED, 'billing');

            $invoice = FinanceInvoice::query()->where('billing_id', $locked->id)->first();

            if ($invoice !== null) {
                FinanceStatusMachine::assert($invoice, FinanceInvoice::STATUS_CANCELLED, 'billing');
                $this->reverseInvoiceAccrual($invoice, $reason, $actor);
                $invoice->status = FinanceInvoice::STATUS_CANCELLED;
                $invoice->save();
            }

            $locked->status = FinanceBilling::STATUS_CANCELLED;
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '')."Dibatalkan: {$reason}");
            $locked->save();

            return $locked->fresh('items.feeType');
        });
    }

    /**
     * Hitung ulang total billing dari baris + status (§15). `paid_amount` tidak
     * disentuh di sini — ia bergerak hanya lewat payment terverifikasi (§21).
     */
    public function recalculateBilling(FinanceBilling $billing): FinanceBilling
    {
        $billing->load('items');

        $subtotal = 0.0;
        $discount = 0.0;
        $scholarship = 0.0;

        foreach ($billing->items as $item) {
            $subtotal += round((float) $item->amount * (int) $item->quantity, 2);
            $discount += (float) $item->discount_amount;
            $scholarship += (float) $item->scholarship_amount;
        }

        $total = round(max(0, $subtotal - $discount - $scholarship + (float) $billing->tax), 2);

        $billing->subtotal = round($subtotal, 2);
        $billing->discount_amount = round($discount, 2);
        $billing->scholarship_amount = round($scholarship, 2);
        $billing->total = $total;

        // Sisa & status tidak dihitung di sini: satu pintu di FinanceOutstanding-
        // Recalculator (§31), supaya write-off piutang ikut memperkecil sisa billing.
        $this->balances->recalculate($billing);

        return $billing;
    }

    /**
     * Tandai billing & invoice yang lewat jatuh tempo dan belum lunas jadi
     * `overdue` (§15). Dijalankan terjadwal (command) — bukan per-request.
     *
     * @return array{billings: int, invoices: int}
     */
    public function markOverdue(?Carbon $asOf = null): array
    {
        $date = ($asOf ?? Carbon::now())->toDateString();

        // Invoice yang *baru* melewati jatuh tempo dikunci dulu sebelum status
        // diganti, agar pengingat hanya sekali per dokumen.
        $overdueInvoiceIds = FinanceInvoice::query()
            ->whereIn('status', [FinanceInvoice::STATUS_ISSUED, FinanceInvoice::STATUS_PARTIAL])
            ->whereDate('due_date', '<', $date)
            ->where('remaining_amount', '>', 0)
            ->pluck('id');

        $billings = FinanceBilling::query()
            ->whereIn('status', [FinanceBilling::STATUS_ISSUED, FinanceBilling::STATUS_PARTIAL])
            ->whereDate('due_date', '<', $date)
            ->where('remaining_amount', '>', 0)
            ->update(['status' => FinanceBilling::STATUS_OVERDUE, 'updated_at' => Carbon::now()]);

        $invoices = FinanceInvoice::query()
            ->whereIn('id', $overdueInvoiceIds)
            ->update(['status' => FinanceInvoice::STATUS_OVERDUE, 'updated_at' => Carbon::now()]);

        $overdueInvoices = FinanceInvoice::query()->whereIn('id', $overdueInvoiceIds)->get();

        foreach ($overdueInvoices as $invoice) {
            $this->notifications->invoiceOverdue($invoice);
        }

        return ['billings' => $billings, 'invoices' => $invoices];
    }

    // ==================== Internal: baris, diskon, beasiswa ====================

    /**
     * Bangun baris billing dari item paket, menerapkan diskon (§17) lalu beasiswa
     * (§18). Pengurangan dibatasi agar total baris tidak pernah negatif.
     *
     * Aturan pengurangan di-scope benar:
     *  - Persentase (dengan/tanpa fee_type) → per baris (10% = 10% tiap baris).
     *  - Nominal terikat satu fee_type → hanya baris jenis itu.
     *  - Nominal TANPA fee_type → sekali untuk SELURUH billing, didistribusikan
     *    lintas baris sampai habis (bukan dipotongkan ke tiap baris).
     *
     * @param  Collection<int, FinanceFeeItem>  $items
     * @param  Collection<int, FinanceDiscount>  $discounts
     * @param  Collection<int, FinanceScholarship>  $scholarships
     */
    private function buildBillingItems(FinanceBilling $billing, $items, $discounts, $scholarships): void
    {
        // Tahap 1: buat baris dengan pengurangan per-baris (persen + nominal terikat fee).
        $lines = [];

        foreach ($items as $item) {
            $feeType = $item->feeType;
            $qty = 1;
            $gross = round((float) $item->amount * $qty, 2);

            $discountAmount = $this->lineReduction($discounts, $gross, (int) $item->fee_type_id, FinanceDiscount::TYPE_FIXED);
            $afterDiscount = round($gross - $discountAmount, 2);

            $scholarshipAmount = $this->lineReduction($scholarships, $afterDiscount, (int) $item->fee_type_id, FinanceScholarship::TYPE_FIXED);
            $total = round($afterDiscount - $scholarshipAmount, 2);

            $line = FinanceBillingItem::create([
                'billing_id' => $billing->id,
                'fee_type_id' => $item->fee_type_id,
                'description' => $feeType?->name ?? 'Tagihan',
                'amount' => (float) $item->amount,
                'quantity' => $qty,
                'discount_amount' => $discountAmount,
                'scholarship_amount' => $scholarshipAmount,
                'total' => $total,
            ]);

            $lines[] = $line;
        }

        // Tahap 2: distribusi potongan nominal global (tanpa fee_type) — sekali per billing.
        $this->applyGlobalFixed($lines, $discounts, 'discount_amount', FinanceDiscount::TYPE_FIXED);
        $this->applyGlobalFixed($lines, $scholarships, 'scholarship_amount', FinanceScholarship::TYPE_FIXED);
    }

    /**
     * Total pengurangan per-baris dari aturan yang berlaku untuk baris ini:
     * semua aturan persentase (global maupun terikat fee) + aturan nominal yang
     * terikat fee_type baris ini. Aturan nominal global diabaikan (tahap 2).
     *
     * @param  Collection<int, FinanceDiscount|FinanceScholarship>  $rules
     */
    private function lineReduction($rules, float $base, int $feeTypeId, string $fixedType): float
    {
        $total = 0.0;

        foreach ($rules as $rule) {
            $boundToFee = $rule->fee_type_id !== null;

            if ($boundToFee && (int) $rule->fee_type_id !== $feeTypeId) {
                continue; // aturan terikat jenis lain → tidak menyentuh baris ini
            }

            $isGlobalFixed = ! $boundToFee && $rule->type === $fixedType;

            if ($isGlobalFixed) {
                continue; // ditangani applyGlobalFixed()
            }

            // Beasiswa menghormati fee_type di dalam amountFor(); diskon sudah difilter di atas.
            $total += $rule instanceof FinanceScholarship
                ? $rule->amountFor($base, $feeTypeId)
                : $rule->amountFor($base);
        }

        return round(min($total, $base), 2);
    }

    /**
     * Distribusikan potongan nominal global (tanpa fee_type) ke baris billing,
     * sekali per aturan, sampai nominalnya habis atau semua baris nol.
     *
     * @param  list<FinanceBillingItem>  $lines
     * @param  Collection<int, FinanceDiscount|FinanceScholarship>  $rules
     */
    private function applyGlobalFixed(array $lines, $rules, string $field, string $fixedType): void
    {
        foreach ($rules as $rule) {
            if ($rule->fee_type_id !== null || $rule->type !== $fixedType) {
                continue;
            }

            $remaining = (float) $rule->value; // untuk scholarship: amount

            if ($rule instanceof FinanceScholarship) {
                $remaining = (float) $rule->amount;
            }

            foreach ($lines as $line) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $available = (float) $line->total;

                if ($available <= 0.0001) {
                    continue;
                }

                $cut = round(min($remaining, $available), 2);
                $line->{$field} = round((float) $line->{$field} + $cut, 2);
                $line->total = round($available - $cut, 2);
                $line->save();

                $remaining = round($remaining - $cut, 2);
            }
        }
    }

    /** @return Collection<int, FinanceDiscount> */
    private function applicableDiscounts(Student $student)
    {
        $today = Carbon::now()->toDateString();

        return FinanceDiscount::query()
            ->active()
            ->where(fn (Builder $q) => $q->whereNull('student_id')->orWhere('student_id', $student->id))
            ->where(fn (Builder $q) => $q->whereNull('program_id')->orWhere('program_id', $student->program_id))
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->get();
    }

    /** @return Collection<int, FinanceScholarship> */
    private function applicableScholarships(Student $student)
    {
        $today = Carbon::now()->toDateString();

        return FinanceScholarship::query()
            ->where('student_id', $student->id)
            ->where('status', FinanceScholarship::STATUS_ACTIVE)
            ->whereDate('start_date', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->get();
    }

    // ==================== Internal: jurnal akrual & reversal ====================

    /**
     * Balik jurnal akrual invoice saat pembatalan (§51) — jurnal asli tak diubah,
     * pembalikan adalah jurnal baru. Hanya berlaku pada basis accrual (cash basis
     * tidak pernah membuat jurnal saat invoice terbit).
     */
    private function reverseInvoiceAccrual(FinanceInvoice $invoice, string $reason, ?User $actor): void
    {
        $journal = FinancialTransaction::query()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->where('type', FinancialTransaction::TYPE_INVOICE)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->first();

        if ($journal !== null) {
            $this->posting->reverse($journal, $reason, $actor);
        }
    }

    // ==================== Internal: helper kunci ====================

    private function referenceKey(int $studentId, int $packageId, string $periodKey): string
    {
        return sprintf('stu:%d|pkg:%d|per:%s', $studentId, $packageId, trim($periodKey));
    }

    private function carbonize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateString() : Carbon::parse($value)->toDateString();
    }
}
