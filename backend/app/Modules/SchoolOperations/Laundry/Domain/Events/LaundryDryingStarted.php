<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Events;

/**
 * Tahap pengeringan dimulai.
 *
 * Event murni (bukan Eloquent event): dipublikasikan application service pada
 * titik transisi yang sah, membawa id order + snapshot data secukupnya agar
 * listener tidak perlu membaca ulang database.
 */
class LaundryDryingStarted
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $orderId,
        public readonly array $data = [],
    ) {
    }
}
