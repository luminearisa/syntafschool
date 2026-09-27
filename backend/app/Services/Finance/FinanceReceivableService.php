<?php

namespace App\Services\Finance;

use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Piutang / Accounts Receivable + aging (§26–§28).
 *
 * AR dihitung dari invoice outstanding (issued/partial/overdue) — `remaining_amount`
 * adalah sisa yang belum dibayar. Aging mengelompokkan sisa itu per ember umur
 * berdasarkan `due_date` terhadap tanggal acuan (`as_of`, default hari ini):
 * belum jatuh tempo, 1-30, 31-60, 61-90, dan >90 hari terlambat.
 *
 * Satu sumber angka dipakai controller AR interaktif maupun laporan/CSV supaya
 * tampilan layar dan ekspor tidak pernah berbeda. Deposit santri (§19) ikut
 * dibaca di sini karena ia sisi cermin piutang: uang yang sudah masuk tetapi
 * belum menemukan tagihannya.
 */
class FinanceReceivableService
{
    public const BUCKET_CURRENT = 'current';

    public const BUCKET_1_30 = '1_30';

    public const BUCKET_31_60 = '31_60';

    public const BUCKET_61_90 = '61_90';

    public const BUCKET_90_PLUS = '90_plus';

    public const BUCKETS = [
        self::BUCKET_CURRENT, self::BUCKET_1_30, self::BUCKET_31_60, self::BUCKET_61_90, self::BUCKET_90_PLUS,
    ];

    public const BUCKET_LABELS = [
        self::BUCKET_CURRENT => 'Belum Jatuh Tempo',
        self::BUCKET_1_30 => '1-30 Hari',
        self::BUCKET_31_60 => '31-60 Hari',
        self::BUCKET_61_90 => '61-90 Hari',
        self::BUCKET_90_PLUS => '> 90 Hari',
    ];

    /**
     * Ember aging untuk sebuah invoice terhadap tanggal acuan.
     */
    public function bucketFor(?Carbon $dueDate, Carbon $asOf): string
    {
        if ($dueDate === null) {
            return self::BUCKET_CURRENT;
        }

        $days = $dueDate->diffInDays($asOf, false);

        // diffInDays(false): positif bila asOf setelah dueDate (sudah lewat).
        return match (true) {
            $days <= 0 => self::BUCKET_CURRENT,
            $days <= 30 => self::BUCKET_1_30,
            $days <= 60 => self::BUCKET_31_60,
            $days <= 90 => self::BUCKET_61_90,
            default => self::BUCKET_90_PLUS,
        };
    }

    /**
     * Query invoice outstanding dengan filter. Dipakai daftar AR + aging.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<FinanceInvoice>
     */
    public function query(array $filters = []): Builder
    {
        return FinanceInvoice::query()
            ->with(['student:id,full_name,nis', 'costCenter:id,name'])
            ->outstanding()
            ->when(filled($filters['cost_center_id'] ?? null), fn (Builder $q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['customer_type'] ?? null), fn (Builder $q) => $q->where('customer_type', $filters['customer_type']))
            ->when(filled($filters['due_from'] ?? null), fn (Builder $q) => $q->whereDate('due_date', '>=', $filters['due_from']))
            ->when(filled($filters['due_to'] ?? null), fn (Builder $q) => $q->whereDate('due_date', '<=', $filters['due_to']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(fn (Builder $inner) => $inner
                    ->where('invoice_number', 'like', "%$term%")
                    ->orWhereHas('student', fn (Builder $s) => $s->where('full_name', 'like', "%$term%")));
            });
    }

