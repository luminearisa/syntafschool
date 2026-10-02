<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\EmploymentService;
use App\Modules\Hrd\Domain\Contracts\EmploymentProviderInterface;

/** Kepegawaian & kontrak untuk modul lain (§65). Payroll fase berikutnya membacanya. */
class EmploymentModuleProvider implements EmploymentProviderInterface
{
    public function __construct(private readonly EmploymentService $employment) {}

    public function currentEmployment(int $employeeId): ?array
    {
        return $this->employment->currentEmployment($employeeId);
    }

    public function employmentHistory(int $employeeId): array
    {
        return $this->employment->employmentHistory($employeeId);
    }

    public function activeContract(int $employeeId, ?string $onDate = null): ?array
    {
        return $this->employment->activeContract($employeeId, $onDate);
    }

    public function expiringContracts(int $days, ?int $organizationId = null): array
    {
        return $this->employment->expiringContracts($days, $organizationId);
    }

    public function assignmentsOn(int $employeeId, ?string $onDate = null): array
    {
        return $this->employment->assignmentsOn($employeeId, $onDate);
    }

    public function totalServiceMonths(int $employeeId, ?string $asOf = null): int
    {
        return $this->employment->totalServiceMonths($employeeId, $asOf);
    }

    public function onProbation(int $employeeId, ?string $onDate = null): bool
    {
        return $this->employment->onProbation($employeeId, $onDate);
    }
}
