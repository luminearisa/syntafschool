<?php

namespace App\Services\Finance;

use App\Models\FinanceAdjustment;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseRequest;
use App\Models\FinanceRefund;
use App\Models\FinanceTransfer;
use App\Models\FinanceVendorInvoice;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard keuangan (§67): ringkasan KPI untuk beranda modul — posisi kas/bank,
 * piutang (AR) + yang jatuh tempo, pendapatan/beban bulan berjalan dari buku
 * besar, antrean persetujuan, realisasi anggaran, dan jurnal terbaru.
 *
 * Angka pendapatan/beban dibaca dari jurnal yang sudah diposting (bukan dari
 * saldo konkret) agar konsisten dengan laporan Laba Rugi.
 */
class FinanceDashboardService
{
    public function __construct(
        protected readonly FinanceBudgetService $budgets,
        protected readonly FinanceReceivableService $receivables,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(?User $actor = null): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        return [
            'as_of' => $today->toDateString(),
            'cash' => $this->cashPosition(),
            'receivable' => $this->receivables->summary(),
            'period' => $this->periodPerformance($monthStart, $today),
            'budget' => $this->budgetUtilization($this->budgets->fiscalYearFor($today)),
            'pending' => $this->pendingApprovals(),
            'recent_transactions' => $this->recentTransactions(),
        ];
    }

    /**
     * Total saldo kas & bank (akun aktif) + rincian jumlah akun.
     *
     * @return array<string, mixed>
     */
    protected function cashPosition(): array
    {
        $cash = (float) FinanceCashAccount::query()->active()->sum('current_balance');
        $bank = (float) FinanceBankAccount::query()->active()->sum('current_balance');

        return [
            'cash_total' => round($cash, 2),
            'bank_total' => round($bank, 2),
            'combined_total' => round($cash + $bank, 2),
            'cash_account_count' => FinanceCashAccount::query()->active()->count(),
            'bank_account_count' => FinanceBankAccount::query()->active()->count(),
        ];
    }

    /**
     * Pendapatan, beban, dan laba bersih bulan berjalan dari buku besar. Jurnal
     * `reversed` ikut dijumlahkan karena jurnal pembaliknya juga ada di sana —
     * memasangkan keduanya adalah yang membuat angka kembali nol (§51).
     *
     * @return array<string, mixed>
     */
    protected function periodPerformance(Carbon $from, Carbon $to): array
    {
        $sums = DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->join('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('t.status', FinancialTransaction::LEDGER_STATUSES)
            ->whereDate('t.transaction_date', '>=', $from->toDateString())
            ->whereDate('t.transaction_date', '<=', $to->toDateString())
            ->groupBy('a.type')
            ->select('a.type', DB::raw('COALESCE(SUM(l.debit),0) as debit'), DB::raw('COALESCE(SUM(l.credit),0) as credit'))
            ->get()
            ->keyBy('type');

        // Pendapatan bersaldo normal kredit; beban bersaldo normal debit.
        $revenue = round((float) ($sums['revenue']->credit ?? 0) - (float) ($sums['revenue']->debit ?? 0), 2);
        $expense = round((float) ($sums['expense']->debit ?? 0) - (float) ($sums['expense']->credit ?? 0), 2);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => max(0.0, $revenue),
            'expense' => max(0.0, $expense),
            'net' => round($revenue - $expense, 2),
        ];
    }

    /**
     * Budget vs Actual (§9) tahun fiskal berjalan: Total Budget, Total Actual,
     * Remaining Budget, Over Budget, Utilization — sesuai nama dan rumus di §9.
     *
     * Actual datang dari `FinanceBudgetService::positions()` yang menjumlah
     * jurnal posted, bukan dari kolom `used_amount`. Cache itu tetap dilaporkan
     * (`cached_used`) beserta selisihnya: dashboard yang hanya menampilkan
     * angka cache tidak bisa dipakai menemukan akun yang realisasinya melenceng.
     *
     * @return array<string, mixed>
     */
    protected function budgetUtilization(int $fiscalYear): array
    {
        $report = $this->budgets->positions(['fiscal_year' => $fiscalYear]);
        $totals = $report['totals'];
        $cached = round(array_sum(array_map(
            static fn (array $row): float => (float) $row['used_amount'],
            $report['rows'],
        )), 2);

        return [
            'fiscal_year' => $fiscalYear,
            'budget' => $totals['budget'],
            'actual' => $totals['actual'],
            'committed' => $totals['committed'],
            'variance' => $totals['variance'],
            'remaining' => $totals['remaining'],
            'utilization' => $totals['utilization'],
            'over_budget' => $totals['over_budget'],
            'not_in_agreement' => $totals['not_in_agreement'],
            'cached_used' => $cached,
            'cached_difference' => round($cached - $totals['actual'], 2),
        ];
    }

    /**
     * Antrean persetujuan lintas dokumen (§3 pemisahan pencatat vs penyetuju).
     *
     * @return array<string, int>
     */
    protected function pendingApprovals(): array
    {
        return [
            'budgets' => FinanceBudget::query()->where('status', FinanceBudget::STATUS_SUBMITTED)->count(),
            'expense_requests' => FinanceExpenseRequest::query()
                ->whereIn('status', [FinanceExpenseRequest::STATUS_SUBMITTED, FinanceExpenseRequest::STATUS_UNDER_REVIEW])->count(),
            'transfers' => FinanceTransfer::query()->where('status', FinanceTransfer::STATUS_SUBMITTED)->count(),
            'adjustments' => FinanceAdjustment::query()->where('status', FinanceAdjustment::STATUS_SUBMITTED)->count(),
            'refunds' => FinanceRefund::query()
                ->whereIn('status', [FinanceRefund::STATUS_REQUESTED, FinanceRefund::STATUS_APPROVED, FinanceRefund::STATUS_PROCESSING])->count(),
            'vendor_invoices' => FinanceVendorInvoice::query()
                ->whereIn('status', [
                    FinanceVendorInvoice::STATUS_RECEIVED,
                    FinanceVendorInvoice::STATUS_PARTIALLY_PAID,
                    FinanceVendorInvoice::STATUS_OVERDUE,
                ])->count(),
        ];
    }

    /**
     * Jurnal terbaru (posted) untuk sekilas aktivitas.
     *
     * @return list<array<string, mixed>>
     */
    protected function recentTransactions(int $limit = 8): array
    {
        return FinancialTransaction::query()
            ->posted()
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (FinancialTransaction $t): array => [
                'id' => (int) $t->id,
                'transaction_number' => $t->transaction_number,
                'date' => $t->transaction_date?->toDateString(),
                'type' => $t->type,
                'description' => $t->description,
                'total' => (float) $t->total_debit,
            ])
            ->all();
    }
}