    /**
     * Baris AR datar (siap tabel & CSV): satu baris per invoice outstanding.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function receivables(array $filters = []): array
    {
        $asOf = $this->asOf($filters);

        return $this->query($filters)
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (FinanceInvoice $invoice): array => $this->receivableRow($invoice, $asOf))
            ->all();
    }

    /**
     * Ringkasan aging: total per ember + matriks per customer.
     *
     * @param  array<string, mixed>  $filters
     * @return array{as_of: string, grand_total: float, buckets: list<array<string, mixed>>, by_customer: list<array<string, mixed>>}
     */
    public function aging(array $filters = []): array
    {
        $asOf = $this->asOf($filters);

        $invoices = $this->query($filters)->limit($this->limit($filters))->get();

        $bucketTotals = array_fill_keys(self::BUCKETS, 0.0);
        $bucketCounts = array_fill_keys(self::BUCKETS, 0);
        $byCustomer = [];
        $grandTotal = 0.0;

        foreach ($invoices as $invoice) {
            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($remaining <= 0) {
                continue;
            }

            $bucket = $this->bucketFor($invoice->due_date, $asOf);
            $bucketTotals[$bucket] = round($bucketTotals[$bucket] + $remaining, 2);
            $bucketCounts[$bucket]++;
            $grandTotal = round($grandTotal + $remaining, 2);

            $key = $this->customerKey($invoice);
            $byCustomer[$key] ??= [
                'customer' => $this->customerName($invoice),
                'student_id' => $invoice->student_id !== null ? (int) $invoice->student_id : null,
                'nis' => $invoice->student?->nis,
                'total' => 0.0,
                'invoices' => 0,
                ...array_fill_keys(self::BUCKETS, 0.0),
            ];
            $byCustomer[$key]['total'] = round($byCustomer[$key]['total'] + $remaining, 2);
            $byCustomer[$key]['invoices']++;
            $byCustomer[$key][$bucket] = round($byCustomer[$key][$bucket] + $remaining, 2);
        }

        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $buckets[] = [
                'bucket' => $bucket,
                'label' => self::BUCKET_LABELS[$bucket],
                'count' => $bucketCounts[$bucket],
                'total' => $bucketTotals[$bucket],
            ];
        }

