<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Transportasi — perjalanan lomba/kegiatan luar (§66).
 */
interface TransportationProviderInterface
{
    public function request(array $payload): array;

    public function cancel(string $reference): bool;

    public function isAvailable(): bool;
}
