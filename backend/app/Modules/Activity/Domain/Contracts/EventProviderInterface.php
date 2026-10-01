<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Event Management — turnamen, pentas seni, perkemahan, workshop (§65).
 */
interface EventProviderInterface
{
    public function create(array $payload): array;

    public function update(string $reference, array $payload): array;

    public function cancel(string $reference): bool;

    public function isAvailable(): bool;
}