        $customers = array_values($byCustomer);
        usort($customers, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'as_of' => $asOf->toDateString(),
            'grand_total' => $grandTotal,
            'buckets' => $buckets,
            'by_customer' => $customers,
        ];
    }

    /**
     * Ringkas AR: total piutang, total jatuh tempo, jumlah invoice.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        return $this->summarize(
            $this->query($filters)->limit($this->limit($filters))->get(),
            $this->asOf($filters),
        );
    }

    /**
     * Ringkasan atas baris yang SUDAH dimuat. `summary()` memakainya setelah
     * membaca database, dan pemanggil lain (layar kelayakan §11, keberesan §12)
     * memakainya atas baris yang sudah di tangan — jadi angka tidak pernah
     * dihasilkan oleh dua buah loop yang berbeda.
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return array<string, mixed>
     */
    public function summarize(Collection $invoices, Carbon $asOf): array
    {
        $total = 0.0;
        $overdue = 0.0;
        $count = 0;
        $overdueCount = 0;

        foreach ($invoices as $invoice) {
            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($remaining <= 0) {
                continue;
            }

            $total = round($total + $remaining, 2);
            $count++;

            if ($this->bucketFor($invoice->due_date, $asOf) !== self::BUCKET_CURRENT) {
                $overdue = round($overdue + $remaining, 2);
                $overdueCount++;
            }
        }

        return [
            'as_of' => $asOf->toDateString(),
            'total_receivable' => $total,
            'total_overdue' => $overdue,
            'invoice_count' => $count,
            'overdue_count' => $overdueCount,
        ];
    }

    /**
     * Sisa pembayaran yang belum terpakai (§19 `credit_balance`/`unallocated`)
     * untuk sekumpulan santri: id santri => nominal.
     *
     * Ini sisi cermin piutang — uangnya masuk tapi belum diterapkan ke invoice
     * mana pun — jadi angkanya tinggal di sini, bukan di tiap layar yang butuh
     * angka itu. Portal orang tua (§9/§10) dan daftar periksa keberesan (§12)
     * memakai hasil bagi yang sama; kalau keduanya punya loop sendiri, deposit
     * yang sama bisa terbaca dua nilai berbeda.
     *
     * Hanya pembayaran yang benar-benar diterima (paid/partial) yang dihitung —
     * pending belum menyentuh kas, dan yang batal/gagal/expired sudah ditarik
     * balik.
     *
     * @param  list<int>  $studentIds
     * @return array<int, float>
     */
    public function creditBalances(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $received = FinancePayment::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', [FinancePayment::STATUS_PAID, FinancePayment::STATUS_PARTIAL])
            ->groupBy('student_id')
            ->selectRaw('student_id, COALESCE(SUM(amount), 0) as total')
            ->pluck('total', 'student_id')
            ->map(fn (mixed $v): float => round((float) $v, 2))
            ->all();

        if ($received === []) {
            return [];
        }

        $appliedByStudent = FinancePaymentAllocation::query()
            ->join('finance_payments', 'finance_payments.id', '=', 'finance_payment_allocations.payment_id')
            ->whereIn('finance_payments.student_id', $studentIds)
            ->whereIn('finance_payments.status', [FinancePayment::STATUS_PAID, FinancePayment::STATUS_PARTIAL])
            ->groupBy('finance_payments.student_id')
            ->selectRaw('finance_payments.student_id as student_id, COALESCE(SUM(finance_payment_allocations.amount), 0) as total')
            ->pluck('total', 'student_id')
            ->map(fn (mixed $v): float => round((float) $v, 2))
            ->all();

        $credits = [];

        foreach ($received as $studentId => $total) {
            $credit = round($total - ($appliedByStudent[$studentId] ?? 0.0), 2);

            if ($credit > 0.0001) {
                $credits[(int) $studentId] = $credit;
            }
        }

        return $credits;
    }

    /** Deposit satu santri; nol bila tidak ada sisa pembayaran yang mengganggur. */
    public function creditBalance(int $studentId): float
    {
        return $this->creditBalances([$studentId])[$studentId] ?? 0.0;
    }

    // ==================== Internal ====================

    /**
     * Satu baris AR datar. Dipakai daftar penagihan (§6) juga, supaya umur
     * tunggakan dan ember umur tidak dihitung dua kali dengan dua cara.
     *
     * @return array<string, mixed>
     */
    public function receivableRow(FinanceInvoice $invoice, Carbon $asOf): array
    {
        $remaining = round((float) $invoice->remaining_amount, 2);
        $bucket = $this->bucketFor($invoice->due_date, $asOf);
        $daysOverdue = $invoice->due_date !== null ? max(0, (int) $invoice->due_date->diffInDays($asOf, false)) : 0;

        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer' => $this->customerName($invoice),
            'nis' => $invoice->student?->nis,
            'cost_center' => $invoice->costCenter?->name,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'written_off_amount' => (float) $invoice->written_off_amount,
            'remaining_amount' => $remaining,
            'days_overdue' => $daysOverdue,
            'bucket' => $bucket,
            'bucket_label' => self::BUCKET_LABELS[$bucket],
            'status' => $invoice->status,
            'status_label' => $invoice->statusLabel(),
            // Status penagihan (§21) berbeda dari status dokumen: invoice yang
            // sisa piutangnya dihapus tetap terbaca `written_off` di laporan AR.
            'receivable_status' => $invoice->receivableStatus(),
        ];
    }

    protected function customerKey(FinanceInvoice $invoice): string
    {
        return $invoice->student_id !== null
            ? 'student:'.$invoice->student_id
            : $invoice->customer_type.':'.$invoice->customer_id;
    }

    protected function customerName(FinanceInvoice $invoice): string
    {
        if ($invoice->student !== null) {
            return (string) $invoice->student->full_name;
        }

        return $invoice->customer_type.' #'.$invoice->customer_id;
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
