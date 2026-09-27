<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpense;
use App\Models\FinancePayment;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransactionLine;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Laporan keuangan (§67, §68): satu sumber baris untuk tabel UI maupun CSV,
 * mengikuti pola modul lain (BoardingReportService) agar angka layar dan ekspor
 * tidak pernah berbeda.
 *
 * Laporan keuangan disusun dari buku besar double-entry: `accountBalances()`
 * menjumlahkan baris jurnal yang sudah diposting per akun dalam rentang tanggal,
 * lalu laporan Laba Rugi / Neraca / Neraca Lajur adalah pandangan atas agregat
 * itu. Karena setiap jurnal seimbang (§47), Neraca selalu menutup (Aset =
 * Kewajiban + Ekuitas + Laba berjalan).
 */
class FinanceReportService
{
    public const KIND_RECEIVABLES = 'receivables';

    public const KIND_PAYABLES = 'payables';

    public const KIND_AGING = 'aging';

    public const KIND_PAYMENTS = 'payments';

    public const KIND_EXPENSES = 'expenses';

    public const KIND_CASH_BANK = 'cash_bank';

    public const KIND_BUDGET = 'budget';

    public const KIND_LEDGER = 'ledger';

    public const KIND_TRIAL_BALANCE = 'trial_balance';

    public const KIND_REVENUE = 'revenue';

    public const KIND_INCOME_STATEMENT = 'income_statement';

    public const KIND_BALANCE_SHEET = 'balance_sheet';

    public const KIND_CASH_FLOW = 'cash_flow';

    /** Laporan Penagihan (§20): ditagih, tertagih, sisa + collection/overdue rate. */
    public const KIND_COLLECTION = 'collection';

    public const KIND_LABELS = [
        self::KIND_RECEIVABLES => 'Laporan Piutang (AR)',
        self::KIND_PAYABLES => 'Laporan Hutang (AP)',
        self::KIND_AGING => 'Aging Piutang',
        self::KIND_PAYMENTS => 'Laporan Penerimaan',
        self::KIND_EXPENSES => 'Laporan Pengeluaran',
        self::KIND_CASH_BANK => 'Laporan Kas & Bank',
        self::KIND_BUDGET => 'Realisasi Anggaran',
        self::KIND_LEDGER => 'Buku Besar',
        self::KIND_TRIAL_BALANCE => 'Neraca Lajur (Trial Balance)',
        self::KIND_REVENUE => 'Laporan Pendapatan',
        self::KIND_INCOME_STATEMENT => 'Laporan Laba Rugi',
        self::KIND_BALANCE_SHEET => 'Neraca (Balance Sheet)',
        self::KIND_CASH_FLOW => 'Laporan Arus Kas',
        self::KIND_COLLECTION => 'Laporan Penagihan',
    ];

    /**
     * Pengelompokan arus kas (§45). Transfer masuk kelompok internal karena
     * memindahkan uang antar rekening sekolah — bukan penerimaan maupun
     * pengeluaran; jurnal non-kas (akrual, write-off) tidak muncul di laporan
     * ini karena tidak pernah menyentuh akun kas/bank.
     */
    private const CASH_FLOW_ACTIVITY = [
        FinancialTransaction::TYPE_PAYMENT => 'operating',
        FinancialTransaction::TYPE_REVENUE => 'operating',
        FinancialTransaction::TYPE_EXPENSE => 'operating',
        FinancialTransaction::TYPE_PAYABLE => 'operating',
        FinancialTransaction::TYPE_REFUND => 'operating',
        FinancialTransaction::TYPE_ADJUSTMENT => 'other',
        FinancialTransaction::TYPE_OPENING => 'financing',
        FinancialTransaction::TYPE_TRANSFER => 'internal',
    ];

    private const CASH_FLOW_ACTIVITY_LABELS = [
        'operating' => 'Arus Kas Aktivitas Operasi',
        'investing' => 'Arus Kas Aktivitas Investasi',
        'financing' => 'Arus Kas Aktivitas Pendanaan',
        'other' => 'Arus Kas Aktivitas Lainnya',
        'internal' => 'Mutasi Internal Kas / Bank',
    ];

    public function __construct(
        protected readonly FinanceBudgetService $budgets,
        protected readonly FinanceReceivableService $receivables,
        protected readonly FinancePayableService $payables,
        protected readonly FinanceCollectionService $collections,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return self::KIND_LABELS;
    }

    public static function supports(string $kind): bool
    {
        return array_key_exists($kind, self::KIND_LABELS);
    }

