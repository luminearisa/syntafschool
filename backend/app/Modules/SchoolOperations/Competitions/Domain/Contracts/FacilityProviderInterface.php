<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Facility owns resource availability and reservations. */
interface FacilityProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array{available:bool, conflicts?:list<array<string,mixed>>, reference?:string|null} */
    public function checkAvailability(array $request): array;
}
