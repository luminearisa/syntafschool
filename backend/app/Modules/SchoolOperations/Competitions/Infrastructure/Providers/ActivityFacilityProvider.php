<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\FacilityProviderInterface as ActivityFacilityProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\FacilityProviderInterface as CompetitionFacilityProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/** Adapts the shared Facility booking provider; Competition does not book rooms itself. */
class ActivityFacilityProvider implements CompetitionFacilityProviderInterface
{
    public function __construct(private readonly ActivityFacilityProviderInterface $facilities) {}

    public function checkAvailability(array $request): array
    {
        $roomId = (int) ($request['room_id'] ?? 0);
        if ($roomId < 1) {
            return ['available' => true];
        }
        $starts = CarbonImmutable::parse($request['starts_at'] ?? now());
        $ends = CarbonImmutable::parse($request['ends_at'] ?? $starts->addHour());
        $available = $this->facilities->availability(
            $roomId,
            $starts->toDateString(),
            $starts->format('H:i:s'),
            $ends->format('H:i:s'),
            isset($request['ignore_booking_id']) ? (int) $request['ignore_booking_id'] : null,
        );

        return ['available' => $available, 'conflicts' => $available ? [] : [['type' => 'ROOM', 'room_id' => $roomId, 'reason' => 'Facility provider melaporkan ruangan telah dipesan.']]];
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('rooms') && Schema::hasTable('room_bookings');
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'Shared rooms/room_bookings tables are unavailable.';
    }
}
