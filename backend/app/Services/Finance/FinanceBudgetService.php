<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceExpenseRequest;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anggaran (§8), Budget vs Actual (§9), dan budget control (§10).
 *
 * Status berjalan satu arah kecuali penolakan: draft → submitted → approved →
 * active → closed, dengan `cancelled` untuk anggaran yang belum sempat berlaku
 * (§8). Pemisahan pengaju vs penyetuju ditegakkan di sini (§3):
 * `finance.budget.manage` boleh mengajukan, `finance.budget.approve` boleh
 * menyetujui — dan seorang pengguna tidak boleh menyetujui anggaran yang ia
 * ajukan sendiri.
 *
 * **Actual diambil dari buku besar, bukan dari tabel invoice/payment (§9).**
 * `position()` menjumlah baris jurnal berstatus ledger (posted + reversed, supaya
 * pembalikan saling meniadakan) pada akun anggaran dalam jendela tahun fiskal —
 * dihitung menurut saldo normal akun, jadi akun pendapatan tidak tampil negatif
 * hanya karena ia bertambah di sisi kredit. Kolom `used_amount` yang dinaikkan
 * saat pembayaran dipertahankan sebagai optimisasi, dan selisihnya terhadap
 * buku besar ikut dilaporkan: dua angka yang keduanya disebut "realisasi" tanpa
 * penjelasan lebih menyesatkan daripada satu angka yang bisa dihitung ulang.
 *
 * **Committed bukan actual (§10).** Pengajuan yang masih dalam alur persetujuan
 * memotong `available`, tetapi tidak pernah menyentuh realisasi — uang belum
 * keluar. Available = Budget − Committed − Actual, dan ambang perlakuan
 * kelebihan (none|warning|block|allow_with_approval) ada di konfigurasi.
 */
class FinanceBudgetService
{
    /** Kebijakan budget control (§10). */
    public const CONTROL_NONE = 'none';

    public const CONTROL_WARNING = 'warning';

    public const CONTROL_BLOCK = 'block';

    public const CONTROL_APPROVAL = 'allow_with_approval';

    /** Hasil pemeriksaan anggaran sebuah nominal (§10). */
    public const CHECK_OK = 'ok';

    public const CHECK_NO_BUDGET = 'no_budget';

    public const CHECK_WARNING = 'warning';

    public const CHECK_EXCEEDED = 'exceeded';

    /** Status pengajuan yang sudah memakan komitmen tapi belum menjadi actual. */
    public const COMMITTED_STATUSES = [
        FinanceExpenseRequest::STATUS_SUBMITTED,
        FinanceExpenseRequest::STATUS_UNDER_REVIEW,
        FinanceExpenseRequest::STATUS_APPROVED,
    ];

    public function __construct(private readonly FinanceSettingsService $settings) {}

    /** Nilai konfigurasi budget control (§10); nilai asing diperlakukan sebagai none. */
    public function controlMode(): string
    {
        $mode = (string) $this->settings->get('budget_control', self::CONTROL_NONE);

        return in_array($mode, [self::CONTROL_WARNING, self::CONTROL_BLOCK, self::CONTROL_APPROVAL], true)
            ? $mode
            : self::CONTROL_NONE;
    }

    /** Ajukan anggaran draft/ditolak untuk disetujui. */
    public function submit(FinanceBudget $budget, ?User $actor): FinanceBudget
    {
        return DB::transaction(function () use ($budget, $actor): FinanceBudget {
            $fresh = $this->lock($budget);

            FinanceStatusMachine::assert($fresh, FinanceBudget::STATUS_SUBMITTED);

            $fresh->status = FinanceBudget::STATUS_SUBMITTED;
            $fresh->submitted_by = $actor?->id;
            $fresh->reject_reason = null;
            $fresh->save();

            return $fresh;
        });
    }