    /**
     * Kolom laporan: key baris => judul kolom CSV.
     *
     * @return array<string, string>
     */
    public function headers(string $kind): array
    {
        return match ($kind) {
            self::KIND_RECEIVABLES => [
                'invoice_number' => 'No. Invoice', 'customer' => 'Customer', 'nis' => 'NIS',
                'cost_center' => 'Cost Center', 'issue_date' => 'Tgl Terbit', 'due_date' => 'Jatuh Tempo',
                'total' => 'Total', 'paid_amount' => 'Terbayar', 'remaining_amount' => 'Sisa',
                'days_overdue' => 'Hari Telat', 'bucket_label' => 'Ember Aging', 'status_label' => 'Status',
            ],
            self::KIND_AGING => [
                'customer' => 'Customer', 'nis' => 'NIS', 'invoices' => 'Jml Invoice', 'total' => 'Total Piutang',
                'current' => 'Belum Jatuh Tempo', '1_30' => '1-30 Hari', '31_60' => '31-60 Hari',
                '61_90' => '61-90 Hari', '90_plus' => '> 90 Hari',
            ],
            self::KIND_PAYABLES => [
                'vendor_invoice_number' => 'No. Invoice Vendor', 'vendor' => 'Vendor', 'cost_center' => 'Cost Center',
                'account_code' => 'Kode Akun', 'invoice_date' => 'Tgl Invoice', 'due_date' => 'Jatuh Tempo',
                'total' => 'Total', 'paid_amount' => 'Terbayar', 'remaining_amount' => 'Sisa Hutang',
                'days_overdue' => 'Hari Telat', 'bucket_label' => 'Ember Aging', 'status_label' => 'Status',
            ],
            self::KIND_PAYMENTS => [
                'date' => 'Tanggal', 'payment_number' => 'No. Pembayaran', 'customer' => 'Customer', 'nis' => 'NIS',
                'invoice_number' => 'No. Invoice', 'method_label' => 'Metode', 'amount' => 'Nominal', 'status_label' => 'Status',
            ],
            self::KIND_EXPENSES => [
                'date' => 'Tanggal', 'account_code' => 'Kode Akun', 'account_name' => 'Akun',
                'cost_center' => 'Cost Center', 'vendor' => 'Vendor', 'description' => 'Keterangan', 'amount' => 'Nominal',
            ],
            self::KIND_CASH_BANK => [
                'type' => 'Jenis', 'code' => 'Kode', 'name' => 'Nama Akun', 'coa_code' => 'Kode COA',
                'cost_center' => 'Cost Center', 'opening_balance' => 'Saldo Awal', 'ledger_balance' => 'Saldo Buku Besar',
                'current_balance' => 'Saldo Akhir', 'difference' => 'Selisih', 'status' => 'Status',
            ],
            self::KIND_BUDGET => [
                'fiscal_year' => 'Tahun', 'account_code' => 'Kode Akun', 'account_name' => 'Akun',
                'cost_center' => 'Cost Center', 'budget_amount' => 'Anggaran', 'actual' => 'Realisasi (Jurnal)',
                'variance' => 'Selisih', 'committed' => 'Komitmen', 'available' => 'Tersedia',
                'utilization' => '% Realisasi', 'cached_difference' => 'Selisih Cache',
            ],
            self::KIND_LEDGER => [
                'date' => 'Tanggal', 'transaction_number' => 'No. Jurnal', 'description' => 'Keterangan',
                'account_code' => 'Kode Akun', 'account_name' => 'Akun', 'debit' => 'Debit', 'credit' => 'Kredit',
                'opening_balance' => 'Saldo Awal', 'running_balance' => 'Saldo Berjalan (Sisi Normal)',
            ],
            self::KIND_TRIAL_BALANCE => [
                'account_code' => 'Kode Akun', 'account_name' => 'Nama Akun', 'type' => 'Tipe',
                'debit' => 'Debit', 'credit' => 'Kredit',
            ],
            self::KIND_REVENUE => [
                'account_code' => 'Kode Akun', 'account_name' => 'Nama Akun', 'recognized' => 'Pengakuan',
                'reversal' => 'Pembatalan', 'amount' => 'Pendapatan Bersih', 'share' => '% Kontribusi',
            ],
            self::KIND_INCOME_STATEMENT => [
                'section' => 'Bagian', 'account_code' => 'Kode Akun', 'account_name' => 'Nama Akun', 'amount' => 'Jumlah',
            ],
            self::KIND_BALANCE_SHEET => [
                'section' => 'Bagian', 'account_code' => 'Kode Akun', 'account_name' => 'Nama Akun', 'amount' => 'Jumlah',
            ],
            self::KIND_CASH_FLOW => [
                'section' => 'Bagian', 'activity' => 'Aktivitas', 'account' => 'Rekening',
                'description' => 'Keterangan', 'amount' => 'Jumlah',
            ],
            // Kolom penagihan (§20): angka AR + posisi workflow + aksi terakhir.
            // Baris TOTAL-nya memakai kunci yang sama, jadi CSV tidak pernah
            // punya kolom lebih banyak daripada tabelnya.
            self::KIND_COLLECTION => [
                'invoice_number' => 'No. Invoice', 'customer' => 'Customer', 'nis' => 'NIS',
                'cost_center' => 'Cost Center', 'issue_date' => 'Tgl Terbit', 'due_date' => 'Jatuh Tempo',
                'total' => 'Ditagih', 'paid_amount' => 'Tertagih', 'written_off_amount' => 'Write-off',
                'remaining_amount' => 'Sisa', 'days_overdue' => 'Hari Telat', 'bucket_label' => 'Ember Aging',
                'stage_label' => 'Tahap Penagihan', 'action_label' => 'Aksi Terakhir',
                'last_action_at' => 'Tgl Aksi Terakhir',
            ],
            default => [],
        };
    }

