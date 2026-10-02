<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\PositionService;
use App\Modules\Hrd\Domain\Contracts\PositionProviderInterface;

/** Posisi & formasi untuk modul lain (§49, §65). */
class PositionModuleProvider implements PositionProviderInterface
{
    public function __construct(private readonly PositionService $positions) {}

    public function findById(int $positionId): ?array
    {
        return $this->positions->findById($positionId);
    }

    public function findByCode(string $code): ?array
    {
        return $this->positions->findByCode($code);
    }

    public function holdersOf(int $positionId, ?string $onDate = null): array
    {
        return $this->positions->holdersOf($positionId, $onDate);
    }

    public function vacancies(?int $organizationId = null): array
    {
        return $this->positions->vacancies($organizationId);
    }

    public function headcountSummary(?int $organizationId = null): array
    {
        return $this->positions->headcountSummary($organizationId);
    }

    public function reportsTo(int $positionId): array
    {
        return $this->positions->reportsTo($positionId);
    }
}
