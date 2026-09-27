<?php

namespace App\Services\Finance;

use App\Models\FinanceAdjustment;
use App\Models\FinanceBankTransaction;
use App\Models\FinanceBilling;
use App\Models\FinanceCashTransaction;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseRequest;
use App\Models\FinanceInvoice;
use App\Models\FinanceInvoiceItem;
use App\Models\FinancePayment;
use App\Models\FinanceReceipt;
use App\Models\FinanceRefund;
use App\Models\FinanceTransfer;
use App\Models\FinanceVendorInvoice;
use App\Models\FinancialTransaction;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pembacaan jurnal + penelusuran (§4, §38, §47).
 *
 * Kelas ini TIDAK menulis apa pun: jurnal hanya ditulis oleh
 * `FinancialTransactionEngine` lewat `JournalPostingService` (§6). Di sisi baca,
 * `finance_transactions` diperlakukan sebagai jurnal umum — header + baris —
 * tanpa tabel kedua (§71), jadi daftar transaksi dan daftar jurnal adalah
 * query yang sama dengan sudut pandang berbeda.
 *
 * `trace()` mewujudkan rantai §38 (JRN → PAY → RCPT → INV → BIL → item → santri)
 * dari `reference_type`/`reference_id` + `metadata` yang sudah ditulis setiap
 * posting, sehingga penelusuran tidak bergantung pada kolom tambahan.
 */
class FinanceJournalService
{
    /**
     * Dokumen yang bisa melahirkan jurnal: kunci API, judul tampilan, dan kolom
     * nomor/tanggal/nominal/status-nya. Satu peta dipakai bersama oleh filter
     * `reference_type` dan oleh rantai penelusuran, jadi keduanya tidak pernah
     * bisa berbeda nama maupun arti.
     *
     * @var array<class-string<Model>, array{key: string, label: string, number: string, date: string, amount: string}>
     */
    public const DOCUMENTS = [
        FinanceInvoice::class => ['key' => 'invoice', 'label' => 'Invoice', 'number' => 'invoice_number', 'date' => 'issue_date', 'amount' => 'total'],
        FinanceBilling::class => ['key' => 'billing', 'label' => 'Tagihan (Billing)', 'number' => 'billing_number', 'date' => 'created_at', 'amount' => 'total'],
        FinancePayment::class => ['key' => 'payment', 'label' => 'Pembayaran', 'number' => 'payment_number', 'date' => 'paid_at', 'amount' => 'amount'],
        FinanceReceipt::class => ['key' => 'receipt', 'label' => 'Kuitansi', 'number' => 'receipt_number', 'date' => 'received_at', 'amount' => 'amount'],
        FinanceRefund::class => ['key' => 'refund', 'label' => 'Refund', 'number' => 'refund_number', 'date' => 'processed_at', 'amount' => 'amount'],
        FinanceExpense::class => ['key' => 'expense', 'label' => 'Pengeluaran', 'number' => 'expense_number', 'date' => 'expense_date', 'amount' => 'amount'],
        FinanceExpenseRequest::class => ['key' => 'expense_request', 'label' => 'Pengajuan Pengeluaran', 'number' => 'request_number', 'date' => 'expense_date', 'amount' => 'amount'],
        FinanceVendorInvoice::class => ['key' => 'vendor_invoice', 'label' => 'Invoice Vendor', 'number' => 'vendor_invoice_number', 'date' => 'invoice_date', 'amount' => 'total'],
        FinanceAdjustment::class => ['key' => 'adjustment', 'label' => 'Adjustment', 'number' => 'adjustment_number', 'date' => 'adjustment_date', 'amount' => 'amount'],
        FinanceTransfer::class => ['key' => 'transfer', 'label' => 'Transfer', 'number' => 'transfer_number', 'date' => 'transfer_date', 'amount' => 'amount'],
        FinanceBankTransaction::class => ['key' => 'bank_transaction', 'label' => 'Transaksi Bank', 'number' => 'reference', 'date' => 'transaction_date', 'amount' => 'amount'],
        FinanceCashTransaction::class => ['key' => 'cash_transaction', 'label' => 'Transaksi Kas', 'number' => 'transaction_number', 'date' => 'transaction_date', 'amount' => 'amount'],
    ];

