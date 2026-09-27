<?php

namespace App\Services\Finance;

use App\Models\FinanceAdjustment;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceRefund;
use App\Models\FinanceVendorInvoice;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lapisan akuntansi Phase 3 (§6): mengubah PERISTIWA BISNIS menjadi jurnal
 * double-entry, lengkap dengan pemetaan akun dan basis pengakuan.
 *
 * Pembagian kerja:
 * - `FinancialTransactionEngine` = mekanisme (seimbang, periode open, akun daun,
 *   saldo kas/bank bergerak atomik). Satu-satunya yang menulis tabel jurnal.
 * - `AccountingPolicyService` = kebijakan (accrual/cash, kode COA tiap peran).
 * - kelas ini = kamus peristiwa: invoice terbit, pembayaran, refund, hutang
 *   vendor, write-off, pembalikan. Service bisnis memanggil SATU metode sesuai
 *   peristiwa yang baru terjadi, jadi tidak ada lagi controller/service yang
 *   menyusun baris debit/kredit sendiri (§6).
 *
 * Aturan yang dijaga di sini:
 * - §7/§12: pendapatan dicatat SEKALI. accrual → saat invoice; cash → saat uang
 *   masuk. Tidak keduanya.
 * - §17: satu akun piutang boleh dipakai, tapi rincian alokasi per invoice tetap
 *   ikut tertulis di `metadata` supaya penelusuran level invoice tidak hilang.
 * - §18: dana gateway bisa ditahan di akun clearing sebelum masuk bank.
 * - §34: jurnal asli tidak pernah diedit; pembalikan = jurnal baru.
 */
class JournalPostingService
{
    public function __construct(
        private readonly FinancialTransactionEngine $ledger,
        private readonly AccountingPolicyService $policy,
    ) {}

    // ==================== Pendapatan & piutang ====================

    /**
     * §8–§11: invoice terbit pada basis accrual → Debit Piutang, Kredit
     * Pendapatan per akun COA tiap item (bukan satu akun generik).
     *
     * Pada basis cash invoice TIDAK menjurnal apa pun: pengakuan pendapatan
     * menunggu uang masuk di `paymentSettlement()`.
     */
    public function invoiceIssuance(FinanceInvoice $invoice, ?User $actor = null): ?FinancialTransaction
    {
        if ($this->policy->isCashBasis()) {
            return null;
        }

        $revenueByAccount = $this->revenueByAccount($invoice);
        $total = round(array_sum($revenueByAccount), 2);

        if ($total <= 0.0001) {
            return null; // Invoice nol (diskon/beasiswa penuh) → tidak ada pendapatan diakui.
        }

        $number = $invoice->invoice_number;
        $lines = [$this->ledger->debit($this->policy->receivableAccountId(), $total, "Piutang {$number}")];

        foreach ($revenueByAccount as $accountId => $amount) {
            $lines[] = $this->ledger->credit($accountId, $amount, "Pendapatan {$number}");
        }

        return $this->ledger->post(FinancialTransaction::TYPE_INVOICE, "Pengakuan pendapatan {$number}", $lines, [
            'date' => $invoice->issue_date ?? Carbon::now(),
            'reference_type' => FinanceInvoice::class,
            'reference_id' => $invoice->id,
            'cost_center_id' => $invoice->cost_center_id,
            'created_by' => $actor?->id ?? $invoice->created_by,
            'metadata' => $this->metadata([
                'document' => ['type' => FinanceInvoice::class, 'id' => $invoice->id, 'number' => $number],
                'student_id' => $invoice->student_id,
                'billing_id' => $invoice->billing_id,
                'basis' => $this->policy->basis(),
            ]),
        ]);
    }

