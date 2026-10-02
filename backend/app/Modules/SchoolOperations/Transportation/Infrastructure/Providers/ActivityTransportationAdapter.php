<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Providers;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\TransportationProviderInterface;
use App\Modules\SchoolOperations\Transportation\Application\Services\CapacityPlanningService;

/**
 * Menghubungkan permintaan transportasi modul Activity (lomba/kegiatan) ke
 * modul Transportation Management yang sebenarnya — menggantikan null provider
 * tanpa mengubah modul Activity.
 */
class ActivityTransportationAdapter implements \App\Modules\Activity\Domain\Contracts\TransportationProviderInterface
{
    public function __construct(
        private readonly TransportationProviderInterface $provider,
        private readonly CapacityPlanningService $capacity,
    ) {
    }

    public function request(array $payload): array
    {
        $requested = $this->provider->request([
            'student_id' => $payload['student_id'] ?? null,
            'route_id' => $payload['route_id'] ?? null,
            'pickup_stop_id' => $payload['pickup_stop_id'] ?? null,
            'dropoff_stop_id' => $payload['dropoff_stop_id'] ?? null,
            'effective_from' => $payload['date'] ?? $payload['trip_date'] ?? now()->toDateString(),
            'reason' => $payload['purpose'] ?? $payload['reason'] ?? 'Permintaan transportasi kegiatan.',
            'special_requirement' => $payload['special_requirement'] ?? null,
            'requested_by_type' => 'STAFF',
        ]);

        if (! empty($payload['passengers']) && ! empty($payload['route_id'])) {
            $requested['capacity_plan'] = $this->capacity->planEventTransport([
                'passengers' => (int) $payload['passengers'],
                'route_id' => $payload['route_id'],
                'date' => $payload['date'] ?? now()->toDateString(),
                'event_name' => $payload['purpose'] ?? null,
            ]);
        }

        return $requested;
    }

    public function cancel(string $reference): bool
    {
        return $this->provider->cancel($reference);
    }

    public function isAvailable(): bool
    {
        try {
            return $this->provider->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }
}
