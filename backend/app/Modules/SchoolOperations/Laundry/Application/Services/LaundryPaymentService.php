<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\RfidProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\WalletProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\FinanceProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryPaymentCompleted;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryPaymentFailed;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryRefundCompleted;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryRefundRequested;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPayment;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPaymentAttempt;
use App\Modules\SchoolOperations\Laundry\Models\LaundryRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran laundry berbayar lewat Wallet (§19–§23, §54–§56, §70).
 *
 * Aturan yang dijaga:
 * - Laundry tidak menyimpan saldo (Rule 10) — preview & charge selalu membaca
 *   WalletProvider.
 * - Charge idempotent (Rule 11/25): kunci (order + idempotency_key) unik di
 *   `laundry_payment_attempts`; replay mengembalikan hasil yang sama.
 * - Payment gagal tidak pernah dianggap paid (Rule 12); sukses wajib menyimpan
 *   referensi transaksi wallet (Rule 13).
 * - Refund hanya lewat WalletProvider (Rule 14).
 */
class LaundryPaymentService
{
    public function __construct(
        private readonly WalletProviderInterface $wallet,
        private readonly RfidProviderInterface $rfid,
        private readonly FinanceProviderInterface $finance,
        private readonly LaundryNotificationService $notifications,
        private readonly LaundryOrderStateMachine $state,
        private readonly LaundryAuditService $audit,
    ) {
    }

    // =====================================================================
    // PREVIEW (§54)
    // =====================================================================

    /**
     * Pratinjau tagihan sebelum pembayaran: porsi free/paid, total, saldo
     * wallet (dibaca dari provider), dan sisa saldo setelah bayar.
     */
    public function preview(LaundryOrder $order): array
    {
        $payable = $order->payableAmount();
        $account = $this->wallet->accountFor((int) $order->student_id);
        $balance = $account !== null ? LaundryMath::money($account['balance']) : null;

        return [
            'order_number' => (string) $order->order_number,
            'net_weight' => LaundryMath::quantity($order->net_weight),
            'quota_type' => $order->quota_type,
            'free_quantity' => LaundryMath::quantity($order->free_quantity),
            'paid_quantity' => LaundryMath::quantity($order->paid_quantity),
            'total_gross' => LaundryMath::money($order->total_amount),
            'discount' => LaundryMath::money($order->discount_amount),
            'payable' => $payable,
            'wallet' => $account !== null ? [
                'number' => $account['number'],
                'balance' => $balance,
                'status' => $account['status'],
                'after_payment' => $balance !== null ? LaundryMath::money($balance - $payable) : null,
                'sufficient' => $balance !== null && $balance >= $payable,
            ] : null,
            'payment_status' => (string) $order->payment_status,
        ];
    }

    // =====================================================================
    // CHARGE (§20, §22, §70)
    // =====================================================================