    /**
     * §12–§17: uang diterima untuk satu payment.
     *
     * accrual: Debit Kas/Bank, Kredit Piutang sebesar yang benar-benar
     * dialokasikan, Kredit Titipan/Deposit sebesar kelebihan yang belum
     * dialokasikan (§19 Phase 2 — invoice tidak pernah dikredit melebihi
     * sisa tagihannya, dan saldo tidak pernah dibuat negatif).
     *
     * cash: pendapatan diakui di sini — Kredit Pendapatan per akun item invoice
     * yang dialokasikan (proporsional), bukan Kredit Piutang, karena piutangnya
     * memang tidak pernah dicatat.
     *
     * `useGatewayClearing()` + metode gateway/VA: sisi debet pindah ke akun
     * clearing dan saldo kas/bank konkret TIDAK bergerak, karena uangnya masih
     * di penyedia (§18). Penyelesaiannya lewat `gatewaySettlement()`.
     *
     * @param  Collection<int, object>  $allocations  baris alokasi (invoice_id + amount)
     */
    public function paymentSettlement(
        FinancePayment $payment,
        FinanceCashAccount|FinanceBankAccount|null $into,
        Collection $allocations,
        Carbon|string|null $paidAt = null,
        ?User $actor = null,
    ): ?FinancialTransaction {
        $amount = round((float) $payment->amount, 2);

        if ($amount <= 0.0001) {
            return null;
        }

        $allocated = round((float) $allocations->sum('amount'), 2);
        $unallocated = round($amount - $allocated, 2);

        // Pembayaran tanpa invoice & tanpa alokasi: seluruhnya dianggap pelunasan
        // piutang (perilaku lama, supaya jurnal tidak tiba-tiba hilang).
        if ($allocations->isEmpty() && $payment->invoice_id === null) {
            $allocated = $amount;
            $unallocated = 0.0;
        }

        if ($unallocated < -0.0001) {
            throw ValidationException::withMessages([
                'allocations' => 'Total alokasi melebihi nominal pembayaran; jurnal tidak bisa dibuat.',
            ]);
        }

        $description = "Pembayaran {$payment->payment_number}";
        $viaClearing = $this->policy->usesGatewayClearing() && $this->policy->isGatewayMethod((string) $payment->method);

        $lines = [];

        if ($viaClearing) {
            // Debit akun clearing COA; tidak ada pergerakan saldo subledger.
            $lines[] = $this->ledger->debit($this->policy->gatewayClearingAccountId(), $amount, $description);
        } else {
            if ($into === null) {
                return null; // Tanpa sumber dana konkret tidak ada jurnal kas yang bisa dibuat.
            }

            $lines[] = $this->ledger->debit($this->coaId($into), $amount, $description);
        }

        if ($this->policy->isCashBasis()) {
            $revenueByAccount = $this->revenueByAllocation($allocations, $allocated);

            // Invoice tanpa baris (data lama) tetap harus balance — akui di
            // pendapatan Lain-lain alih-alih membiarkan jurnal debit saja.
            if ($revenueByAccount === [] && $allocated > 0.0001) {
                $revenueByAccount = [$this->policy->revenueAccountId(null) => $allocated];
            }

            foreach ($revenueByAccount as $accountId => $credit) {
                $lines[] = $this->ledger->credit($accountId, $credit, "Pendapatan {$payment->payment_number}");
            }
        } elseif ($allocated > 0.0001) {
            $lines[] = $this->ledger->credit($this->policy->receivableAccountId(), $allocated, $description);
        }

        if ($unallocated > 0.0001) {
            $lines[] = $this->ledger->credit(
                $this->policy->depositAccountId(),
                $unallocated,
                "Kelebihan pembayaran belum dialokasikan {$payment->payment_number}",
            );
        }

        $context = [
            'date' => $paidAt ?? $payment->paid_at ?? Carbon::now(),
            'reference_type' => FinancePayment::class,
            'reference_id' => $payment->id,
            'cost_center_id' => $payment->cost_center_id,
            'created_by' => $actor?->id ?? $payment->created_by,
            'metadata' => $this->metadata([
                'document' => ['type' => FinancePayment::class, 'id' => $payment->id, 'number' => $payment->payment_number],
                'invoice_id' => $payment->invoice_id,
                'student_id' => $payment->student_id,
                'method' => $payment->method,
                'basis' => $this->policy->basis(),
                'clearing_account' => $viaClearing,
                // §17: rincian per invoice tetap tersimpan walau akun AR-nya satu.
                'allocations' => $allocations->map(fn ($a): array => [
                    'invoice_id' => (int) $a->invoice_id,
                    'amount' => round((float) $a->amount, 2),
                ])->values()->all(),
            ]),
        ];

        if (! $viaClearing && $into !== null) {
            $context['balances'] = [['account' => $into, 'delta' => $amount]];
        }

        return $this->ledger->post(FinancialTransaction::TYPE_PAYMENT, $description, $lines, $context);
    }

