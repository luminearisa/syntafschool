<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceVendorInvoice;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoice vendor (§35) + Accounts Payable (§36).
 *
 * Saat invoice vendor diterima, hutang diakui: jurnal akrual diposting
 * (Dr akun beban/aset, Cr Hutang 2100) — belum ada uang keluar. `remaining_amount`
 * per vendor = AP outstanding. Saat dibayar, jurnal keluar diposting
 * (Dr Hutang 2100, Cr Kas/Bank) dan `paid_amount`/`remaining_amount`/status
 * diperbarui. Pembayaran boleh sebagian (partially_paid).
 *
 * Invoice yang sudah ada pembayaran tidak boleh dibatalkan; pembatalan hanya
 * untuk invoice tanpa pembayaran dan membalik jurnal akrualnya (§51 — reversal,
 * bukan hapus/edit jurnal lama).
 */
class FinanceVendorInvoiceService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly JournalPostingService $posting,
    ) {}

    /**
     * Terima invoice vendor (§35). Total = subtotal + tax. Bila status `received`,
     * jurnal akrual hutang langsung diposting.
     *
     * @param  array{vendor_id: int, invoice_date?: Carbon|string|null, due_date?: Carbon|string|null,
     *               subtotal: float, tax?: float|null, account_id?: int|null, cost_center_id?: int|null,
     *               purchase_order_id?: int|null, expense_request_id?: int|null, notes?: string|null,
     *               status?: string}  $data
     */
    public function receive(array $data, ?User $actor = null): FinanceVendorInvoice
    {
        $subtotal = round((float) ($data['subtotal'] ?? 0), 2);
        $tax = round((float) ($data['tax'] ?? 0), 2);

        if ($subtotal < 0 || $tax < 0) {
            throw ValidationException::withMessages(['subtotal' => 'Subtotal dan pajak tidak boleh negatif.']);
        }

        $total = round($subtotal + $tax, 2);

        if ($total <= 0) {
            throw ValidationException::withMessages(['subtotal' => 'Total invoice vendor harus lebih dari nol.']);
        }

        $accountId = $data['account_id'] ?? null;
        $status = $data['status'] ?? FinanceVendorInvoice::STATUS_RECEIVED;

        if ($status === FinanceVendorInvoice::STATUS_RECEIVED && $accountId === null) {
            throw ValidationException::withMessages(['account_id' => 'Akun beban/aset tujuan wajib diisi untuk invoice yang langsung diterima.']);
        }

        return DB::transaction(function () use ($data, $subtotal, $tax, $total, $accountId, $status, $actor): FinanceVendorInvoice {
            $invoice = FinanceVendorInvoice::create([
                'vendor_invoice_number' => $this->numbering->next(FinanceNumberingService::CODE_VENDOR_INVOICE),
                'vendor_id' => (int) $data['vendor_id'],
                'invoice_date' => $data['invoice_date'] ?? Carbon::now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'paid_amount' => 0,
                'remaining_amount' => $total,
                'status' => $status,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'expense_request_id' => $data['expense_request_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'account_id' => $accountId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            if ($status === FinanceVendorInvoice::STATUS_RECEIVED) {
                $this->accruePayable($invoice, $actor);
            }

            return $invoice->fresh();
        });
    }

    /** Tandai invoice draft jadi `received` dan akui hutangnya. */
    public function markReceived(FinanceVendorInvoice $invoice, ?User $actor = null): FinanceVendorInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): FinanceVendorInvoice {
            $fresh = $this->lock($invoice);

            FinanceStatusMachine::assert($fresh, FinanceVendorInvoice::STATUS_RECEIVED);

            if ($fresh->account_id === null) {
                throw ValidationException::withMessages(['account_id' => 'Akun beban/aset tujuan wajib diisi sebelum invoice diterima.']);
            }

            $fresh->status = FinanceVendorInvoice::STATUS_RECEIVED;
            $fresh->save();

            $this->accruePayable($fresh, $actor);

            return $fresh->fresh();
        });
    }

    /**
     * Bayar (sebagian/seluruh) invoice vendor (§36): Dr Hutang 2100, Cr Kas/Bank.
     *
     * @param  array{amount: float, cash_account_id?: int|null, bank_account_id?: int|null,
     *               paid_date?: Carbon|string|null, notes?: string|null}  $data
     */
    public function pay(FinanceVendorInvoice $invoice, array $data, ?User $actor = null): FinanceVendorInvoice
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus lebih dari nol.']);
        }

        return DB::transaction(function () use ($invoice, $amount, $data, $actor): FinanceVendorInvoice {
            $fresh = $this->lock($invoice);

            if (! FinanceStatusMachine::can($fresh, FinanceVendorInvoice::STATUS_PAID)) {
                throw ValidationException::withMessages(['status' => "Invoice vendor berstatus {$fresh->status} tidak bisa dibayar."]);
            }

            $remaining = round((float) $fresh->remaining_amount, 2);

            if ($amount > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Pembayaran melebihi sisa hutang invoice (Rp '.number_format($remaining, 0, ',', '.').').',
                ]);
            }

            $account = $this->settlementAccount($data['cash_account_id'] ?? null, $data['bank_account_id'] ?? null);

            if ($account === null) {
                throw ValidationException::withMessages(['cash_account_id' => 'Tentukan akun kas atau bank sumber pembayaran.']);
            }

            $this->posting->payableSettlement(
                $fresh,
                $account,
                $amount,
                $data['paid_date'] ?? Carbon::now(),
                $actor,
            );

            $paid = round((float) $fresh->paid_amount + $amount, 2);
            $total = round((float) $fresh->total, 2);

            $fresh->paid_amount = $paid;
            $fresh->remaining_amount = round(max(0, $total - $paid), 2);
            $status = $fresh->remaining_amount <= 0.0001
                ? FinanceVendorInvoice::STATUS_PAID
                : FinanceVendorInvoice::STATUS_PARTIALLY_PAID;
            FinanceStatusMachine::assert($fresh, $status);
            $fresh->status = $status;
            $fresh->save();

            return $fresh->fresh();
        });
    }

    /** Batalkan invoice tanpa pembayaran: balik jurnal akrualnya, tandai cancelled (§51). */
    public function cancel(FinanceVendorInvoice $invoice, string $reason, ?User $actor = null): FinanceVendorInvoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): FinanceVendorInvoice {
            $fresh = $this->lock($invoice);

            if (round((float) $fresh->paid_amount, 2) > 0.0001) {
                throw ValidationException::withMessages([
                    'status' => 'Invoice vendor yang sudah ada pembayaran tidak bisa dibatalkan; gunakan refund/adjustment.',
                ]);
            }

            FinanceStatusMachine::assert($fresh, FinanceVendorInvoice::STATUS_CANCELLED);

            // Yang dibalik hanya jurnal AKRUAL hutang (§28). Jurnal pembayaran
            // ikut merujuk invoice yang sama, jadi tanpa filter type pembatalan
            // bisa membalik kas keluar — bukan pengakuan hutangnya.
            $journal = FinancialTransaction::query()
                ->where('reference_type', FinanceVendorInvoice::class)
                ->where('reference_id', $fresh->id)
                ->where('type', FinancialTransaction::TYPE_PAYABLE)
                ->where('status', FinancialTransaction::STATUS_POSTED)
                ->first();

            if ($journal !== null) {
                $this->posting->reverse($journal, $reason, $actor);
            }

            $fresh->status = FinanceVendorInvoice::STATUS_CANCELLED;
            $fresh->notes = trim(($fresh->notes ? $fresh->notes.' | ' : '').'Dibatalkan: '.$reason);
            $fresh->save();

            return $fresh->fresh();
        });
    }

    // ==================== Internal ====================

    /** Akui hutang: Dr akun beban/aset, Cr Hutang Usaha (belum ada uang keluar). */
    protected function accruePayable(FinanceVendorInvoice $invoice, ?User $actor): void
    {
        $this->posting->payable($invoice, $actor);
    }

    protected function settlementAccount(mixed $cashId, mixed $bankId): FinanceCashAccount|FinanceBankAccount|null
    {
        if ($cashId !== null) {
            return FinanceCashAccount::query()->whereKey($cashId)->first();
        }

        if ($bankId !== null) {
            return FinanceBankAccount::query()->whereKey($bankId)->first();
        }

        return null;
    }

    protected function lock(FinanceVendorInvoice $invoice): FinanceVendorInvoice
    {
        return FinanceVendorInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }
}
