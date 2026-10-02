<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Kontrak personel keagamaan untuk modul lain (§16): imam, muadzin, khatib, dan ketersediaannya.
 *
 * Kontrak yang DISEDIAKAN modul Religious untuk modul lain.
 */
interface ReligiousPersonnelProviderInterface
{
    /** Profil personel keagamaan. */
    public function personnel(int $personnelId): ?array;

    /** Daftar imam. */
    public function imams(array $filters = []): array;

    /** Daftar muadzin. */
    public function muadzins(array $filters = []): array;

    /** Daftar khatib. */
    public function khatibs(array $filters = []): array;

    /** Ketersediaan personel pada tanggal tertentu. */
    public function availability(int $personnelId, string $date): array;

    /** Deteksi bentrok penugasan (§16, §60). */
    public function hasConflict(int $personnelId, string $date, string $startTime, string $endTime, ?int $exceptAssignmentId = null): bool;
}