    /** Setujui anggaran yang sedang diajukan → approved lalu aktif. */
    public function approve(FinanceBudget $budget, ?User $actor): FinanceBudget
    {
        return DB::transaction(function () use ($budget, $actor): FinanceBudget {
            $fresh = $this->lock($budget);

            FinanceStatusMachine::assert($fresh, FinanceBudget::STATUS_ACTIVE);

            if ($actor !== null && (int) $fresh->submitted_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approved_by' => 'Pengaju tidak boleh menyetujui anggarannya sendiri.',
                ]);
            }

            $fresh->status = FinanceBudget::STATUS_ACTIVE;
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->reject_reason = null;
            $fresh->save();

            return $fresh;
        });
    }

    /** Tolak anggaran yang sedang diajukan, wajib disertai alasan. */
    public function reject(FinanceBudget $budget, string $reason, ?User $actor): FinanceBudget
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reject_reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($budget, $reason): FinanceBudget {
            $fresh = $this->lock($budget);

            FinanceStatusMachine::assert($fresh, FinanceBudget::STATUS_REJECTED);

            $fresh->status = FinanceBudget::STATUS_REJECTED;
            $fresh->reject_reason = $reason;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Tutup anggaran yang sudah berlaku (§8 CLOSED): tahun anggaran selesai,
     * realisasinya tetap terbaca di Budget vs Actual tapi tidak ada komitmen baru
     * yang boleh menempel padanya — `matchingBudget()` hanya melihat approved/active.
     */
    public function close(FinanceBudget $budget, ?User $actor): FinanceBudget
    {
        return DB::transaction(function () use ($budget, $actor): FinanceBudget {
            $fresh = $this->lock($budget);

            FinanceStatusMachine::assert($fresh, FinanceBudget::STATUS_CLOSED);

            $fresh->status = FinanceBudget::STATUS_CLOSED;
            $fresh->closed_by = $actor?->id;
            $fresh->closed_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Batalkan anggaran yang belum pernah berlaku (§8 CANCELLED). Anggaran yang
     * sudah disetujui tidak dibatalkan tapi ditutup: realisasinya sudah tercatat
     * di buku besar, dan menghapus anggaran dari sejarah tidak menghapus
     * pengeluarannya.
     */
    public function cancel(FinanceBudget $budget, string $reason, ?User $actor): FinanceBudget
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['cancel_reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($budget, $reason, $actor): FinanceBudget {
            $fresh = $this->lock($budget);

            FinanceStatusMachine::assert($fresh, FinanceBudget::STATUS_CANCELLED);

            $fresh->status = FinanceBudget::STATUS_CANCELLED;
            $fresh->cancel_reason = $reason;
            $fresh->cancelled_by = $actor?->id;
            $fresh->cancelled_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Tambah realisasi (`used_amount`) saat expense/PO diposting. Dipanggil oleh
     * layanan pengeluaran, bukan endpoint langsung. Boleh membuat used > budget
     * (overrun) — itu fakta yang harus terlihat di laporan, bukan disembunyikan.
     */
    public function addUsage(int $budgetId, float $amount): void
    {
        DB::transaction(function () use ($budgetId, $amount): void {
            $budget = FinanceBudget::query()->whereKey($budgetId)->lockForUpdate()->first();

            if ($budget === null) {
                return;
            }

            $budget->used_amount = round((float) $budget->used_amount + $amount, 2);
            $budget->save();
        });
    }

    /**
     * Naikkan realisasi anggaran yang cocok dengan (cost center, akun, tahun
     * fiskal) dari sebuah pengeluaran. Best-effort: bila tidak ada anggaran aktif
     * yang cocok, pengeluaran tetap sah — hanya tak terpetakan ke budget (fakta
     * yang terlihat di Budget vs Actual §9, bukan error).
     */
    public function addUsageFor(int $costCenterId, int $accountId, float $amount, Carbon|string|null $date = null): void
    {
        $budget = $this->matchingBudget($accountId, $costCenterId, $date);

        if ($budget === null) {
            return;
        }

        $this->addUsage((int) $budget->id, $amount);
    }

    // ==================== Budget vs Actual (§9) ====================

    /**
     * Posisi satu anggaran: actual dihitung dari buku besar, commitment dari
     * pengajuan yang masih dalam alur, dan `used_amount` dilaporkan apa adanya
     * supaya selisih antara cache dan buku besar terlihat, bukan tersamar.
     *
     * @return array<string, mixed>
     */
    public function position(FinanceBudget $budget): array
    {
        $budget->loadMissing('account:id,code,name,normal_balance', 'costCenter:id,name');

        $planned = round((float) $budget->budget_amount, 2);
        $actual = $this->ledgerActual($budget);
        $committed = $this->committedAmount($budget);
        $cached = round((float) $budget->used_amount, 2);

        return [
            'id' => (int) $budget->id,
            'fiscal_year' => (int) $budget->fiscal_year,
            'period' => $budget->period,
            'account_id' => (int) $budget->account_id,
            'account_code' => $budget->account?->code,
            'account_name' => $budget->account?->name,
            'cost_center_id' => $budget->cost_center_id !== null ? (int) $budget->cost_center_id : null,
            'cost_center' => $budget->costCenter?->name,
            'status' => $budget->status,
            'budget_amount' => $planned,
            // Sumber kebenaran actual adalah jurnal posted (§9).
            'actual' => $actual,
            'committed' => $committed,
            'available' => round($planned - $actual - $committed, 2),
            'variance' => round($planned - $actual, 2),
            'utilization' => $planned > 0 ? round($actual / $planned * 100, 2) : 0.0,
            'over_budget' => $actual > $planned,
            // `used_amount` hanya cache: selisihnya wajib tampak, tidak didiamkan.
            'used_amount' => $cached,
            'cached_difference' => round($cached - $actual, 2),
            'in_agreement' => abs($cached - $actual) <= 0.005,
        ];
    }

    /**
     * Laporan Budget vs Actual (§9) dengan filter tahun fiskal, periode, akun, dan
     * cost center (unit). `totals` memakai rumus dashboard §9: Total Budget, Total
     * Actual, Remaining Budget, Over Budget, Utilization.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float|int>}
     */
    public function positions(array $filters = []): array
    {
        $budgets = FinanceBudget::query()
            ->with(['account:id,code,name,normal_balance', 'costCenter:id,name'])
            ->when(filled($filters['fiscal_year'] ?? null), fn ($q) => $q->where('fiscal_year', (int) $filters['fiscal_year']))
            ->when(filled($filters['account_id'] ?? null), fn ($q) => $q->where('account_id', (int) $filters['account_id']))
            ->when(filled($filters['cost_center_id'] ?? null), fn ($q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['period'] ?? null), fn ($q) => $q->where('period', (string) $filters['period']))
            ->when(filled($filters['status'] ?? null),
                fn ($q) => $q->where('status', (string) $filters['status']),
                // Tanpa filter status: hanya anggaran berlaku yang punya arti realisasi.
                fn ($q) => $q->active())
            ->orderBy('fiscal_year')
            ->orderBy('id')
            ->get();

        $rows = $budgets->map(fn (FinanceBudget $budget): array => $this->position($budget))->all();

        $planned = round(array_sum(array_column($rows, 'budget_amount')), 2);
        $actual = round(array_sum(array_column($rows, 'actual')), 2);
        $committed = round(array_sum(array_column($rows, 'committed')), 2);

        return [
            'rows' => $rows,
            'totals' => [
                'budget' => $planned,
                'actual' => $actual,
                'committed' => $committed,
                'variance' => round($planned - $actual, 2),
                'remaining' => round($planned - $actual - $committed, 2),
                'utilization' => $planned > 0 ? round($actual / $planned * 100, 2) : 0.0,
                'over_budget' => count(array_filter($rows, fn (array $row): bool => $row['over_budget'])),
                'not_in_agreement' => count(array_filter($rows, fn (array $row): bool => ! $row['in_agreement'])),
            ],
        ];
    }

    /**
     * Actual sebuah anggaran dari baris jurnal posted/reversed pada akun budget
     * dalam jendela tahun (atau periode) anggaran itu (§9).
     *
     * Sisi dihitung dari saldo normal akun: beban bertambah di debit, pendapatan
     * di kredit. Menjalankan `debit − credit` apa adanya untuk akun pendapatan
     * menampilkan realisasi negatif — angka yang salah, bukan hanya aneh.
     * Status reversed ikut dijumlahkan karena jurnal pembaliknya juga tercatat di
     * buku besar, sehingga keduanya saling meniadakan tanpa perlu dikecualikan.
     */
    public function ledgerActual(FinanceBudget $budget): float
    {
        [$from, $to] = $this->window($budget);

        $row = DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->whereIn('t.status', FinancialTransaction::LEDGER_STATUSES)
            ->where('l.account_id', (int) $budget->account_id)
            ->where('t.transaction_date', '>=', $from)
            ->where('t.transaction_date', '<=', $to)
            // Anggaran tanpa cost center adalah anggaran lintas unit: seluruh
            // movement akun ini dihitung, bukan hanya yang tidak ber-cost-center.
            ->when($budget->cost_center_id !== null, fn ($q) => $q->where('t.cost_center_id', (int) $budget->cost_center_id))
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit_total, COALESCE(SUM(l.credit), 0) as credit_total')
            ->first();

        $debit = (float) ($row->debit_total ?? 0);
        $credit = (float) ($row->credit_total ?? 0);

        return round($this->normalSide($budget) === 'credit' ? $credit - $debit : $debit - $credit, 2);
    }

    /**
     * Komitmen: pengajuan pengeluaran yang sudah masuk alur persetujuan tetapi
     * belum dibayar. Belum jadi actual (§10) — uang belum keluar.
     */
    public function committedAmount(FinanceBudget $budget): float
    {
        [$from, $to] = $this->window($budget);

        return round((float) FinanceExpenseRequest::query()
            ->where('account_id', (int) $budget->account_id)
            ->when($budget->cost_center_id !== null, fn ($q) => $q->where('cost_center_id', (int) $budget->cost_center_id))
            ->whereIn('status', self::COMMITTED_STATUSES)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<=', $to)
            ->sum('amount'), 2);
    }

    // ==================== Budget control (§10) ====================

    /**
     * "Check Budget → Available?" (§10) untuk satu nominal pada (akun, cost
     * center, tanggal). Menjawab dengan angka, bukan dengan keputusan: perlakuan
     * terhadap kelebihan diatur `applyControl()` menurut konfigurasi.
     *
     * @return array<string, mixed>
     */
    public function check(int $accountId, ?int $costCenterId, float $amount, Carbon|string|null $date = null): array
    {
        $budget = $this->matchingBudget($accountId, $costCenterId, $date);

        if ($budget === null) {
            // Tanpa anggaran tidak ada yang bisa dilanggar: pengeluaran tetap sah
            // dan tercatat sebagai tidak teranggarkan (§9, keputusan sadar).
            // `shortfall` nol karena tidak ada batas yang dilewati — angka ini
            // dibekukan pada pengajuan dan menentukan apakah ia menuntut
            // persetujuan tambahan, jadi memakainya untuk "kelebihan" salah.
            return [
                'status' => self::CHECK_NO_BUDGET,
                'budget_id' => null,
                'budget_amount' => 0.0,
                'actual' => 0.0,
                'committed' => 0.0,
                'available' => 0.0,
                'amount' => round($amount, 2),
                'shortfall' => 0.0,
                'control' => $this->controlMode(),
            ];
        }

        $position = $this->position($budget);
        $available = (float) $position['available'];
        $shortfall = round($amount - $available, 2);

        return [
            'status' => $shortfall > 0.005 ? self::CHECK_EXCEEDED : self::CHECK_OK,
            'budget_id' => (int) $budget->id,
            'budget_amount' => (float) $position['budget_amount'],
            'actual' => (float) $position['actual'],
            'committed' => (float) $position['committed'],
            'available' => $available,
            'amount' => round($amount, 2),
            'shortfall' => max(0.0, $shortfall),
            'control' => $this->controlMode(),
        ];
    }

    /**
     * Tegakkan kebijakan §10 atas pengajuan yang akan masuk alur persetujuan:
     * `block` menolak, `warning` dan `allow_with_approval` meloloskan dan
     * mengembalikan hasil pemeriksaan supaya pemanggil bisa menanggapinya.
     *
     * @return array<string, mixed>
     */
    public function applyControl(int $accountId, ?int $costCenterId, float $amount, Carbon|string|null $date = null): array
    {
        $verdict = $this->check($accountId, $costCenterId, $amount, $date);

        if ($verdict['status'] === self::CHECK_EXCEEDED && $verdict['control'] === self::CONTROL_BLOCK) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal melebihi sisa anggaran: tersedia '
                    .number_format((float) $verdict['available'], 0, ',', '.')
                    .', kekurangan '
                    .number_format((float) $verdict['shortfall'], 0, ',', '.')
                    .'. Revisi anggaran lebih dulu.',
            ]);
        }

        return $verdict;
    }

    /**
     * §10 `ALLOW_WITH_APPROVAL`: pengajuan yang melampaui anggaran boleh jalan,
     * tetapi persetujuannya harus ditutup pemegang hak anggaran.
     *
     * Jawabannya dibaca dari hasil pemeriksaan yang dibekukan pada baris pengajuan,
     * bukan dihitung ulang terhadap konfigurasi sekarang: kalau bendahara mengubah
     * kebijakan di tengah alur, pengajuan yang sudah berjalan tidak tiba-tiba
     * menuntut (atau berhenti menuntut) persetujuan yang berbeda.
     */
    public function awaitsBudgetAuthority(FinanceExpenseRequest $request): bool
    {
        return $request->budget_control === self::CONTROL_APPROVAL
            && $request->overranBudget()
            && $request->budget_override_by === null;
    }

    // ==================== Internal ====================

    protected function matchingBudget(int $accountId, ?int $costCenterId, Carbon|string|null $date): ?FinanceBudget
    {
        if ($costCenterId === null) {
            return null;
        }

        return FinanceBudget::query()
            ->where('account_id', $accountId)
            ->where('cost_center_id', $costCenterId)
            ->where('fiscal_year', $this->fiscalYearFor($date))
            ->whereIn('status', [FinanceBudget::STATUS_APPROVED, FinanceBudget::STATUS_ACTIVE])
            ->orderByRaw('period is null desc')
            ->first();
    }

    /** Tahun fiskal sebuah tanggal: bulan sebelum awal tahun fiskal dihitung mundur. */
    public function fiscalYearFor(Carbon|string|null $date): int
    {
        $moment = $date instanceof Carbon ? $date : Carbon::parse((string) ($date ?? 'now'));
        $startMonth = max(1, min(12, (int) $this->settings->get('fiscal_start_month', 7)));

        return $moment->month >= $startMonth ? $moment->year : $moment->year - 1;
    }

    protected function normalSide(FinanceBudget $budget): string
    {
        $account = $budget->account;

        return (string) ($account?->normal_balance ?? FinanceAccount::NORMAL_BALANCE_FOR_TYPE[(string) $account?->type] ?? 'debit');
    }

    /**
     * Jendela tanggal anggaran: tahun fiskal penuh, dipersempit bila `period`
     * dikenali (kode periode `P-2026-07` atau `2026-07`).
     *
     * @return array{0: string, 1: string}
     */
    protected function window(FinanceBudget $budget): array
    {
        $startMonth = max(1, min(12, (int) $this->settings->get('fiscal_start_month', 7)));
        $year = (int) $budget->fiscal_year;

        $from = Carbon::create($year, $startMonth, 1)->startOfDay();
        $to = $from->copy()->addYear()->subDay()->endOfDay();

        $period = $this->periodWindow($budget->period);

        if ($period !== null) {
            // Periode tidak boleh melompat keluar dari tahun fiskalnya sendiri.
            $from = max($from->getTimestamp(), $period[0]->getTimestamp()) === $period[0]->getTimestamp() ? $period[0] : $from;
            $to = min($to->getTimestamp(), $period[1]->getTimestamp()) === $period[1]->getTimestamp() ? $period[1] : $to;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Jendela satu periode anggaran. Bentuk yang tidak dikenali sengaja
     * diabaikan: memotong realisasi jadi nol karena teks periode aneh lebih
     * menyesatkan daripada memakai seluruh tahun fiskal.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    protected function periodWindow(?string $period): ?array
    {
        if (! preg_match('/(?:P-)?(\d{4})-(\d{1,2})$/', trim((string) $period), $matches)) {
            return null;
        }

        $start = Carbon::create((int) $matches[1], (int) $matches[2], 1)->startOfDay();

        return [$start, $start->copy()->endOfMonth()->endOfDay()];
    }

    protected function lock(FinanceBudget $budget): FinanceBudget
    {
        return FinanceBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
    }
}
