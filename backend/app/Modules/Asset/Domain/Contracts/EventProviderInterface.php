<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Event — booking fasilitas dan peminjaman peralatan untuk acara sekolah.
 *
 * Booking dari Event mengikuti pemeriksaan benturan yang sama dengan booking
 * yang dibuat manual: tidak ada jalur pintas yang dapat menggandakan slot.
 */
interface EventProviderInterface
{
    public function event(int $eventId): ?array;

    /** Booking fasilitas dan pinjaman alat yang melekat pada sebuah acara. */
    public function commitments(int $eventId): array;
}