    /**
     * Daftar jurnal umum dengan filter (§47). Filter `account_id` menembus ke
     * baris karena satu jurnal bisa menyinggung banyak akun.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<FinancialTransaction>
     */
    public function query(array $filters = []): Builder
    {
        $reference = $this->referenceFilter($filters);

        return FinancialTransaction::query()
            ->with(['lines.account:id,code,name,normal_balance,type', 'lines.costCenter:id,name', 'costCenter:id,name'])
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where('type', (string) $filters['type']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', (string) $filters['status']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate('transaction_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate('transaction_date', '<=', $filters['to']))
            ->when(filled($filters['cost_center_id'] ?? null), fn (Builder $q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['period_id'] ?? null), fn (Builder $q) => $q->where('period_id', (int) $filters['period_id']))
            ->when(filled($filters['created_by'] ?? null), fn (Builder $q) => $q->where('created_by', (int) $filters['created_by']))
            ->when(filled($filters['account_id'] ?? null), fn (Builder $q) => $q->whereHas(
                'lines',
                fn (Builder $l) => $l->where('account_id', (int) $filters['account_id']),
            ))
            ->when($reference !== null, fn (Builder $q) => $q->where($reference))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(fn (Builder $inner) => $inner
                    ->where('transaction_number', 'like', "%$term%")
                    ->orWhere('description', 'like', "%$term%"));
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');
    }

    /**
     * Total debit/credit dari himpunan jurnal yang tersaring — angka penutup
     * yang harus selalu sama (§47). Ditampilkan di meta daftar supaya ketidak-
     * seimbangan terlihat di layar, bukan baru ketahuan saat neraca.
     *
     * @param  array<string, mixed>  $filters
     * @return array{journals: int, debit: float, credit: float, balanced: bool}
     */
    public function sums(array $filters = []): array
    {
        $row = $this->query($filters)
            ->reorder()
            ->toBase()
            ->selectRaw('COUNT(*) as aggregated, COALESCE(SUM(total_debit), 0) as debit, COALESCE(SUM(total_credit), 0) as credit')
            ->first();

        $debit = round((float) $row->debit, 2);
        $credit = round((float) $row->credit, 2);

        return [
            'journals' => (int) $row->aggregated,
            'debit' => $debit,
            'credit' => $credit,
            'balanced' => abs($debit - $credit) < 0.005,
        ];
    }

    /**
     * Rantai penelusuran §38 untuk satu jurnal.
     *
     * @return array{stages: list<string>, trail: list<array<string, mixed>>, accounts: list<array<string, mixed>>, totals: array<string, float>, related_journals: list<array<string, mixed>>}
     */
    public function trace(FinancialTransaction $journal): array
    {
        $journal->loadMissing(['lines.account:id,code,name,normal_balance,type', 'lines.costCenter:id,name', 'reference']);

        $document = $journal->reference;
        $payment = match (true) {
            $document instanceof FinancePayment => $document,
            $document instanceof FinanceRefund, $document instanceof FinanceExpense => $document->payment,
            default => null,
        };

        $invoices = $this->traceableInvoices($journal, $document, $payment);
        $receipt = $payment?->receipt;

        $trail = [];
        $trail[] = $this->journalStep($journal);

        // Dokumen sumber lebih dulu (refund/adjustment/vendor invoice), baru
        // pembayarannya; pada jurnal akrual murni tidak ada tahap uang sama
        // sekali — dan memang begitu seharusnya (§8). Invoice tidak ikut di
        // sini: tahapnya dirender di bawah bersama baris itemnya, supaya satu
        // tagihan tidak muncul dua kali dalam rantai.
        if ($document !== null && ! $document instanceof FinancePayment && ! $document instanceof FinanceInvoice) {
            $trail[] = $this->documentStep($document);
        }

        if ($payment !== null) {
            $trail[] = $this->documentStep($payment);

            if ($receipt !== null) {
                $trail[] = $this->documentStep($receipt);
            }
        }

        foreach ($invoices as $invoice) {
            $invoice->loadMissing(['items.feeType:id,name', 'items.account:id,code,name', 'billing:id,billing_number,total,remaining_amount,status,created_at']);

            $trail[] = $this->documentStep($invoice) + [
                'remaining_amount' => (float) $invoice->remaining_amount,
                'written_off_amount' => (float) $invoice->written_off_amount,
                'receivable_status' => $invoice->receivableStatus(),
                'items' => $invoice->items
                    ->map(fn (FinanceInvoiceItem $item): array => [
                        'id' => (int) $item->id,
                        'stage' => 'item',
                        'description' => $item->description,
                        'fee_type' => $item->feeType?->name,
                        'account_code' => $item->account?->code,
                        'account_name' => $item->account?->name,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'amount' => (float) $item->total,
                    ])
                    ->values()
                    ->all(),
            ];

            if ($invoice->billing !== null) {
                $invoice->billing->loadMissing('items.feeType:id,name');

                $trail[] = $this->documentStep($invoice->billing) + [
                    'remaining_amount' => (float) $invoice->billing->remaining_amount,
                    'items' => $invoice->billing->items
                        ->map(fn ($item): array => [
                            'id' => (int) $item->id,
                            'stage' => 'item',
                            'description' => $item->description,
                            'fee_type' => $item->feeType?->name,
                            'quantity' => (int) $item->quantity,
                            'amount' => (float) $item->total,
                        ])
                        ->values()
                        ->all(),
                ];
            }
        }

        $student = $invoices->first(fn (FinanceInvoice $invoice): bool => $invoice->student !== null)?->student
            ?? $payment?->student;

        if ($student !== null) {
            $trail[] = [
                'stage' => 'student',
                'label' => 'Santri',
                'key' => 'student',
                'type' => Student::class,
                'id' => (int) $student->id,
                'number' => $student->nis,
                'name' => $student->full_name,
            ];
        }

        return [
            'stages' => ['journal', 'document', 'payment', 'receipt', 'invoice', 'billing', 'item', 'student'],
            'trail' => $trail,
            'accounts' => $this->touchedAccounts($journal),
            'totals' => [
                'debit' => round((float) $journal->total_debit, 2),
                'credit' => round((float) $journal->total_credit, 2),
                'lines' => $journal->lines->count(),
            ],
            'related_journals' => $this->relatedJournals($journal, $invoices->pluck('id')->all(), array_filter([$payment?->id])),
        ];
    }

