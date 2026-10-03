<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Events;

/**
 * Wallet charge berhasil; order PAID.
 *
 * Event murni (bukan Eloquent event): dipublikasikan application service pada
 * titik transisi yang sah, membawa id order + snapshot data secukupnya agar
 * listener tidak perlu membaca ulang database.
 */
class LaundryPaymentCompleted
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $orderId,
        public readonly array $data = [],
    ) {
    }
}