    /**
     * §18 langkah dua: dana gateway yang sudah masuk rekening sekolah.
     * Debit Bank, Kredit akun clearing — uang berpindah tempat, bukan pendapatan.
     */
    public function gatewaySettlement(
        FinanceCashAccount|FinanceBankAccount $into,
        float $amount,
        string $description,
        array $context = [],
    ): FinancialTransaction {
        $amount = round($amount, 2);

        $lines = [
            $this->ledger->debit($this->coaId($into), $amount, $description),
            $this->ledger->credit($this->policy->gatewayClearingAccountId(), $amount, $description),
        ];

        $context['balances'] = array_merge($context['balances'] ?? [], [
            ['account' => $into, 'delta' => $amount],
        ]);

        return $this->ledger->post(FinancialTransaction::TYPE_TRANSFER, $description, $lines, $context);
    }

    // ==================== Uang keluar ====================

    /**
     * §25, §31: refund keluar dari kas/bank. Piutang di-debit (dana yang dulu
     * mengurangi piutang santri dikembalikan menjadi piutang) — pembayaran
     * aslinya tetap utuh sebagai jejak audit.
     */
    public function refund(FinanceRefund $refund, FinanceCashAccount|FinanceBankAccount $from, ?User $actor = null): FinancialTransaction
    {
        $amount = round((float) $refund->amount, 2);
        $description = "Refund {$refund->refund_number}";

        return $this->ledger->cashDisbursement(
            $from,
            $this->policy->receivableAccountId(),
            $amount,
            $description,
            [
                'type' => FinancialTransaction::TYPE_REFUND,
                'date' => $refund->processed_at ?? Carbon::now(),
                'reference_type' => FinanceRefund::class,
                'reference_id' => $refund->id,
                'created_by' => $actor?->id ?? $refund->requested_by,
                'metadata' => $this->metadata([
                    'document' => ['type' => FinanceRefund::class, 'id' => $refund->id, 'number' => $refund->refund_number],
                    'payment_id' => $refund->payment_id,
                    'reason' => $refund->reason,
                ]),
            ],
        );
    }

    /**
     * §27: invoice vendor diterima → Debit beban/aset (akun di dokumen), Kredit
     * Hutang Usaha. Belum ada uang keluar.
     */
    public function payable(FinanceVendorInvoice $invoice, ?User $actor = null): FinancialTransaction
    {
        if ($invoice->account_id === null) {
            throw ValidationException::withMessages([
                'account_id' => "Invoice vendor {$invoice->vendor_invoice_number} belum punya akun beban/aset; jurnal tidak bisa dibuat.",
            ]);
        }

        $description = "Hutang invoice vendor {$invoice->vendor_invoice_number}";
        $amount = round((float) $invoice->total, 2);

        return $this->ledger->post(FinancialTransaction::TYPE_PAYABLE, $description, [
            $this->ledger->debit((int) $invoice->account_id, $amount, $invoice->notes),
            $this->ledger->credit($this->policy->payableAccountId(), $amount, $invoice->notes),
        ], [
            'date' => $invoice->invoice_date,
            'reference_type' => FinanceVendorInvoice::class,
            'reference_id' => $invoice->id,
            'cost_center_id' => $invoice->cost_center_id,
            'created_by' => $actor?->id ?? $invoice->created_by,
            'metadata' => $this->metadata([
                'document' => ['type' => FinanceVendorInvoice::class, 'id' => $invoice->id, 'number' => $invoice->vendor_invoice_number],
                'vendor_id' => $invoice->vendor_id,
            ]),
        ]);
    }

    /**
     * §28: hutang vendor dibayar → Debit Hutang Usaha, Kredit Kas/Bank.
     * Pendapatan/beban tidak diulang; bebannya sudah diakui saat invoice diterima.
     */
    public function payableSettlement(
        FinanceVendorInvoice $invoice,
        FinanceCashAccount|FinanceBankAccount $from,
        float $amount,
        Carbon|string|null $paidAt = null,
        ?User $actor = null,
    ): FinancialTransaction {
        $amount = round($amount, 2);
        $description = "Pembayaran invoice vendor {$invoice->vendor_invoice_number}";

        return $this->ledger->cashDisbursement($from, $this->policy->payableAccountId(), $amount, $description, [
            'type' => FinancialTransaction::TYPE_PAYMENT,
            'date' => $paidAt ?? Carbon::now(),
            'reference_type' => FinanceVendorInvoice::class,
            'reference_id' => $invoice->id,
            'cost_center_id' => $invoice->cost_center_id,
            'created_by' => $actor?->id ?? $invoice->created_by,
            'metadata' => $this->metadata([
                'document' => [
                    'type' => FinanceVendorInvoice::class,
                    'id' => $invoice->id,
                    'number' => $invoice->vendor_invoice_number,
                ],
                'vendor_id' => $invoice->vendor_id,
            ]),
        ]);
    }