    /**
     * Bebani wallet santri untuk porsi berbayar order.
     *
     * @return array{success:bool, payment?:array, reason?:string, balance?:float|null, payable?:float, message?:string}
     */
    public function pay(
        LaundryOrder $order,
        string $idempotencyKey,
        ?User $actor = null,
        string $method = 'WALLET',
        ?string $rfidUid = null,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Kunci idempotensi pembayaran wajib diisi.',
            ]);
        }

        $payable = $order->payableAmount();
        if ($payable <= 0) {
            throw new LaundryDomainException("Order {$order->order_number} tidak memiliki tagihan.", [
                'order_id' => (int) $order->id,
            ]);
        }

        if (! in_array($order->payment_status, ['PAYMENT_REQUIRED', 'FAILED', 'PENDING'], true)) {
            // Sudah dibayar/dibebaskan — kembalikan pembayaran yang ada (idempoten).
            $existing = LaundryPayment::query()
                ->where('laundry_order_id', $order->id)
                ->where('status', 'PAID')
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                return ['success' => true, 'payment' => $this->presentPayment($existing), 'replayed' => true];
            }

            throw new LaundryDomainException(
                "Order {$order->order_number} tidak dalam status menunggu pembayaran ({$order->payment_status})."
            );
        }

        // §70: RFID adalah credential — bila dipakai, harus milik santri order ini.
        if ($rfidUid !== null && trim($rfidUid) !== '') {
            $this->assertRfidBelongsToStudent(trim($rfidUid), (int) $order->student_id);
            $method = 'WALLET_RFID';
        }

        // Attempt idempoten: unik (order, key). Replay tidak charge dua kali.
        $attempt = LaundryPaymentAttempt::query()
            ->where('laundry_order_id', $order->id)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($attempt !== null && $attempt->status === 'SUCCESS') {
            $payment = LaundryPayment::query()->where('laundry_payment_attempt_id', $attempt->id)->first();
            if ($payment !== null) {
                return ['success' => true, 'payment' => $this->presentPayment($payment), 'replayed' => true];
            }
        }

        if ($attempt === null) {
            $attempt = LaundryPaymentAttempt::create([
                'laundry_order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'attempt_number' => LaundryPaymentAttempt::query()->where('laundry_order_id', $order->id)->count() + 1,
                'status' => 'PENDING',
                'amount' => $payable,
                'requested_by' => $actor?->id,
                'requested_at' => now(),
            ]);
        } elseif ($attempt->status === 'FAILED') {
            // Retry dengan kunci sama: attempt dihidupkan ulang; charge wallet
            // tetap idempoten di sisi provider.
            $attempt->update(['status' => 'PENDING', 'amount' => $payable, 'failure_reason' => null,
                'requested_at' => now(), 'requested_by' => $actor?->id]);
        }

        $account = $this->wallet->accountFor((int) $order->student_id);
        if ($account === null) {
            return $this->fail($order, $attempt, 'NO_WALLET', $actor,
                'Santri belum memiliki wallet aktif.');
        }

        $result = $this->wallet->charge((int) $order->student_id, $payable, $idempotencyKey, [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'description' => "Pembayaran laundry {$order->order_number}",
            'actor_id' => $actor?->id,
            'rfid_uid' => $rfidUid,
        ]);

        if (! ($result['success'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'CHARGE_FAILED');

            return $this->fail($order, $attempt, $reason, $actor,
                $result['message'] ?? null,
                isset($result['balance']) ? (float) $result['balance'] : ($account['balance'] ?? null));
        }

        return DB::transaction(function () use ($order, $attempt, $result, $actor, $method, $rfidUid, $payable): array {
            $payment = LaundryPayment::create([
                'payment_number' => 'LPY-TMP',
                'laundry_order_id' => $order->id,
                'laundry_payment_attempt_id' => $attempt->id,
                'wallet_id' => $result['wallet_id'] ?? null,
                'wallet_transaction_id' => $result['wallet_transaction_id'] ?? null,
                'wallet_transaction_number' => $result['transaction_number'] ?? null,
                'amount' => $payable,
                'balance_before' => $result['balance_before'] ?? null,
                'balance_after' => $result['balance_after'] ?? null,
                'method' => $method,
                'status' => 'PAID',
                'rfid_uid' => $rfidUid,
                'paid_at' => now(),
                'paid_by' => $actor?->id,
            ]);
            $payment->update(['payment_number' => 'LPY-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

            $attempt->update([
                'status' => 'SUCCESS',
                'wallet_transaction_id' => $result['wallet_transaction_id'] ?? null,
                'wallet_transaction_number' => $result['transaction_number'] ?? null,
                'completed_at' => now(),
            ]);

            $before = ['payment_status' => $order->payment_status, 'status' => $order->status];
            $order->payment_status = 'PAID';
            $order->payment_method = $method;
            $order->wallet_transaction_id = $result['wallet_transaction_id'] ?? null;
            $order->wallet_transaction_number = $result['transaction_number'] ?? null;
            $order->save();

            // §19: hanya setelah wallet konfirmasi, order menjadi PAID.
            if (! in_array($order->status, [OrderStatus::PAID, OrderStatus::QUEUED], true)
                && ! OrderStatus::isTerminal((string) $order->status)) {
                $this->state->transitionTo($order, OrderStatus::PAID, $actor, null, $payment->payment_number);
            }

            $this->audit->record('PAYMENT_CHARGED', $order, $actor, $before, [
                'payment_status' => 'PAID', 'amount' => $payable,
                'wallet_transaction' => $payment->wallet_transaction_number,
            ], null, $payment->payment_number);

            event(new LaundryPaymentCompleted((int) $order->id, [
                'order_number' => $order->order_number,
                'amount' => $payable,
                'wallet_transaction_id' => $payment->wallet_transaction_id,
                'wallet_transaction_number' => $payment->wallet_transaction_number,
                'balance_before' => $payment->balance_before,
                'balance_after' => $payment->balance_after,
            ]));

            $this->notifications->notifyOrder($order, 'PAYMENT_COMPLETED', [
                'amount' => $payable,
                'reference' => $payment->wallet_transaction_number,
                'paid_text' => rtrim(rtrim(number_format((float) $order->paid_quantity, 3, ',', '.'), '0'), ',').' '.($order->quota_type === 'WEIGHT' ? 'kg' : 'porsi'),
            ]);

            // §46: saldo rendah setelah charge → ingatkan wali.
            $this->warnLowBalance($order, $result['balance_after'] ?? null);

            // §24: teruskan pendapatan ke Finance (source of truth akuntansi).
            $this->finance->postRevenue([
                'amount' => $payable,
                'reference' => $payment->payment_number,
                'description' => "Pendapatan laundry {$order->order_number}",
                'occurred_at' => now()->toIso8601String(),
                'metadata' => [
                    'module' => 'laundry', 'order_id' => (int) $order->id,
                    'student_id' => (int) $order->student_id,
                    'wallet_transaction' => $payment->wallet_transaction_number,
                ],
            ]);

            return ['success' => true, 'payment' => $this->presentPayment($payment->fresh())];
        });
    }

    /**
     * Refund (§23, Rule 14): selalu lewat WalletProvider, tercatat dengan
     * referensi transaksi wallet, dan order ditandai REFUNDED.
     */
    public function refund(LaundryOrder $order, string $reason, ?User $actor = null, ?float $amount = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan refund wajib diisi.']);
        }

        $payment = LaundryPayment::query()
            ->where('laundry_order_id', $order->id)
            ->where('status', 'PAID')
            ->orderByDesc('id')
            ->first();

        if ($payment === null) {
            throw new LaundryDomainException("Order {$order->order_number} tidak memiliki pembayaran untuk direfund.");
        }

        $amount = LaundryMath::money($amount ?? $payment->amount);
        if ($amount <= 0 || $amount > (float) $payment->amount) {
            throw ValidationException::withMessages(['amount' => 'Nominal refund tidak valid.']);
        }

        event(new LaundryRefundRequested((int) $order->id, [
            'order_number' => $order->order_number, 'amount' => $amount, 'reason' => $reason,
        ]));

        $idempotencyKey = 'refund:order-'.$order->id.':payment-'.$payment->id;

        $result = $this->wallet->refund((int) $order->student_id, $amount, $idempotencyKey, [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'original_transaction_id' => $payment->wallet_transaction_id,
            'description' => "Refund laundry {$order->order_number}: {$reason}",
            'reason' => $reason,
            'actor_id' => $actor?->id,
        ]);

        if (! ($result['success'] ?? false)) {
            $refund = LaundryRefund::create([
                'refund_number' => 'LRF-TMP',
                'laundry_order_id' => $order->id,
                'laundry_payment_id' => $payment->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => 'FAILED',
                'refunded_by' => $actor?->id,
            ]);
            $refund->update(['refund_number' => 'LRF-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT)]);

            throw new LaundryDomainException('Refund wallet gagal: '.($result['reason'] ?? 'UNKNOWN'), [
                'order_id' => (int) $order->id, 'reason' => $result['reason'] ?? null,
            ]);
        }

        $refund = LaundryRefund::create([
            'refund_number' => 'LRF-TMP',
            'laundry_order_id' => $order->id,
            'laundry_payment_id' => $payment->id,
            'wallet_transaction_id' => $result['wallet_transaction_id'] ?? null,
            'wallet_transaction_number' => $result['transaction_number'] ?? null,
            'amount' => $amount,
            'reason' => $reason,
            'status' => 'COMPLETED',
            'refunded_at' => now(),
            'refunded_by' => $actor?->id,
            'approved_by' => $actor?->id,
        ]);
        $refund->update(['refund_number' => 'LRF-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT)]);

        $payment->update(['status' => 'REFUNDED']);
        $order->payment_status = 'REFUNDED';
        $order->save();

        $this->audit->record('PAYMENT_REFUNDED', $order, $actor, [
            'payment_status' => 'PAID', 'amount' => (float) $payment->amount,
        ], [
            'payment_status' => 'REFUNDED', 'refund' => $refund->refund_number, 'amount' => $amount,
        ], $reason, $refund->refund_number);

        $this->finance->postRefund([
            'amount' => $amount,
            'reference' => $refund->refund_number,
            'description' => "Refund laundry {$order->order_number}",
            'occurred_at' => now()->toIso8601String(),
            'metadata' => ['module' => 'laundry', 'order_id' => (int) $order->id, 'reason' => $reason],
        ]);

        event(new LaundryRefundCompleted((int) $order->id, [
            'order_number' => $order->order_number,
            'refund_number' => $refund->refund_number,
            'amount' => $amount,
            'wallet_transaction_number' => $refund->wallet_transaction_number,
        ]));

        return ['success' => true, 'refund' => [
            'refund_number' => $refund->refund_number,
            'amount' => $amount,
            'wallet_transaction_number' => $refund->wallet_transaction_number,
            'balance_after' => $result['balance_after'] ?? null,
        ]];
    }

    // =====================================================================
    // INTERNAL
    // =====================================================================

    private function fail(LaundryOrder $order, LaundryPaymentAttempt $attempt, string $reason, ?User $actor, ?string $message = null, ?float $balance = null): array
    {
        $attempt->update([
            'status' => 'FAILED',
            'failure_reason' => $reason.($message ? ': '.$message : ''),
            'completed_at' => now(),
        ]);

        // Rule 12: gagal ≠ paid. Status order tetap menunggu pembayaran.
        if ($order->payment_status !== 'PAYMENT_REQUIRED') {
            $order->payment_status = 'PAYMENT_REQUIRED';
            $order->save();
        }

        $this->audit->record('PAYMENT_FAILED', $order, $actor, [], [
            'reason' => $reason, 'message' => $message, 'balance' => $balance,
        ], null, $order->order_number);

        event(new LaundryPaymentFailed((int) $order->id, [
            'order_number' => $order->order_number, 'reason' => $reason, 'balance' => $balance,
        ]));

        $balanceText = $balance !== null ? 'Rp '.number_format($balance, 0, ',', '.') : null;

        if ($reason === 'INSUFFICIENT_BALANCE') {
            // §21/§69: wali diberi tahu kebutuhan pembayaran & saldo saat ini.
            $this->notifications->notifyOrder($order, 'INSUFFICIENT_BALANCE', [
                'amount' => $order->payableAmount(), 'balance_text' => $balanceText,
            ]);
        }

        return [
            'success' => false,
            'reason' => $reason,
            'message' => $message,
            'payable' => $order->payableAmount(),
            'balance' => $balance,
        ];
    }

    private function warnLowBalance(LaundryOrder $order, ?float $balanceAfter): void
    {
        $threshold = (float) config('laundry.low_balance_threshold', 0);
        if ($threshold <= 0 || $balanceAfter === null || $balanceAfter >= $threshold) {
            return;
        }

        $this->notifications->notifyOrder($order, 'LOW_BALANCE', [
            'balance_text' => 'Rp '.number_format($balanceAfter, 0, ',', '.'),
        ]);
    }

    /** §70: tap RFID harus resolving ke santri pemilik order (atau tasnya). */
    private function assertRfidBelongsToStudent(string $uid, int $studentId): void
    {
        $resolved = $this->rfid->resolve($uid);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'rfid_uid' => 'Kartu/tag RFID tidak dikenali.',
            ]);
        }

        $ownerId = isset($resolved['student_id']) ? (int) $resolved['student_id'] : null;
        if ($ownerId !== $studentId) {
            throw ValidationException::withMessages([
                'rfid_uid' => 'Kartu RFID bukan milik santri pada order ini.',
            ]);
        }
    }

    private function presentPayment(LaundryPayment $payment): array
    {
        return [
            'payment_number' => (string) $payment->payment_number,
            'amount' => LaundryMath::money($payment->amount),
            'method' => (string) $payment->method,
            'status' => (string) $payment->status,
            'wallet_transaction_id' => $payment->wallet_transaction_id ? (int) $payment->wallet_transaction_id : null,
            'wallet_transaction_number' => $payment->wallet_transaction_number,
            'balance_before' => $payment->balance_before !== null ? LaundryMath::money($payment->balance_before) : null,
            'balance_after' => $payment->balance_after !== null ? LaundryMath::money($payment->balance_after) : null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
