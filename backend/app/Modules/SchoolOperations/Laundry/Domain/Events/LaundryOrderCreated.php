<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Events;

/**
 * Order laundry baru dibuat (DRAFT).
 *
 * Event murni (bukan Eloquent event): dipublikasikan application service pada
 * titik transisi yang sah, membawa id order + snapshot data secukupnya agar
 * listener tidak perlu membaca ulang database.
 */
class LaundryOrderCreated
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $orderId,
        public readonly array $data = [],
    ) {
    }
}