    /** §33: piutang dihapus jadi beban. Debit Beban Write-Off, Kredit Piutang. */
    public function writeOff(FinanceAdjustment $adjustment, FinanceInvoice $invoice, ?User $actor = null): FinancialTransaction
    {
        $amount = round((float) $adjustment->amount, 2);
        $description = "Write-off piutang {$invoice->invoice_number}: {$adjustment->reason}";

        return $this->ledger->post(FinancialTransaction::TYPE_WRITE_OFF, $description, [
            $this->ledger->debit($this->policy->writeOffExpenseAccountId(), $amount, $description),
            $this->ledger->credit($this->policy->receivableAccountId(), $amount, "Piutang {$invoice->invoice_number}"),
        ], [
            'date' => $adjustment->adjustment_date,
            'reference_type' => FinanceAdjustment::class,
            'reference_id' => $adjustment->id,
            'cost_center_id' => $adjustment->cost_center_id ?? $invoice->cost_center_id,
            'created_by' => $actor?->id ?? $adjustment->requested_by,
            'metadata' => $this->metadata([
                'document' => ['type' => FinanceAdjustment::class, 'id' => $adjustment->id, 'number' => $adjustment->adjustment_number],
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'reason' => $adjustment->reason,
            ]),
        ]);
    }

    /**
     * §32: koreksi manual ber-approval antara dua akun COA. Arah `increase`
     * berarti akun yang dikoreksi di-debit; `decrease` sebaliknya. Service bisnis
     * tetap tidak menyusun baris debit/kredit sendiri.
     *
     * @param  list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>  $balances
     */
    public function correction(FinanceAdjustment $adjustment, ?User $actor = null, array $balances = []): FinancialTransaction
    {
        $amount = round((float) $adjustment->amount, 2);
        $increase = $adjustment->direction === FinanceAdjustment::DIRECTION_INCREASE;
        $description = "Adjustment {$adjustment->adjustment_number}: {$adjustment->reason}";

        $lines = $increase
            ? [
                $this->ledger->debit((int) $adjustment->account_id, $amount, $adjustment->reason),
                $this->ledger->credit((int) $adjustment->contra_account_id, $amount, $adjustment->reason),
            ]
            : [
                $this->ledger->credit((int) $adjustment->account_id, $amount, $adjustment->reason),
                $this->ledger->debit((int) $adjustment->contra_account_id, $amount, $adjustment->reason),
            ];

        return $this->ledger->post(FinancialTransaction::TYPE_ADJUSTMENT, $description, $lines, [
            'date' => $adjustment->adjustment_date,
            'reference_type' => FinanceAdjustment::class,
            'reference_id' => $adjustment->id,
            'cost_center_id' => $adjustment->cost_center_id,
            'created_by' => $actor?->id ?? $adjustment->requested_by,
            'balances' => $balances,
            'metadata' => $this->metadata([
                'document' => [
                    'type' => FinanceAdjustment::class,
                    'id' => $adjustment->id,
                    'number' => $adjustment->adjustment_number,
                ],
                'direction' => $adjustment->direction,
                'reason' => $adjustment->reason,
            ]),
        ]);
    }

    // ==================== Pembalikan ====================

    /**
     * §34/§41/§51: batalkan jurnal dengan baris lawan. Jurnal asli tetap ada.
     *
     * Kalau jurnal menyentuh kas/bank konkret, pemanggil WAJIB menyuplai
     * `balances` (akun konkret + delta pembalik) — engine sengaja tidak menebak
     * kas/bank mana yang bergerak, karena satu COA bisa menaungi beberapa
     * rekening. Tanpa itu, pembalikan diam-diam meninggalkan saldo subledger
     * tidak cocok dengan buku besar (§61).
     *
     * @param  list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>  $balances
     */
    public function reverse(FinancialTransaction $journal, string $reason, ?User $actor = null, array $balances = []): FinancialTransaction
    {
        if ($balances === [] && $this->touchesCashOrBank($journal)) {
            throw ValidationException::withMessages([
                'journal' => "Jurnal {$journal->transaction_number} menggerakkan saldo kas/bank; batalkan lewat dokumen sumbernya ".
                    '(batal pembayaran / refund / mutasi) agar saldo rekening ikut dibalik.',
            ]);
        }

        return $this->ledger->reverse($journal, $reason, $actor?->id, $balances);
    }

