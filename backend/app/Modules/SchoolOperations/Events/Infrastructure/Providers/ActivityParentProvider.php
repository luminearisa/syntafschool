<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Data wali murid (modul Activity).
 *
 * Meneruskan ke kontrak Parent modul Activity; dipakai untuk persetujuan wali (§35).
 */
class ActivityParentProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Parent modul Activity; dipakai untuk persetujuan wali (§35).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\ParentProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function guardiansOf(int $studentId): array
    {
        $value = $this->call('guardiansOf', [$studentId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function userIdsFor(int $studentId): array
    {
        $value = $this->call('userIdsFor', [$studentId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function childrenOfUser(int $userId): array
    {
        $value = $this->call('childrenOfUser', [$userId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function canActFor(int $userId, int $studentId): bool
    {
        $value = $this->call('canActFor', [$userId, $studentId]);

        return $value === null ? false : (bool) $value;
    }
}
