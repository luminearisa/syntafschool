<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Data siswa (modul Activity).
 *
 * Meneruskan ke kontrak Student modul Activity; data siswa tidak diduplikasi di modul Event.
 */
class ActivityStudentProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Student modul Activity; data siswa tidak diduplikasi di modul Event.';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\StudentProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function snapshot(int $studentId): ?array
    {
        $value = $this->call('snapshot', [$studentId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function snapshots(array $studentIds): array
    {
        $value = $this->call('snapshots', [$studentIds]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function search(string $term, int $limit = 20): array
    {
        $value = $this->call('search', [$term, $limit]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function currentClass(int $studentId): ?array
    {
        $value = $this->call('currentClass', [$studentId]);

        return $value === null ? null : (array) $value;
    }
}