    /**
     * Baris laporan datar (siap tabel & CSV).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(string $kind, array $filters = [], ?User $actor = null): array
    {
        return match ($kind) {
            self::KIND_RECEIVABLES => $this->receivables->receivables($filters),
            self::KIND_PAYABLES => $this->payables->payables($filters),
            self::KIND_AGING => $this->agingRows($filters),
            self::KIND_PAYMENTS => $this->paymentRows($filters),
            self::KIND_EXPENSES => $this->expenseRows($filters),
            self::KIND_CASH_BANK => $this->cashBankRows(),
            self::KIND_BUDGET => $this->budgetRows($filters),
            self::KIND_LEDGER => $this->ledgerRows($filters),
            self::KIND_TRIAL_BALANCE => $this->trialBalanceRows($filters),
            self::KIND_REVENUE => $this->revenueRows($filters),
            self::KIND_INCOME_STATEMENT => $this->incomeStatementRows($filters),
            self::KIND_BALANCE_SHEET => $this->balanceSheetRows($filters),
            self::KIND_CASH_FLOW => $this->cashFlowRows($filters),
            self::KIND_COLLECTION => $this->collections->reportRows($filters),
            default => [],
        };
    }

    public function fileName(string $kind, array $filters = []): string
    {
        $from = filled($filters['from'] ?? null) ? (string) $filters['from'] : 'awal';
        $to = filled($filters['to'] ?? null) ? (string) $filters['to'] : Carbon::today()->toDateString();

        return 'finance-'.$kind.'-'.$from.'-'.$to.'.csv';
    }

    // ==================== Piutang / aging ====================

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function agingRows(array $filters): array
    {
        $aging = $this->receivables->aging($filters);

        return array_map(fn (array $row): array => [
            'customer' => $row['customer'],
            'nis' => $row['nis'],
            'invoices' => (int) $row['invoices'],
            'total' => (float) $row['total'],
            'current' => (float) $row[FinanceReceivableService::BUCKET_CURRENT],
            '1_30' => (float) $row[FinanceReceivableService::BUCKET_1_30],
            '31_60' => (float) $row[FinanceReceivableService::BUCKET_31_60],
            '61_90' => (float) $row[FinanceReceivableService::BUCKET_61_90],
            '90_plus' => (float) $row[FinanceReceivableService::BUCKET_90_PLUS],
        ], $aging['by_customer']);
    }

    // ==================== Penerimaan / pengeluaran ====================

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function paymentRows(array $filters): array
    {
        return FinancePayment::query()
            ->with(['student:id,full_name,nis', 'invoice:id,invoice_number'])
            ->whereIn('status', [FinancePayment::STATUS_PAID, FinancePayment::STATUS_PARTIAL])
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('paid_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('paid_at', '<=', $filters['to']))
            ->when(filled($filters['cost_center_id'] ?? null), fn ($q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['method'] ?? null), fn ($q) => $q->where('method', $filters['method']))
            ->orderBy('paid_at')
            ->orderBy('id')
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (FinancePayment $p): array => [
                'date' => $p->paid_at?->toDateString(),
                'payment_number' => $p->payment_number,
                'customer' => $p->student?->full_name ?? ($p->customer_type.' #'.$p->customer_id),
                'nis' => $p->student?->nis,
                'invoice_number' => $p->invoice?->invoice_number,
                'method_label' => FinancePayment::METHOD_LABELS[$p->method] ?? (string) $p->method,
                'amount' => (float) $p->amount,
                'status_label' => FinancePayment::STATUS_LABELS[$p->status] ?? (string) $p->status,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function expenseRows(array $filters): array
    {
        return FinanceExpense::query()
            ->with(['account:id,code,name', 'costCenter:id,name', 'vendor:id,name'])
            ->where('status', FinanceExpense::STATUS_PAID)
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('expense_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('expense_date', '<=', $filters['to']))
            ->when(filled($filters['cost_center_id'] ?? null), fn ($q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['account_id'] ?? null), fn ($q) => $q->where('account_id', (int) $filters['account_id']))
            ->orderBy('expense_date')
            ->orderBy('id')
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (FinanceExpense $e): array => [
                'date' => $e->expense_date?->toDateString(),
                'account_code' => $e->account?->code,
                'account_name' => $e->account?->name,
                'cost_center' => $e->costCenter?->name,
                'vendor' => $e->vendor?->name,
                'description' => $e->description,
                'amount' => (float) $e->amount,
            ])
            ->all();
    }

    // ==================== Kas & bank ====================

    /**
     * Saldo kas & bank. `current_balance` adalah cached balance; `ledger_balance`
     * adalah saldo yang sama jika dihitung ulang dari jurnal berstempel rekening,
     * dan `difference` menjadikan ketidakcocokan keduanya terlihat di laporan —
     * bukan tersembunyi di balik satu kolom (§1).
     *
     * @return list<array<string, mixed>>
     */
    protected function cashBankRows(): array
    {
        $reconciled = [];

        foreach ($this->subledgerReconciliation()['rows'] as $row) {
            $reconciled[$row['type'].':'.$row['id']] = $row;
        }

        $at = fn (string $type, int $id): array => $reconciled[$type.':'.$id]
            ?? ['ledger_balance' => 0.0, 'difference' => 0.0, 'journal_lines' => 0];

        $cash = FinanceCashAccount::query()->with(['account:id,code', 'costCenter:id,name'])->get()
            ->map(function ($a) use ($at): array {
                $r = $at('cash', (int) $a->id);

                return [
                    'type' => 'Kas', 'code' => $a->code, 'name' => $a->name, 'coa_code' => $a->account?->code,
                    'cost_center' => $a->costCenter?->name, 'opening_balance' => (float) $a->opening_balance,
                    'ledger_balance' => $r['ledger_balance'], 'current_balance' => (float) $a->current_balance,
                    'difference' => $r['difference'], 'status' => $a->is_active ? 'Aktif' : 'Nonaktif',
                ];
            });

        $bank = FinanceBankAccount::query()->with(['financeAccount:id,code', 'costCenter:id,name'])->get()
            ->map(function ($a) use ($at): array {
                $r = $at('bank', (int) $a->id);

                return [
                    'type' => 'Bank', 'code' => $a->code, 'name' => $a->name, 'coa_code' => $a->financeAccount?->code,
                    'cost_center' => $a->costCenter?->name, 'opening_balance' => (float) $a->opening_balance,
                    'ledger_balance' => $r['ledger_balance'], 'current_balance' => (float) $a->current_balance,
                    'difference' => $r['difference'], 'status' => $a->is_active ? 'Aktif' : 'Nonaktif',
                ];
            });

        return $cash->concat($bank)->values()->all();
    }

    // ==================== Anggaran ====================

