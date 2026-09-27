<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceCashAccount;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceRefund;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refund / pengembalian dana (§29).
 *
 * Payment asli TIDAK dihapus. Refund berjalan `requested → approved → completed`
 * (atau `rejected`). Saat `completed`, jurnal balik diposting (Dr Piutang 1300,
 * Cr Kas/Bank — uang keluar) dan penerapan ke invoice/billing dikurangi sebesar
 * nominal refund, sehingga sisa tagihan hidup kembali.
 */
class FinanceRefundService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly JournalPostingService $posting,
        private readonly FinanceOutstandingRecalculator $balances,
        private readonly FinanceNotificationService $notifications,
    ) {}

    /**
     * Ajukan refund atas sebuah payment yang sudah lunas/sebagian.
     *
     * @param  array{amount: float, reason: string, cash_account_id?: int|null,
     *               bank_account_id?: int|null, requested_by?: int|null}  $data
     */
    public function request(FinancePayment $payment, array $data, ?User $actor = null): FinanceRefund
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal refund harus lebih dari nol.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan refund wajib diisi.']);
        }

        return DB::transaction(function () use ($payment, $amount, $reason, $data, $actor): FinanceRefund {
            $locked = FinancePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [FinancePayment::STATUS_PAID, FinancePayment::STATUS_PARTIAL, FinancePayment::STATUS_REFUNDED], true)) {
                throw ValidationException::withMessages([
                    'payment' => 'Refund hanya untuk pembayaran yang sudah lunas.',
                ]);
            }

            $refunded = round((float) $locked->refunds()
                ->whereIn('status', [FinanceRefund::STATUS_REQUESTED, FinanceRefund::STATUS_APPROVED, FinanceRefund::STATUS_PROCESSING, FinanceRefund::STATUS_COMPLETED])
                ->sum('amount'), 2);

            if ($amount + $refunded > round((float) $locked->amount, 2) + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Total refund melebihi nominal pembayaran.',
                ]);
            }

            return FinanceRefund::create([
                'refund_number' => $this->numbering->next(FinanceNumberingService::CODE_REFUND),
                'payment_id' => $locked->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => FinanceRefund::STATUS_REQUESTED,
                'cash_account_id' => $data['cash_account_id'] ?? $locked->cash_account_id,
                'bank_account_id' => $data['bank_account_id'] ?? $locked->bank_account_id,
                'requested_by' => $data['requested_by'] ?? $actor?->id,
            ]);
        });
    }

    public function approve(FinanceRefund $refund, ?User $actor = null): FinanceRefund
    {
        return DB::transaction(function () use ($refund, $actor): FinanceRefund {
            $locked = FinanceRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinanceRefund::STATUS_APPROVED, 'refund');

            $locked->status = FinanceRefund::STATUS_APPROVED;
            $locked->approved_by = $actor?->id;
            $locked->approved_at = Carbon::now();
            $locked->save();

            $approved = $locked->fresh();
            $this->notifications->refundApproved($approved);

            return $approved;
        });
    }

    public function reject(FinanceRefund $refund, string $reason, ?User $actor = null): FinanceRefund
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($refund, $reason, $actor): FinanceRefund {
            $locked = FinanceRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinanceRefund::STATUS_REJECTED, 'refund');

            $locked->status = FinanceRefund::STATUS_REJECTED;
            $locked->reject_reason = $reason;
            $locked->approved_by = $actor?->id;
            $locked->approved_at = Carbon::now();
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Selesaikan refund yang sudah disetujui: posting jurnal keluar (Dr Piutang,
     * Cr Kas/Bank) dan kurangi penerapan pembayaran pada invoice/billing.
     */
    public function complete(FinanceRefund $refund, ?User $actor = null): FinanceRefund
    {
        return DB::transaction(function () use ($refund, $actor): FinanceRefund {
            $locked = FinanceRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinanceRefund::STATUS_COMPLETED, 'refund');

            $amount = round((float) $locked->amount, 2);
            $account = $this->settlementAccount($locked);

            if ($account === null) {
                throw ValidationException::withMessages(['cash_account_id' => 'Akun kas/bank sumber refund belum ditentukan.']);
            }

            $journal = $this->posting->refund($locked, $account, $actor);

            $this->adjustInvoice($locked, -$amount);

            $locked->status = FinanceRefund::STATUS_COMPLETED;
            $locked->finance_transaction_id = $journal->id;
            $locked->processed_at = Carbon::now();
            $locked->save();

            $this->refreshPaymentStatus($locked->payment_id);

            $completed = $locked->fresh();
            $this->notifications->refundCompleted($completed);

            return $completed;
        });
    }

    // ==================== Internal ====================

    private function adjustInvoice(FinanceRefund $refund, float $delta): void
    {
        $payment = FinancePayment::query()->whereKey($refund->payment_id)->first();

        if ($payment === null) {
            return;
        }

        if ($payment->invoice_id !== null) {
            $invoice = FinanceInvoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->first();

            if ($invoice !== null) {
                $this->balances->applyPaidDelta($invoice, $delta);
            }
        }

        if ($payment->billing_id !== null) {
            $billing = FinanceBilling::query()->whereKey($payment->billing_id)->lockForUpdate()->first();

            if ($billing !== null) {
                $this->balances->applyPaidDelta($billing, $delta);
            }
        }
    }

    /** Set status payment jadi `refunded` bila seluruh nominalnya sudah dikembalikan. */
    private function refreshPaymentStatus(int $paymentId): void
    {
        $payment = FinancePayment::query()->whereKey($paymentId)->lockForUpdate()->first();

        if ($payment === null) {
            return;
        }

        $refunded = round((float) $payment->refunds()->where('status', FinanceRefund::STATUS_COMPLETED)->sum('amount'), 2);
        $total = round((float) $payment->amount, 2);

        if ($refunded + 0.0001 >= $total) {
            $payment->status = FinancePayment::STATUS_REFUNDED;
        } elseif ($refunded > 0 && $payment->status === FinancePayment::STATUS_PAID) {
            $payment->status = FinancePayment::STATUS_PARTIAL;
        }

        $payment->save();
    }

    private function settlementAccount(FinanceRefund $refund): FinanceCashAccount|FinanceBankAccount|null
    {
        if ($refund->cash_account_id !== null) {
            return FinanceCashAccount::query()->whereKey($refund->cash_account_id)->first();
        }

        if ($refund->bank_account_id !== null) {
            return FinanceBankAccount::query()->whereKey($refund->bank_account_id)->first();
        }

        return null;
    }
}
