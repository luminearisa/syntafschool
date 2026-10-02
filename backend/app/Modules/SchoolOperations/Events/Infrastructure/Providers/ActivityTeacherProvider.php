<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Data guru (modul Activity).
 *
 * Meneruskan ke kontrak Teacher modul Activity untuk pemeriksaan bentrok jadwal (§85).
 */
class ActivityTeacherProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Teacher modul Activity untuk pemeriksaan bentrok jadwal (§85).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\TeacherProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function profile(int $employeeId): ?array
    {
        $value = $this->call('profile', [$employeeId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function userIdsForEmployee(int $employeeId): array
    {
        $value = $this->call('userIdsForEmployee', [$employeeId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function search(string $term, int $limit = 20): array
    {
        $value = $this->call('search', [$term, $limit]);

        return $this->wrap($value);
    }
}
