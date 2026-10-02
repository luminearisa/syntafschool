<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Application\Services\{MosqueBookingService, MosqueService};
use App\Modules\SchoolOperations\Religious\Domain\Contracts\MosqueResourceProviderInterface;

/**
 * Ketersediaan masjid & area untuk modul lain (Event, Facility, Boarding).
 * Modul lain cukup bertanya — tidak perlu memahami skema internal modul ini.
 */
class MosqueResourceProvider implements MosqueResourceProviderInterface
{
    public function __construct(
        protected MosqueService $mosques,
        protected MosqueBookingService $bookings,
    ) {
    }

    public function mosques(array $filters = []): array
    {
        return $this->mosques->list($filters);
    }

    public function areas(int $mosqueId, array $filters = []): array
    {
        return $this->mosques->areas($mosqueId, $filters);
    }

    public function isAvailable(int $mosqueId, ?int $areaId, string $startAt, string $endAt): bool
    {
        return $this->bookings->detectConflict($mosqueId, $areaId, $startAt, $endAt) === null;
    }

    public function bookings(array $filters = []): array
    {
        return $this->bookings->list($filters);
    }
}
