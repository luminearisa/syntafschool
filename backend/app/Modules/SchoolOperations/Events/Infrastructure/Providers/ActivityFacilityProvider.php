<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Fasilitas & venue (modul Activity).
 *
 * Meneruskan ke kontrak Facility modul Activity; pemesanan venue tidak dibuat ulang (§17).
 */
class ActivityFacilityProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Facility modul Activity; pemesanan venue tidak dibuat ulang (§17).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\FacilityProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function room(int $roomId): ?array
    {
        $value = $this->call('room', [$roomId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function rooms(array $filters = []): array
    {
        $value = $this->call('rooms', [$filters]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function availability(int $roomId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool
    {
        $value = $this->call('availability', [$roomId, $date, $startTime, $endTime, $ignoreBookingId]);

        return $value === null ? false : (bool) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function book(array $payload): array
    {
        $value = $this->call('book', [$payload]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function cancel(string $reference): bool
    {
        $value = $this->call('cancel', [$reference]);

        return $value === null ? false : (bool) $value;
    }
}
