<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Wallet provider (§18, Rule 10).
 *
 * Laundry TIDAK menyimpan saldo sebagai source of truth dan tidak membuat
 * wallet sendiri — modul hanya meminta saldo, charge, dan refund ke penyedia
 * wallet. Charge WAJIB idempotent terhadap (order + idempotency key) supaya
 * retry jaringan tidak memotong saldo dua kali (Rule 11/25, §22).
 */
interface WalletProviderInterface
{
    /**
     * Rekening wallet santri.
     *
     * @return array{wallet_id:int, number:string, balance:float, status:string}|null
     */
    public function accountFor(int $studentId): ?array;

    /**
     * Bebani wallet santri.
     *
     * @param  array{order_id?:int, order_number?:string, description?:string, actor_id?:int|null, rfid_uid?:string|null}  $context
     * @return array{success:bool, reason?:string, wallet_id?:int, wallet_transaction_id?:int, transaction_number?:string, balance_before?:float, balance_after?:float}
     */
    public function charge(int $studentId, float $amount, string $idempotencyKey, array $context = []): array;

    /**
     * Kembalikan dana ke wallet santri (§23, Rule 14).
     *
     * @param  array{order_id?:int, order_number?:string, original_transaction_id?:int|null, description?:string, actor_id?:int|null, reason?:string}  $context
     * @return array{success:bool, reason?:string, wallet_id?:int, wallet_transaction_id?:int, transaction_number?:string, balance_before?:float, balance_after?:float}
     */
    public function refund(int $studentId, float $amount, string $idempotencyKey, array $context = []): array;

    public function available(): bool;
}
