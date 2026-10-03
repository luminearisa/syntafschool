<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Event Management orchestrates events; Competition may only attach a reference. */
interface EventProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array{reference?:string|null, created:bool} */
    public function attachCompetition(array $payload): array;
}
