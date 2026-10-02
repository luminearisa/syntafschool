<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Pegawai HRD — sumber kebenaran pengemudi & penumpang staf. */
interface EmployeeGatewayInterface
{
    public function find(int $employeeId): ?array;

    public function findByUser(int $userId): ?array;

    public function isActive(int $employeeId, ?string $onDate = null): bool;

    public function available(): bool;
}
