<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Geolokasi/GPS: opsional di Phase 2 (§21, §43). */
interface LocationGatewayInterface
{
    public function geocode(string $address): ?array;

    public function distanceKm(array $from, array $to): ?float;

    public function isAvailable(): bool;
}
