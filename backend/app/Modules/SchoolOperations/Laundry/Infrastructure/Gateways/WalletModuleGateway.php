<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\WalletProviderInterface;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Services\Wallet\WalletLedgerService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Adapter Wallet module (§18, Rule 10).
 *
 * Laundry tidak menyimpan saldo: gateway ini satu-satunya jembatan ke
 * `WalletLedgerService` (satu-satunya penulis saldo di sistem). Charge memakai
 * tipe `withdraw` (debit) dan refund memakai `refund` (kredit) dengan
 * `source_transaction_id` ke transaksi asli.
 *
 * Idempotensi (Rule 11/25, §22): sebelum posting, gateway mencari transaksi
 * wallet dengan referensi order + `metadata.idempotency_key` yang sama. Retry
 * karena network error mengembalikan transaksi yang sudah ada — saldo tidak
 * pernah terpotong dua kali.
 */
class WalletModuleGateway implements WalletProviderInterface
{
    use GatewaySupport;

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly WalletService $wallets,
    ) {
    }

    public function accountFor(int $studentId): ?array
    {
        $wallet = $this->walletOf($studentId);
        if (! $wallet) {
            return null;
        }

        return [
            'wallet_id' => (int) $wallet->id,
            'number' => (string) $wallet->number,
            'balance' => round((float) $wallet->balance, 2),
            'status' => (string) $wallet->status,
        ];
    }

    public function charge(int $studentId, float $amount, string $idempotencyKey, array $context = []): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'reason' => 'INVALID_AMOUNT'];
        }

        $wallet = $this->walletOf($studentId);
        if (! $wallet) {
            return ['success' => false, 'reason' => 'NO_WALLET'];
        }

        // Replay: transaksi dengan kunci idempotensi sama dikembalikan apa adanya.
        $existing = $this->findByIdempotencyKey($wallet, $idempotencyKey, $context, WalletTransaction::TYPE_WITHDRAW);
        if ($existing) {
            return $this->success($existing);
        }

        try {
            $this->wallets->assertCanTransact($wallet);
        } catch (ValidationException $exception) {
            return ['success' => false, 'reason' => 'WALLET_NOT_TRANSACTABLE',
                'message' => $exception->getMessage(),
                'balance' => round((float) $wallet->balance, 2)];
        }

        if (round((float) $wallet->balance, 2) < $amount) {
            return ['success' => false, 'reason' => 'INSUFFICIENT_BALANCE',
                'balance' => round((float) $wallet->balance, 2), 'amount' => $amount];
        }

        try {
            $transaction = $this->ledger->post($wallet, WalletTransaction::TYPE_WITHDRAW, $amount, [
                'reference' => $this->referenceModel($context),
                'description' => $context['description'] ?? 'Pembayaran laundry',
                'metadata' => array_merge($context['metadata'] ?? [], [
                    'module' => 'laundry',
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $context['order_id'] ?? null,
                    'order_number' => $context['order_number'] ?? null,
                    'rfid_uid' => $context['rfid_uid'] ?? null,
                ]),
                'created_by' => $context['actor_id'] ?? null,
                'approved_by' => $context['actor_id'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            // Saldo tidak cukup (race) atau wallet diblokir saat posting.
            return ['success' => false, 'reason' => 'INSUFFICIENT_BALANCE',
                'message' => $exception->getMessage(),
                'balance' => round((float) $wallet->fresh()?->balance, 2)];
        }

        return $this->success($transaction);
    }

    public function refund(int $studentId, float $amount, string $idempotencyKey, array $context = []): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'reason' => 'INVALID_AMOUNT'];
        }

        $wallet = $this->walletOf($studentId);
        if (! $wallet) {
            return ['success' => false, 'reason' => 'NO_WALLET'];
        }

        $existing = $this->findByIdempotencyKey($wallet, $idempotencyKey, $context, WalletTransaction::TYPE_REFUND);
        if ($existing) {
            return $this->success($existing);
        }

        try {
            $transaction = $this->ledger->post($wallet, WalletTransaction::TYPE_REFUND, $amount, [
                'reference' => $this->referenceModel($context),
                'source_transaction_id' => $context['original_transaction_id'] ?? null,
                'description' => $context['description'] ?? 'Refund laundry',
                'metadata' => array_merge($context['metadata'] ?? [], [
                    'module' => 'laundry',
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $context['order_id'] ?? null,
                    'order_number' => $context['order_number'] ?? null,
                    'reason' => $context['reason'] ?? null,
                ]),
                'created_by' => $context['actor_id'] ?? null,
                'approved_by' => $context['actor_id'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return ['success' => false, 'reason' => 'REFUND_FAILED', 'message' => $exception->getMessage()];
        }

        return $this->success($transaction);
    }

    public function available(): bool
    {
        return $this->has('wallets') && $this->has('wallet_transactions');
    }

    private function walletOf(int $studentId): ?Wallet
    {
        if (! $this->available()) {
            return null;
        }

        return Wallet::query()->where('student_id', $studentId)->orderBy('id')->first();
    }

    private function findByIdempotencyKey(Wallet $wallet, string $key, array $context, string $type): ?WalletTransaction
    {
        if (! Schema::hasTable('wallet_transactions') || $key === '') {
            return null;
        }

        return WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', $type)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('metadata->idempotency_key', $key)
            ->when(isset($context['order_id']), function ($q) use ($context): void {
                $q->where('reference_type', LaundryOrder::class)
                    ->where('reference_id', (int) $context['order_id']);
            })
            ->orderBy('id')
            ->first();
    }

    private function referenceModel(array $context): ?LaundryOrder
    {
        if (! isset($context['order_id'])) {
            return null;
        }

        return LaundryOrder::query()->find((int) $context['order_id']);
    }

    private function success(WalletTransaction $transaction): array
    {
        return [
            'success' => true,
            'wallet_id' => (int) $transaction->wallet_id,
            'wallet_transaction_id' => (int) $transaction->id,
            'transaction_number' => (string) $transaction->transaction_number,
            'balance_before' => round((float) $transaction->balance_before, 2),
            'balance_after' => round((float) $transaction->balance_after, 2),
        ];
    }
}
