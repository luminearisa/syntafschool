<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\RouteOptimizationProviderInterface;

/**
 * Fondasi §66: data & batasan disiapkan, mesin optimasi belum dipasang.
 * Implementasi nyata (OSRM/GraphHopper/Google) cukup menggantikan binding ini.
 */
class NullRouteOptimizationGateway implements RouteOptimizationProviderInterface
{
    public function capabilities(): array
    {
        return [
            'available' => false,
            'providers' => ['GoogleMaps', 'OpenStreetMap', 'OSRM', 'GraphHopper'],
            'supported_inputs' => ['stops', 'coordinates', 'distance', 'duration', 'passenger_count',
                'pickup_time', 'dropoff_time', 'vehicle_capacity', 'constraints'],
            'reason' => 'Mesin optimasi rute belum dipasang pada Phase 2 (hanya fondasi data & batasan).',
        ];
    }

    public function optimize(array $stops, array $constraints = []): array
    {
        return [
            'optimized' => false,
            'reason' => 'Optimasi rute otomatis berada di luar batas Phase 2.',
            'input' => ['stops' => count($stops), 'constraints' => array_keys($constraints)],
            'fallback_order' => array_values(array_map(
                fn ($stop) => $stop['sequence'] ?? $stop['id'] ?? null,
                $stops
            )),
        ];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
