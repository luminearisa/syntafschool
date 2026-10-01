<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Fasilitas — lapangan, aula, laboratorium, studio (§30, §64).
 *
 * Modul Activity **tidak** membuat mesin pemesanan ruang sendiri. Ia bertanya
 * ketersediaan lewat kontrak ini dan menerima jawaban ya/tidak beserta alasannya.
 */
interface FacilityProviderInterface
{
    public function room(int $roomId): ?array;

    /** @return list<array> ruang yang dapat dipesan */
    public function rooms(array $filters = []): array;

    public function availability(int $roomId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool;

    /** Ajukan pemesanan; kembalikan `['status' => ..., 'reference' => ..., 'conflict' => ...]`. */
    public function book(array $payload): array;

    public function cancel(string $reference): bool;
}
