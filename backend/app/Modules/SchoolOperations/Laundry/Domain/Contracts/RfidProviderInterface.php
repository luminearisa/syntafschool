<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Identitas RFID/QR (§17, §70). RFID hanya identity/payment credential —
 * saldo tetap di Wallet. Resolusi: kartu wallet santri, laundry bag, atau
 * label order.
 */
interface RfidProviderInterface
{
    /**
     * Terjemahkan satu uid/kode menjadi identitas.
     *
     * @return array{type:string, student_id?:int|null, wallet_id?:int|null, bag_id?:int|null, order_id?:int|null, source:string}|null
     */
    public function resolve(string $uid): ?array;

    public function available(): bool;
}
