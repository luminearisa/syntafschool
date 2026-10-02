<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Presensi institusi (modul Activity).
 *
 * Meneruskan ke kontrak Attendance; event hanya mencatat presensi operasionalnya sendiri (§36).
 */
class ActivityAttendanceProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Attendance; event hanya mencatat presensi operasionalnya sendiri (§36).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\AttendanceProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function mirror(array $payload): ?string
    {
        $value = $this->call('mirror', [$payload]);

        return $value === null ? null : (string) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function revoke(string $reference): bool
    {
        $value = $this->call('revoke', [$reference]);

        return $value === null ? false : (bool) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function summary(int $studentId, string $from, string $to): array
    {
        $value = $this->call('summary', [$studentId, $from, $to]);

        return $this->wrap($value);
    }
}