    /**
     * Budget vs Actual (§9): Actual diambil dari jurnal posted oleh
     * `FinanceBudgetService::position()`, bukan dari kolom `used_amount` yang
     * hanya cache — laporan dan layar harus menyebut angka yang sama.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function budgetRows(array $filters): array
    {
        $report = $this->budgets->positions($filters);

        return array_slice(array_map(static fn (array $row): array => [
            'fiscal_year' => $row['fiscal_year'],
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'cost_center' => $row['cost_center'],
            'budget_amount' => $row['budget_amount'],
            'actual' => $row['actual'],
            'variance' => $row['variance'],
            'committed' => $row['committed'],
            'available' => $row['available'],
            'utilization' => $row['utilization'],
            // Cache dilaporkan apa adanya; selisihnya terhadap buku besar ikut
            // terlihat supaya perbedaan cepat ditemukan, bukan diam.
            'cached_difference' => $row['cached_difference'],
        ], $report['rows']), 0, $this->limit($filters));
    }

    // ==================== Buku besar & laporan keuangan ====================

    /**
     * Buku besar per akun (§35–§37). Saldo berjalan dihitung PER AKUN dan
     * mengikuti saldo normalnya: akun bergaya kredit (pendapatan, kewajiban,
     * ekuitas) bertambah di sisi kredit, jadi menjalankan `debit − credit` apa
     * adanya — apalagi lintas akun — menampilkan angka sisi yang salah dan
     * menjumlahkan akun yang tidak pernah seharusnya dijumlahkan. Bila filter
     * `from` dipakai, saldo sebelum tanggal itu dibawa sebagai saldo awal.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function ledgerRows(array $filters): array
    {
        $lines = $this->lineQuery($filters)
            ->when(filled($filters['account_id'] ?? null), fn ($q) => $q->where('l.account_id', (int) $filters['account_id']))
            ->select([
                'l.account_id', 'a.code', 'a.name', 'a.normal_balance',
                't.transaction_date', 't.transaction_number',
                't.description as journal_description', 'l.description as line_description',
                'l.debit', 'l.credit',
            ])
            ->orderBy('a.code')
            ->orderBy('t.transaction_date')
            ->orderBy('t.id')
            ->orderBy('l.id')
            ->limit($this->limit($filters))
            ->get();

        $opening = $this->balancesBefore($filters);

        $rows = [];
        $running = [];

        foreach ($lines as $line) {
            $accountId = (int) $line->account_id;
            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);
            $movement = $this->signedByNormal($line->normal_balance, $debit, $credit);

            $isFirstOfAccount = ! array_key_exists($accountId, $running);
            $running[$accountId] = round(($running[$accountId] ?? ($opening[$accountId] ?? 0.0)) + $movement, 2);

            $rows[] = [
                'date' => Carbon::parse($line->transaction_date)->toDateString(),
                'transaction_number' => $line->transaction_number,
                'description' => $line->line_description ?? $line->journal_description,
                'account_code' => $line->code,
                'account_name' => $line->name,
                'debit' => $debit,
                'credit' => $credit,
                'opening_balance' => $isFirstOfAccount ? round($opening[$accountId] ?? 0.0, 2) : null,
                'running_balance' => $running[$accountId],
            ];
        }

        return $rows;
    }

    /**
     * Pendapatan per akun dari buku besar yang sudah diposting (§42, §43).
     * "Pengakuan" adalah total kredit, "pembatalan" total debit (reversal,
     * restu, koreksi) — bersihnya yang masuk laporan laba rugi. Angka ini tidak
     * dibaca dari tabel invoice/payment: invoice adalah dokumen, pengakuan
     * pendapatan adalah jurnalnya.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function revenueRows(array $filters): array
    {
        $total = 0.0;
        $rows = [];

        foreach ($this->accountBalances($filters)->where('type', FinanceAccount::TYPE_REVENUE) as $b) {
            if ($b['debit'] === 0.0 && $b['credit'] === 0.0) {
                continue;
            }

            $total = round($total + $b['balance'], 2);

            $rows[] = [
                'account_code' => $b['code'],
                'account_name' => $b['name'],
                'recognized' => $b['credit'],
                'reversal' => $b['debit'],
                'amount' => $b['balance'],
                'share' => 0.0,
            ];
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['share'] = $total != 0.0 ? round($row['amount'] / $total * 100, 2) : 0.0;
        }

        $rows[] = [
            'account_code' => '',
            'account_name' => 'TOTAL PENDAPATAN',
            'recognized' => round(array_sum(array_column($rows, 'recognized')), 2),
            'reversal' => round(array_sum(array_column($rows, 'reversal')), 2),
            'amount' => $total,
            'share' => $total != 0.0 ? 100.0 : 0.0,
        ];

        return $rows;
    }

    /**
     * Arus kas (§45): Saldo Awal + Masuk − Keluar = Saldo Akhir, dikelompokkan
     * per aktivitas. Sumber angkanya mutasi akun kas/bank di buku besar, sehingga
     * saldo akhir laporan ini harus selalu sama dengan saldo subledger kas/bank
     * (laporan `cash_bank`) — kalau berbeda, ada jurnal yang tidak nyambung.
     *
     * Transfer kas↔bank masuk kelompok internal: uang berpindah antar rekening
     * sekolah, bukan penerimaan atau pengeluaran (§29).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function cashFlowRows(array $filters): array
    {
        $accountIds = $this->cashBankAccountIds();

        if ($accountIds === []) {
            return [];
        }

        $opening = round(array_sum(array_intersect_key(
            $this->balancesBefore($filters),
            array_flip($accountIds),
        )), 2);

        $movements = $this->lineQuery($filters)
            ->whereIn('l.account_id', $accountIds)
            ->select(
                't.id as transaction_id', 't.type', 'l.account_id', 'a.code', 'a.name',
                'a.normal_balance', 'l.debit', 'l.credit',
            )
            ->get()
            ->groupBy('transaction_id');

        $groups = [];

        foreach ($movements as $lines) {
            // Lebih dari satu baris kas/bank dalam satu jurnal = uang hanya
            // berpindah rekening (transfer). Keduanya dicatat sebagai mutasi
            // internal: totalnya nol, jadi tidak pernah diklaim sebagai
            // penerimaan atau pengeluaran (§29).
            if ($lines->count() > 1) {
                foreach ($lines as $line) {
                    $signed = $this->signedByNormal($line->normal_balance, (float) $line->debit, (float) $line->credit);
                    $key = 'internal|'.$line->account_id.'|'.($signed >= 0 ? 'in' : 'out');

                    $groups[$key] ??= [
                        'activity' => 'internal',
                        'account' => $line->code.' — '.$line->name,
                        'description' => 'Mutasi antar rekening kas/bank',
                        'amount' => 0.0,
                    ];
                    $groups[$key]['amount'] = round($groups[$key]['amount'] + $signed, 2);
                }

                continue;
            }

            $line = $lines[0];
            $signed = $this->signedByNormal($line->normal_balance, (float) $line->debit, (float) $line->credit);

            if ($signed === 0.0) {
                continue;
            }

            $activity = self::CASH_FLOW_ACTIVITY[$line->type] ?? 'other';
            $key = $activity.'|'.$line->account_id.'|'.($signed >= 0 ? 'in' : 'out');

            $groups[$key] ??= [
                'activity' => $activity,
                'account' => $line->code.' — '.$line->name,
                'description' => FinancialTransaction::TYPE_LABELS[$line->type] ?? (string) $line->type,
                'amount' => 0.0,
            ];
            $groups[$key]['amount'] = round($groups[$key]['amount'] + $signed, 2);
        }

        $order = array_flip(['operating', 'investing', 'financing', 'other', 'internal']);
        usort($groups, fn (array $a, array $b): int => [$order[$a['activity']], $a['account'], $a['description']]
            <=> [$order[$b['activity']], $b['account'], $b['description']]);

        $rows = [[
            'section' => 'Saldo Awal',
            'activity' => '',
            'account' => 'Seluruh kas & bank',
            'description' => filled($filters['from'] ?? null)
                ? 'Saldo sebelum '.Carbon::parse((string) $filters['from'])->toDateString()
                : 'Saldo awal pembukuan',
            'amount' => $opening,
        ]];

        $netChange = 0.0;
        $subtotal = [];

        foreach ($groups as $group) {
            $rows[] = [
                'section' => self::CASH_FLOW_ACTIVITY_LABELS[$group['activity']],
                'activity' => $group['activity'],
                'account' => $group['account'],
                'description' => $group['description'].' ('.($group['amount'] >= 0 ? 'masuk' : 'keluar').')',
                'amount' => $group['amount'],
            ];

            $subtotal[$group['activity']] = round(($subtotal[$group['activity']] ?? 0.0) + $group['amount'], 2);

            if ($group['activity'] !== 'internal') {
                $netChange = round($netChange + $group['amount'], 2);
            }
        }

        foreach (['operating', 'investing', 'financing', 'other', 'internal'] as $activity) {
            if (! array_key_exists($activity, $subtotal)) {
                continue;
            }

            $rows[] = [
                'section' => self::CASH_FLOW_ACTIVITY_LABELS[$activity],
                'activity' => $activity,
                'account' => '',
                'description' => 'Subtotal '.self::CASH_FLOW_ACTIVITY_LABELS[$activity],
                'amount' => $subtotal[$activity],
            ];
        }

        $rows[] = ['section' => 'Ringkasan', 'activity' => '', 'account' => '', 'description' => 'Arus kas bersih (masuk − keluar)', 'amount' => $netChange];
        $rows[] = ['section' => 'Ringkasan', 'activity' => '', 'account' => '', 'description' => 'Saldo Akhir Kas & Bank', 'amount' => round($opening + $netChange, 2)];

        return $rows;
    }

    /**
     * Saldo per akun (sisi normal) sebelum tanggal `from`. Kosong bila tidak
     * ada batas bawah — laporan mulai dari pembukuan paling awal.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, float>
     */
    protected function balancesBefore(array $filters): array
    {
        if (! filled($filters['from'] ?? null)) {
            return [];
        }

        return DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->join('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('t.status', FinancialTransaction::LEDGER_STATUSES)
            ->whereDate('t.transaction_date', '<', $filters['from'])
            ->when(filled($filters['cost_center_id'] ?? null), fn ($q) => $q->where('t.cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['account_id'] ?? null), fn ($q) => $q->where('l.account_id', (int) $filters['account_id']))
            ->groupBy('a.id', 'a.normal_balance')
            ->select(
                'a.id as account_id', 'a.normal_balance',
                DB::raw('COALESCE(SUM(l.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(l.credit), 0) as credit'),
            )
            ->get()
            ->mapWithKeys(fn ($r): array => [
                (int) $r->account_id => $this->signedByNormal($r->normal_balance, (float) $r->debit, (float) $r->credit),
            ])
            ->all();
    }

    /**
     * Akun COA yang ditopang subledger kas/bank. "Kas" untuk arus kas cukup
     * didefinisikan sekali: akun yang punya rekening kas atau rekening bank.
     *
     * @return list<int>
     */
    protected function cashBankAccountIds(): array
    {
        return FinanceCashAccount::query()
            ->pluck('account_id')
            ->merge(FinanceBankAccount::query()->pluck('finance_account_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Arah sebuah baris dilihat dari saldo normal akun: bergaya debit bertambah
     * oleh debit, bergaya kredit bertambah oleh kredit (§37).
     */
    protected function signedByNormal(string $normalBalance, float $debit, float $credit): float
    {
        return $normalBalance === 'debit' ? round($debit - $credit, 2) : round($credit - $debit, 2);
    }

    /**
     * Saldo satu akun untuk API `GET /finance/accounts/{account}/balance` (§47).
     * Sama persis dengan agregasi neraca lajur — hanya dibuka dua hal yang
     * dibutuhkan di layar akun: saldo awal sebelum rentang (supaya `balance`
     * adalah posisi, bukan mutasi) dan rekening kas/bank konkret yang menaungi
     * akun COA itu, sehingga subledger bisa dicek silang dengan buku besar di
     * satu respons.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function accountBalance(int $accountId, array $filters = []): ?array
    {
        $account = FinanceAccount::query()->find($accountId);

        if ($account === null) {
            return null;
        }

        $movement = $this->accountBalances($filters)
            ->first(fn (array $row): bool => $row['account_id'] === $accountId);

        $opening = $this->balancesBefore($filters)[$accountId] ?? 0.0;
        $periodMovement = $movement['balance'] ?? 0.0;

        $subledger = $this->subledgerOf($accountId);

        return [
            'account_id' => (int) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'type_label' => FinanceAccount::TYPE_LABELS[$account->type] ?? $account->type,
            'normal_balance' => $account->normal_balance,
            'debit' => $movement['debit'] ?? 0.0,
            'credit' => $movement['credit'] ?? 0.0,
            'opening_balance' => round($opening, 2),
            'period_movement' => round($periodMovement, 2),
            'balance' => round($opening + $periodMovement, 2),
            'subledger' => $subledger,
            'subledger_balance' => round(array_sum(array_column($subledger, 'current_balance')), 2),
        ];
    }

    /**
     * Rekening kas/bank konkret yang dinaungi satu akun COA, lengkap dengan
     * saldo hasil rekonstruksi buku besar (§1). `difference` bukan nol berarti
     * cached balance dan jurnal tidak lagi sepakat — itu bug yang harus dicari,
     * bukan selisih yang dibiarkan.
     *
     * @return list<array<string, mixed>>
     */
    public function subledgerOf(int $accountId): array
    {
        $amounts = $this->subledgerLedgerAmounts();

        $cash = FinanceCashAccount::query()
            ->where('account_id', $accountId)
            ->get()
            ->map(fn (FinanceCashAccount $a): array => $this->subledgerRow('cash', $a, $amounts));

        $bank = FinanceBankAccount::query()
            ->where('finance_account_id', $accountId)
            ->get()
            ->map(fn (FinanceBankAccount $a): array => $this->subledgerRow('bank', $a, $amounts));

        return $cash->concat($bank)->values()->all();
    }

    /**
     * Rekonsiliasi internal kas & bank (§1): cached balance vs saldo yang sama
     * jika dihitung ulang dari baris jurnal berstempel rekening.
     *
     * `opening + movement = closing` diuji di sini untuk SETIAP rekening. Yang
     * dibaca tetap jurnal (bukan kolom `current_balance`), sehingga jawaban
     * "berapa uang di bank X?" bisa ditelusuri baris per baris — dan cached
     * balance terbukti menjadi optimasi, bukan sumber kebenaran tersendiri.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>, in_agreement: bool}
     */
    public function subledgerReconciliation(): array
    {
        $amounts = $this->subledgerLedgerAmounts();

        $rows = FinanceCashAccount::query()->get()
            ->map(fn (FinanceCashAccount $a): array => $this->subledgerRow('cash', $a, $amounts))
            ->concat(FinanceBankAccount::query()->get()
                ->map(fn (FinanceBankAccount $a): array => $this->subledgerRow('bank', $a, $amounts)))
            ->values()
            ->all();

        $cached = 0.0;
        $ledger = 0.0;
        $opening = 0.0;
        $movement = 0.0;

        foreach ($rows as $row) {
            $cached = round($cached + $row['current_balance'], 2);
            $ledger = round($ledger + $row['ledger_balance'], 2);
            $opening = round($opening + $row['opening_balance'], 2);
            $movement = round($movement + $row['ledger_movement'], 2);
        }

        return [
            'rows' => $rows,
            'totals' => [
                'opening_balance' => $opening,
                'ledger_movement' => $movement,
                'ledger_balance' => $ledger,
                'current_balance' => $cached,
                'difference' => round($cached - $ledger, 2),
            ],
            // Σ cached == Σ ledger adalah invarian yang bisa dipercaya; selisih
            // per rekening boleh ada hanya bila ada baris tak ter-stempel, dan
            // itu justru yang ingin kita lihat.
            'in_agreement' => abs($cached - $ledger) <= 0.005,
        ];
    }

    /**
     * Pergerakan kas/bank satu rekening, dari baris jurnal yang distempel
     * rekening tersebut (§1: saldo harus dapat dihitung ulang). Barisnya adalah
     * jurnal — jadi setiap angka di layar ini bisa diklik ke §38.
     *
     * @return array{account: array<string, mixed>, rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function subledgerMovements(string $type, int $id, array $filters = []): array
    {
        $model = match ($type) {
            'cash' => FinanceCashAccount::class,
            'bank' => FinanceBankAccount::class,
            default => null,
        };

        if ($model === null) {
            throw ValidationException::withMessages(['type' => 'Tipe rekening harus cash atau bank.']);
        }

        /** @var FinanceCashAccount|FinanceBankAccount $account */
        $account = $model::query()->whereKey($id)->firstOrFail();
        $alias = FinancialTransactionLine::subledgerTypeForAccount($account);

        $query = FinancialTransactionLine::query()
            ->from('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->leftJoin('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.subledger_type', $alias)
            ->where('l.subledger_id', (int) $account->id)
            ->whereIn('t.status', FinancialTransaction::LEDGER_STATUSES)
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('t.transaction_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('t.transaction_date', '<=', $filters['to']))
            ->orderBy('t.transaction_date')
            ->orderBy('t.id')
            ->select('l.id', 'l.debit', 'l.credit', 'l.description', 'l.reference', 'l.cost_center_id',
                't.transaction_number', 't.transaction_date', 't.type', 't.reference_type', 't.reference_id',
                'a.code as account_code', 'a.name as account_name')
            ->limit($this->limit($filters));

        $rows = [];
        // Jurnal opening balance ikut berstempel, jadi saldo berjalan mulai dari
        // nol — menambahnya dengan `opening_balance` akan menghitungnya dua kali.
        $running = 0.0;
        $debit = 0.0;
        $credit = 0.0;

        foreach ($query->get() as $row) {
            $lineDebit = round((float) $row->debit, 2);
            $lineCredit = round((float) $row->credit, 2);
            $debit = round($debit + $lineDebit, 2);
            $credit = round($credit + $lineCredit, 2);
            $running = round($running + $lineDebit - $lineCredit, 2);

            $rows[] = [
                'id' => (int) $row->id,
                'date' => (string) $row->transaction_date,
                'transaction_number' => $row->transaction_number,
                'type' => $row->type,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'description' => $row->description,
                'reference' => $row->reference,
                'debit' => $lineDebit,
                'credit' => $lineCredit,
                'running_balance' => $running,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id === null ? null : (int) $row->reference_id,
            ];
        }

        return [
            // Saldo rekening selalu posisi penuh (dari seluruh jurnal), supaya
            // `totals` di bawahnya dibaca sebagai mutasi rentang yang terfilter.
            'account' => $this->subledgerRow($type, $account, $this->subledgerLedgerAmounts()),
            'rows' => $rows,
            'totals' => [
                'lines' => count($rows),
                'debit' => $debit,
                'credit' => $credit,
                'movement' => round($debit - $credit, 2),
                'closing_balance' => $running,
            ],
        ];
    }

    /**
     * Σ debit/credit baris jurnal per rekening, dari stempel subledger.
     *
     * Key `"cash:1" => …` supaya satu query melayani semua rekening; jumlah
     * baris jurnal jauh lebih banyak daripada rekeningnya.
     *
     * @return array<string, array{debit: float, credit: float, lines: int}>
     */
    public function subledgerLedgerAmounts(): array
    {
        $rows = FinancialTransactionLine::query()
            ->from('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->whereNotNull('l.subledger_type')
            ->whereIn('t.status', FinancialTransaction::LEDGER_STATUSES)
            ->groupBy('l.subledger_type', 'l.subledger_id')
            ->selectRaw('l.subledger_type, l.subledger_id, SUM(l.debit) as total_debit, SUM(l.credit) as total_credit, COUNT(*) as lines')
            ->get();

        $amounts = [];

        foreach ($rows as $row) {
            $alias = (string) $row->subledger_type;
            $type = $alias === FinancialTransactionLine::SUBLEDGER_BANK ? 'bank' : 'cash';
            $amounts[$type.':'.(int) $row->subledger_id] = [
                'debit' => round((float) $row->total_debit, 2),
                'credit' => round((float) $row->total_credit, 2),
                'lines' => (int) $row->lines,
            ];
        }

        return $amounts;
    }

    /**
     * Satu baris rekening: cached balance vs saldo buku besar + selisihnya.
     *
     * @param  array<string, array{debit: float, credit: float, lines: int}>  $amounts
     * @return array<string, mixed>
     */
    protected function subledgerRow(string $type, FinanceCashAccount|FinanceBankAccount $account, array $amounts): array
    {
        $entry = $amounts[$type.':'.$account->id] ?? ['debit' => 0.0, 'credit' => 0.0, 'lines' => 0];
        // Kas/bank adalah aset → sisi normal debit. Saldo buku besar dihitung dari
        // jurnalnya langsung, termasuk jurnal opening balance (makanya `opening`
        // di sini hanya informasi, bukan komponen penjumlahan).
        $movement = round($entry['debit'] - $entry['credit'], 2);

        return [
            'type' => $type,
            'id' => (int) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'is_active' => (bool) $account->is_active,
            'opening_balance' => round((float) $account->opening_balance, 2),
            'current_balance' => round((float) $account->current_balance, 2),
            'ledger_movement' => $movement,
            'ledger_balance' => $movement,
            'journal_lines' => $entry['lines'],
            'difference' => round((float) $account->current_balance - $movement, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function trialBalanceRows(array $filters): array
    {
        $balances = $this->accountBalances($filters);

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($balances as $b) {
            $debit = $b['net'] > 0 ? $b['net'] : 0.0;
            $credit = $b['net'] < 0 ? -$b['net'] : 0.0;

            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $totalDebit = round($totalDebit + $debit, 2);
            $totalCredit = round($totalCredit + $credit, 2);

            $rows[] = [
                'account_code' => $b['code'],
                'account_name' => $b['name'],
                'type' => FinanceAccount::TYPE_LABELS[$b['type']] ?? (string) $b['type'],
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        $rows[] = [
            'account_code' => '',
            'account_name' => 'TOTAL',
            'type' => '',
            'debit' => $totalDebit,
            'credit' => $totalCredit,
        ];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function incomeStatementRows(array $filters): array
    {
        $balances = $this->accountBalances($filters);

        $rows = [];
        $totalRevenue = 0.0;
        $totalExpense = 0.0;

        foreach ($balances->where('type', FinanceAccount::TYPE_REVENUE) as $b) {
            if ($b['balance'] === 0.0) {
                continue;
            }

            $totalRevenue = round($totalRevenue + $b['balance'], 2);
            $rows[] = ['section' => 'Pendapatan', 'account_code' => $b['code'], 'account_name' => $b['name'], 'amount' => $b['balance']];
        }

        $rows[] = ['section' => 'Pendapatan', 'account_code' => '', 'account_name' => 'Total Pendapatan', 'amount' => $totalRevenue];

        foreach ($balances->where('type', FinanceAccount::TYPE_EXPENSE) as $b) {
            if ($b['balance'] === 0.0) {
                continue;
            }

            $totalExpense = round($totalExpense + $b['balance'], 2);
            $rows[] = ['section' => 'Beban', 'account_code' => $b['code'], 'account_name' => $b['name'], 'amount' => $b['balance']];
        }

        $rows[] = ['section' => 'Beban', 'account_code' => '', 'account_name' => 'Total Beban', 'amount' => $totalExpense];

        $netIncome = round($totalRevenue - $totalExpense, 2);
        $rows[] = ['section' => 'Laba/Rugi', 'account_code' => '', 'account_name' => 'Laba (Rugi) Bersih', 'amount' => $netIncome];

        return $rows;
    }

    /**
     * Neraca per tanggal `to` (default hari ini). Laba berjalan tahun ini dilipat
     * ke bagian ekuitas sehingga Aset = Kewajiban + Ekuitas selalu menutup.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function balanceSheetRows(array $filters): array
    {
        // Neraca = posisi s.d. tanggal `to` (dari awal pembukuan).
        $asOf = filled($filters['to'] ?? null) ? (string) $filters['to'] : Carbon::today()->toDateString();
        $balances = $this->accountBalances(['to' => $asOf, 'cost_center_id' => $filters['cost_center_id'] ?? null]);

        $rows = [];
        $totalAsset = 0.0;
        $totalLiability = 0.0;
        $totalEquity = 0.0;

        foreach ($balances->where('type', FinanceAccount::TYPE_ASSET) as $b) {
            if ($b['balance'] === 0.0) {
                continue;
            }

            $totalAsset = round($totalAsset + $b['balance'], 2);
            $rows[] = ['section' => 'Aset', 'account_code' => $b['code'], 'account_name' => $b['name'], 'amount' => $b['balance']];
        }

        $rows[] = ['section' => 'Aset', 'account_code' => '', 'account_name' => 'TOTAL ASET', 'amount' => $totalAsset];

        foreach ($balances->where('type', FinanceAccount::TYPE_LIABILITY) as $b) {
            if ($b['balance'] === 0.0) {
                continue;
            }

            $totalLiability = round($totalLiability + $b['balance'], 2);
            $rows[] = ['section' => 'Kewajiban', 'account_code' => $b['code'], 'account_name' => $b['name'], 'amount' => $b['balance']];
        }

        $rows[] = ['section' => 'Kewajiban', 'account_code' => '', 'account_name' => 'TOTAL KEWAJIBAN', 'amount' => $totalLiability];

        foreach ($balances->where('type', FinanceAccount::TYPE_EQUITY) as $b) {
            if ($b['balance'] === 0.0) {
                continue;
            }

            $totalEquity = round($totalEquity + $b['balance'], 2);
            $rows[] = ['section' => 'Ekuitas', 'account_code' => $b['code'], 'account_name' => $b['name'], 'amount' => $b['balance']];
        }

        // Laba (rugi) berjalan = pendapatan - beban s.d. tanggal neraca.
        $revenue = $balances->where('type', FinanceAccount::TYPE_REVENUE)->sum('balance');
        $expense = $balances->where('type', FinanceAccount::TYPE_EXPENSE)->sum('balance');
        $currentEarnings = round((float) $revenue - (float) $expense, 2);

        if ($currentEarnings !== 0.0) {
            $totalEquity = round($totalEquity + $currentEarnings, 2);
            $rows[] = ['section' => 'Ekuitas', 'account_code' => '', 'account_name' => 'Laba (Rugi) Berjalan', 'amount' => $currentEarnings];
        }

        $rows[] = ['section' => 'Ekuitas', 'account_code' => '', 'account_name' => 'TOTAL EKUITAS', 'amount' => $totalEquity];

        $rows[] = [
            'section' => 'Ringkasan', 'account_code' => '', 'account_name' => 'TOTAL KEWAJIBAN + EKUITAS',
            'amount' => round($totalLiability + $totalEquity, 2),
        ];

        return $rows;
    }

    /**
     * Agregat saldo per akun dari baris jurnal yang sudah diposting dalam rentang
     * `from`..`to` (opsional cost center). `net = debit - credit`; `balance`
     * disesuaikan sisi saldo normal akun (asset/expense = debit, lainnya = credit).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function accountBalances(array $filters): Collection
    {
        return $this->lineQuery($filters)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
            ->select(
                'a.id as account_id', 'a.code', 'a.name', 'a.type', 'a.normal_balance',
                DB::raw('COALESCE(SUM(l.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(l.credit), 0) as credit'),
            )
            ->orderBy('a.code')
            ->get()
            ->map(function ($r): array {
                $debit = round((float) $r->debit, 2);
                $credit = round((float) $r->credit, 2);
                $net = round($debit - $credit, 2);

                return [
                    'account_id' => (int) $r->account_id,
                    'code' => $r->code,
                    'name' => $r->name,
                    'type' => $r->type,
                    'normal_balance' => $r->normal_balance,
                    'debit' => $debit,
                    'credit' => $credit,
                    'net' => $net,
                    'balance' => $this->signedByNormal($r->normal_balance, $debit, $credit),
                ];
            });
    }

    /**
     * Query dasar baris jurnal untuk buku besar & laporan. Jurnal `reversed`
     * ikut dibaca: ia dinolkan oleh jurnal pembaliknya di ledger yang sama,
     * jadi jejak aslinya tetap terlihat (§51).
     *
     * @param  array<string, mixed>  $filters
     */
    protected function lineQuery(array $filters): Builder
    {
        return DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->join('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('t.status', FinancialTransaction::LEDGER_STATUSES)
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('t.transaction_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('t.transaction_date', '<=', $filters['to']))
            ->when(filled($filters['cost_center_id'] ?? null), fn ($q) => $q->where('t.cost_center_id', (int) $filters['cost_center_id']));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function limit(array $filters): int
    {
        return max(1, min(20000, (int) ($filters['limit'] ?? 5000)));
    }
}