    /**
     * Nama dokumen untuk filter/meta API: kunci => kelas.
     *
     * @return array<string, class-string<Model>>
     */
    public static function documentKeys(): array
    {
        return array_combine(
            array_map(fn (array $meta): string => $meta['key'], array_values(self::DOCUMENTS)),
            array_keys(self::DOCUMENTS),
        );
    }

    /** Terima FQCN atau kunci pendek (`invoice`, `payment`, …); selain itu null. */
    protected function referenceFilter(array $filters): ?array
    {
        $type = filled($filters['reference_type'] ?? null) ? (string) $filters['reference_type'] : null;

        if ($type === null) {
            return null;
        }

        $class = array_key_exists($type, self::DOCUMENTS)
            ? $type
            : (self::documentKeys()[$type] ?? null);

        if ($class === null) {
            return null;
        }

        $id = filled($filters['reference_id'] ?? null) ? (int) $filters['reference_id'] : null;

        return $id === null
            ? ['reference_type' => $class]
            : ['reference_type' => $class, 'reference_id' => $id];
    }

    /**
     * Invoice yang ikut membentuk jurnal ini: dari dokumen sumber, dari alokasi
     * pembayaran, atau dari `metadata` (jurnal write-off menunjuk adjustment-
     * nya, tapi invoice yang dihapus ada di metadata §17/§33).
     *
     * @return Collection<int, FinanceInvoice>
     */
    protected function traceableInvoices(FinancialTransaction $journal, ?Model $document, ?FinancePayment $payment): Collection
    {
        $ids = [];

        if ($document instanceof FinanceInvoice) {
            $ids[] = (int) $document->id;
        }

        if ($document instanceof FinanceAdjustment && $document->invoice_id !== null) {
            $ids[] = (int) $document->invoice_id;
        }

        if ($payment !== null) {
            $ids[] = $payment->invoice_id !== null ? (int) $payment->invoice_id : 0;
            $ids = array_merge($ids, $payment->allocations()->pluck('invoice_id')->map(fn ($id): int => (int) $id)->all());
        }

        foreach ((array) ($journal->metadata['allocations'] ?? []) as $allocation) {
            $ids[] = (int) ($allocation['invoice_id'] ?? 0);
        }

        if ($journal->metadata['invoice_id'] ?? null) {
            $ids[] = (int) $journal->metadata['invoice_id'];
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return new Collection;
        }

        return FinanceInvoice::query()
            ->with('student:id,full_name,nis')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /**
     * Akun-akun yang disinggung jurnal, dengan total per sisi. Ditampilkan agar
     * pembaca API melihat arah posting tanpa menjumlah sendiri (§37).
     *
     * @return list<array<string, mixed>>
     */
    protected function touchedAccounts(FinancialTransaction $journal): array
    {
        $byAccount = [];

        foreach ($journal->lines as $line) {
            $key = (int) $line->account_id;
            $byAccount[$key] ??= [
                'account_id' => $key,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'account_type' => $line->account?->type,
                'normal_balance' => $line->account?->normal_balance,
                'debit' => 0.0,
                'credit' => 0.0,
            ];
            $byAccount[$key]['debit'] = round($byAccount[$key]['debit'] + (float) $line->debit, 2);
            $byAccount[$key]['credit'] = round($byAccount[$key]['credit'] + (float) $line->credit, 2);
        }

        usort($byAccount, fn (array $a, array $b): int => strcmp((string) $a['account_code'], (string) $b['account_code']));

        return array_values($byAccount);
    }

    /**
     * Semua jurnal yang lahir dari dokumen yang sama — termasuk write-off yang
     * referensinya adjustment namun metadata-nya memuat `invoice_id`. Tanpa
     * metadata, siklus hidup satu tagihan terputus di tengah (§38).
     *
     * @param  list<int>  $invoiceIds
     * @param  list<int>  $paymentIds
     * @return list<array<string, mixed>>
     */
    protected function relatedJournals(FinancialTransaction $journal, array $invoiceIds, array $paymentIds): array
    {
        $query = FinancialTransaction::query()
            ->with('lines.account:id,code,name')
            ->where('id', $journal->id)
            ->when($invoiceIds !== [] || $paymentIds !== [], function (Builder $q) use ($invoiceIds, $paymentIds): void {
                $q->orWhere(function (Builder $inner) use ($invoiceIds, $paymentIds): void {
                    if ($invoiceIds !== []) {
                        $inner->orWhere(fn (Builder $byDoc) => $byDoc
                            ->where('reference_type', FinanceInvoice::class)
                            ->whereIn('reference_id', $invoiceIds))
                            ->orWhereIn('metadata->invoice_id', $invoiceIds);
                    }

                    if ($paymentIds !== []) {
                        $inner->orWhere(fn (Builder $byDoc) => $byDoc
                            ->where('reference_type', FinancePayment::class)
                            ->whereIn('reference_id', $paymentIds));
                    }
                });
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->limit(200);

        return $query->get()
            ->map(fn (FinancialTransaction $row): array => [
                'id' => (int) $row->id,
                'transaction_number' => $row->transaction_number,
                'transaction_date' => $row->transaction_date?->toDateString(),
                'type' => $row->type,
                'type_label' => $row->typeLabel(),
                'status' => $row->status,
                'description' => $row->description,
                'total_debit' => round((float) $row->total_debit, 2),
                'total_credit' => round((float) $row->total_credit, 2),
                'is_current' => (int) $row->id === (int) $journal->id,
                'lines' => $row->lines
                    ->map(fn ($line): array => [
                        'account_code' => $line->account?->code,
                        'account_name' => $line->account?->name,
                        'debit' => round((float) $line->debit, 2),
                        'credit' => round((float) $line->credit, 2),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function journalStep(FinancialTransaction $journal): array
    {
        return [
            'stage' => 'journal',
            'label' => 'Jurnal',
            'key' => 'journal',
            'type' => FinancialTransaction::class,
            'id' => (int) $journal->id,
            'number' => $journal->transaction_number,
            'date' => $journal->transaction_date?->toDateString(),
            'amount' => round((float) $journal->total_debit, 2),
            'status' => $journal->status,
            'status_label' => $journal->typeLabel(),
            'type_of_transaction' => $journal->type,
        ];
    }

    /** Satu tahap dokumen umum: nomor, tanggal, nominal, status. */
    protected function documentStep(Model $document): array
    {
        $meta = self::DOCUMENTS[$document::class] ?? null;
        $date = $meta === null ? null : $document->getAttribute($meta['date']);

        return [
            'stage' => $meta['key'] ?? 'document',
            'label' => $meta['label'] ?? class_basename($document),
            'key' => $meta['key'] ?? 'document',
            'type' => $document::class,
            'id' => (int) $document->getKey(),
            'number' => $meta === null ? null : (string) $document->getAttribute($meta['number']),
            'date' => $date instanceof \DateTimeInterface ? Carbon::parse($date)->toDateString() : null,
            'amount' => $meta === null ? null : round((float) $document->getAttribute($meta['amount']), 2),
            'status' => $document->getAttribute('status'),
            'status_label' => $this->statusLabel($document),
        ];
    }

    protected function statusLabel(Model $document): ?string
    {
        $status = $document->getAttribute('status');

        if (! is_string($status)) {
            return null;
        }

        // Model finance menamai label status lewat metode, bukan konstanta yang
        // dijamin ada — `method_exists` lebih jujur daripada mengakses konstanta
        // yang mungkin tidak pernah didefinisikan.
        return method_exists($document, 'statusLabel') ? $document->statusLabel() : $status;
    }
}
