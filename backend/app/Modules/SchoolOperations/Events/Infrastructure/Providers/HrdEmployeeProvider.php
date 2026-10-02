<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Data pegawai (modul HRD).
 *
 * Meneruskan ke kontrak Employee modul HRD; pegawai tidak diduplikasi di modul Event.
 */
class HrdEmployeeProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Employee modul HRD; pegawai tidak diduplikasi di modul Event.';

    protected const TARGET_CONTRACT = 'App\\Modules\\Hrd\\Domain\\Contracts\\EmployeeProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function findById(int $employeeId): ?array
    {
        $value = $this->call('findById', [$employeeId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function findByNumber(string $employeeNumber): ?array
    {
        $value = $this->call('findByNumber', [$employeeNumber]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function findByUserId(int $userId): ?array
    {
        $value = $this->call('findByUserId', [$userId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function summary(int $employeeId): ?array
    {
        $value = $this->call('summary', [$employeeId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isActive(int $employeeId, ?string $onDate = null): bool
    {
        $value = $this->call('isActive', [$employeeId, $onDate]);

        return $value === null ? false : (bool) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function activeInOrganization(int $organizationId, bool $includeDescendants = false): array
    {
        $value = $this->call('activeInOrganization', [$organizationId, $includeDescendants]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function directReportsOf(int $employeeId): array
    {
        $value = $this->call('directReportsOf', [$employeeId]);

        return $this->wrap($value);
    }
}
