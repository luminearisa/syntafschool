<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\LocationGatewayInterface;

/**
 * Tanpa penyedia GPS: geocoding tidak dikarang, namun jarak antar koordinat
 * tetap dihitung lokal (haversine) karena hanya butuh data halte (§21).
 */
class NullLocationGateway implements LocationGatewayInterface
{
    use GatewaySupport;

    public function geocode(string $address): ?array
    {
        return null;
    }

    public function distanceKm(array $from, array $to): ?float
    {
        $lat1 = $from['latitude'] ?? null;
        $lon1 = $from['longitude'] ?? null;
        $lat2 = $to['latitude'] ?? null;
        $lon2 = $to['longitude'] ?? null;

        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }

        $earthRadius = 6371.0;
        $latDelta = deg2rad((float) $lat2 - (float) $lat1);
        $lonDelta = deg2rad((float) $lon2 - (float) $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2)) * sin($lonDelta / 2) ** 2;

        return round(2 * $earthRadius * asin(min(1.0, sqrt($a))), 2);
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
