<?php

namespace App\Services\Finance;

use App\Models\FinanceVendorInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Hutang / Accounts Payable + aging (§24–§31).
 *
 * Cermin `FinanceReceivableService`: AR dibaca dari invoice outstanding, AP
 * dibaca dari invoice vendor outstanding (`received`/`partially_paid`/`overdue`)
 * dengan `remaining_amount` sebagai sisa kewajiban ke vendor. Ember umurnya
 * sengaja memakai mesin aging AR — ambang 1-30/31-60/61-90/>90 adalah aturan
 * umum "hari keterlambatan terhadap tanggal acuan", bukan aturan piutang saja,
 * jadi menyalinnya ke kelas ini hanya akan menghasilkan dua sumber kebenaran.
 *
 * Nilai buku hutang tetap ada di akun 2100 di buku besar (neraca); daftar ini
 * adalah sisi penagihan/pembayarannya: hutang siapa, berapa, jatuh tempo kapan.
 */
class FinancePayableService
{
    public function __construct(private readonly FinanceReceivableService $aging) {}

    /**
     * Query invoice vendor outstanding dengan filter.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<FinanceVendorInvoice>
     */
    public function query(array $filters = []): Builder
    {
        return FinanceVendorInvoice::query()
            ->with(['vendor:id,name', 'costCenter:id,name', 'account:id,code,name'])
            ->outstanding()
            ->when(filled($filters['vendor_id'] ?? null), fn (Builder $q) => $q->where('vendor_id', (int) $filters['vendor_id']))
            ->when(filled($filters['cost_center_id'] ?? null), fn (Builder $q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['account_id'] ?? null), fn (Builder $q) => $q->where('account_id', (int) $filters['account_id']))
            ->when(filled($filters['due_from'] ?? null), fn (Builder $q) => $q->whereDate('due_date', '>=', $filters['due_from']))
            ->when(filled($filters['due_to'] ?? null), fn (Builder $q) => $q->whereDate('due_date', '<=', $filters['due_to']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(fn (Builder $inner) => $inner
                    ->where('vendor_invoice_number', 'like', "%$term%")
                    ->orWhere('notes', 'like', "%$term%")
                    ->orWhereHas('vendor', fn (Builder $v) => $v->where('name', 'like', "%$term%")));
            });
    }

    /**
     * Baris AP datar (siap tabel & CSV): satu baris per invoice vendor outstanding.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function payables(array $filters = []): array
    {
        $asOf = $this->asOf($filters);

        return $this->query($filters)
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (FinanceVendorInvoice $invoice): array => $this->payableRow($invoice, $asOf))
            ->all();
    }

    /**
     * Ringkasan aging AP: total per ember + matriks per vendor.
     *
     * @param  array<string, mixed>  $filters
     * @return array{as_of: string, grand_total: float, buckets: list<array<string, mixed>>, by_vendor: list<array<string, mixed>>}
     */
    public function aging(array $filters = []): array
    {
        $asOf = $this->asOf($filters);
        $buckets = array_fill_keys(FinanceReceivableService::BUCKETS, 0.0);
        $counts = array_fill_keys(FinanceReceivableService::BUCKETS, 0);
        $byVendor = [];
        $grandTotal = 0.0;

        foreach ($this->query($filters)->limit($this->limit($filters))->get() as $invoice) {
            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($remaining <= 0) {
                continue;
            }

            $bucket = $this->aging->bucketFor($invoice->due_date, $asOf);
            $buckets[$bucket] = round($buckets[$bucket] + $remaining, 2);
            $counts[$bucket]++;
            $grandTotal = round($grandTotal + $remaining, 2);

            $key = 'vendor:'.$invoice->vendor_id;
            $byVendor[$key] ??= [
                'vendor' => $invoice->vendor?->name ?? ('Vendor #'.$invoice->vendor_id),
                'vendor_id' => (int) $invoice->vendor_id,
                'invoices' => 0,
                'total' => 0.0,
                ...array_fill_keys(FinanceReceivableService::BUCKETS, 0.0),
            ];
            $byVendor[$key]['total'] = round($byVendor[$key]['total'] + $remaining, 2);
            $byVendor[$key]['invoices']++;
            $byVendor[$key][$bucket] = round($byVendor[$key][$bucket] + $remaining, 2);
        }

        $vendors = array_values($byVendor);
        usort($vendors, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'as_of' => $asOf->toDateString(),
            'grand_total' => $grandTotal,
            'buckets' => array_map(fn (string $bucket): array => [
                'bucket' => $bucket,
                'label' => FinanceReceivableService::BUCKET_LABELS[$bucket],
                'count' => $counts[$bucket],
                'total' => $buckets[$bucket],
            ], FinanceReceivableService::BUCKETS),
            'by_vendor' => $vendors,
        ];
    }

    /**
     * Total hutang + yang jatuh tempo (§30).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $asOf = $this->asOf($filters);
        $total = 0.0;
        $overdue = 0.0;
        $count = 0;
        $overdueCount = 0;

        foreach ($this->query($filters)->limit($this->limit($filters))->get() as $invoice) {
            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($remaining <= 0) {
                continue;
            }

            $total = round($total + $remaining, 2);
            $count++;

            if ($this->aging->bucketFor($invoice->due_date, $asOf) !== FinanceReceivableService::BUCKET_CURRENT) {
                $overdue = round($overdue + $remaining, 2);
                $overdueCount++;
            }
        }

        return [
            'as_of' => $asOf->toDateString(),
            'total_payable' => $total,
            'total_overdue' => $overdue,
            'invoice_count' => $count,
            'overdue_count' => $overdueCount,
        ];
    }

    // ==================== Internal ====================

    /**
     * @return array<string, mixed>
     */
    protected function payableRow(FinanceVendorInvoice $invoice, Carbon $asOf): array
    {
        $remaining = round((float) $invoice->remaining_amount, 2);
        $bucket = $this->aging->bucketFor($invoice->due_date, $asOf);
        $daysOverdue = $invoice->due_date !== null ? max(0, (int) $invoice->due_date->diffInDays($asOf, false)) : 0;

        return [
            'vendor_invoice_id' => (int) $invoice->id,
            'vendor_invoice_number' => $invoice->vendor_invoice_number,
            'vendor' => $invoice->vendor?->name ?? ('Vendor #'.$invoice->vendor_id),
            'cost_center' => $invoice->costCenter?->name,
            'account_code' => $invoice->account?->code,
            'account_name' => $invoice->account?->name,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'remaining_amount' => $remaining,
            'days_overdue' => $daysOverdue,
            'bucket' => $bucket,
            'bucket_label' => FinanceReceivableService::BUCKET_LABELS[$bucket],
            'status' => $invoice->status,
            'status_label' => $invoice->statusLabel(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function asOf(array $filters): Carbon
    {
        return filled($filters['as_of'] ?? null)
            ? Carbon::parse((string) $filters['as_of'])->startOfDay()
            : Carbon::today();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function limit(array $filters): int
    {
        return max(1, min(10000, (int) ($filters['limit'] ?? 5000)));
    }
}
