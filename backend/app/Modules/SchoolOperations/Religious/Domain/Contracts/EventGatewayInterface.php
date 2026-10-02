<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Event Management (§53). Siklus hidup event keagamaan memakai modul Event bila ada.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface EventGatewayInterface
{
    /** Buat event pada modul Event. */
    public function create(array $payload): array;

    /** Publikasikan event. */
    public function publish(int|string $eventId): array;

    /** Status event. */
    public function status(int|string $eventId): ?string;

    /** Ketersediaan modul Event. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
