<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

interface FacilityGatewayInterface
{
    public function find(int $facilityId): ?array;

    public function available(): bool;
}