    // ==================== Pemetaan & pembagian nominal ====================

    /**
     * Pendapatan per akun COA untuk satu invoice, diambil dari baris item
     * (`finance_invoice_items.account_id` sudah menyimpan pemetaan fee type).
     *
     * @return array<int, float>
     */
    public function revenueByAccount(FinanceInvoice $invoice): array
    {
        $invoice->loadMissing('items.feeType');

        $byAccount = [];

        foreach ($invoice->items as $item) {
            $amount = round((float) $item->total, 2);

            if ($amount <= 0.0001) {
                continue;
            }

            $accountId = (int) ($item->account_id ?? $this->policy->revenueAccountId($item->feeType));
            $byAccount[$accountId] = round(($byAccount[$accountId] ?? 0) + $amount, 2);
        }

        return $byAccount;
    }

    /**
     * §16 pada basis cash: sebagian pelunasan dibagi proporsional ke akun
     * pendapatan item invoice-nya, sisa pembulatan dilempar ke akun terakhir
     * supaya total kredit == nominal dialokasikan (jurnal wajib seimbang).
     *
     * @param  Collection<int, object>  $allocations
     * @return array<int, float>
     */
    public function revenueByAllocation(Collection $allocations, float $allocated): array
    {
        if ($allocated <= 0.0001 || $allocations->isEmpty()) {
            return [];
        }

        $invoices = FinanceInvoice::query()
            ->with('items.feeType')
            ->whereIn('id', $allocations->map(fn ($a): int => (int) $a->invoice_id)->unique()->all())
            ->get()
            ->keyBy('id');

        $byAccount = [];

        foreach ($allocations as $allocation) {
            $invoice = $invoices->get((int) $allocation->invoice_id);

            if ($invoice === null) {
                continue;
            }

            foreach ($this->revenueSplit($invoice, round((float) $allocation->amount, 2)) as $accountId => $amount) {
                $byAccount[$accountId] = round(($byAccount[$accountId] ?? 0) + $amount, 2);
            }
        }

        return $byAccount;
    }

    /**
     * Pecah satu nominal pembayaran ke akun pendapatan item invoice secara
     * proporsional terhadap nilai tiap item.
     *
     * @return array<int, float>
     */
    public function revenueSplit(FinanceInvoice $invoice, float $amount): array
    {
        $invoice->loadMissing('items.feeType');

        $items = $invoice->items->filter(fn ($item): bool => round((float) $item->total, 2) > 0.0001)->values();
        $base = round((float) $items->sum(fn ($item): float => round((float) $item->total, 2)), 2);

        if ($base <= 0.0001 || $amount <= 0.0001) {
            return [];
        }

        $target = round(min($amount, $base), 2);
        $byAccount = [];
        $assigned = 0.0;

        foreach ($items as $index => $item) {
            $accountId = (int) ($item->account_id ?? $this->policy->revenueAccountId($item->feeType));
            $isLast = $index === $items->count() - 1;
            $cut = $isLast
                ? round($target - $assigned, 2)
                : round($target * (round((float) $item->total, 2) / $base), 2);

            if ($cut <= 0.0001) {
                continue;
            }

            $byAccount[$accountId] = round(($byAccount[$accountId] ?? 0) + $cut, 2);
            $assigned = round($assigned + $cut, 2);
        }

        return $byAccount;
    }

    /** COA control account yang menaungi akun kas/bank konkret. */
    protected function coaId(FinanceCashAccount|FinanceBankAccount $account): int
    {
        return (int) ($account instanceof FinanceBankAccount
            ? $account->finance_account_id
            : $account->account_id);
    }

    /** Apakah jurnal ini menggerakkan akun COA yang punya rekening konkret? */
    protected function touchesCashOrBank(FinancialTransaction $journal): bool
    {
        $journal->loadMissing('lines');

        $accountIds = $journal->lines->pluck('account_id')->filter()->unique()->all();

        if ($accountIds === []) {
            return false;
        }

        $cash = FinanceCashAccount::query()->whereIn('account_id', $accountIds)->exists();
        $bank = FinanceBankAccount::query()->whereIn('finance_account_id', $accountIds)->exists();

        return $cash || $bank;
    }

    /**
     * Metadata seragam untuk penelusuran §38: dokumen sumber + siapa yang
     * menghasilkan jurnal ini.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function metadata(array $metadata): array
    {
        return array_merge(['basis' => $this->policy->basis()], $metadata);
    }
}
