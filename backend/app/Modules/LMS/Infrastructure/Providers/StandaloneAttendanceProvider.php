<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\AttendanceProviderInterface;

/**
 * Standalone Mode: tidak ada kehadiran akademik terpisah.
 *
 * Kehadiran belajar LMS (penyelesaian aktivitas) dan kehadiran kelas langsung
 * tetap dihitung modulnya masing-masing (§9).
 */
class StandaloneAttendanceProvider implements AttendanceProviderInterface
{
    public function key(): string
    {
        return 'standalone';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function academicSummary(int $studentId, ?string $from = null, ?string $to = null): array
    {
        return [
            'records' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'excused' => 0,
            'rate' => null,
            'by_status' => [],
            'source' => 'standalone',
        ];
    }

    public function academicRecords(int $studentId, ?string $from = null, ?string $to = null, int $limit = 30): array
    {
        return [];
    }

    public function isBoardingStudent(int $studentId): bool
    {
        return false;
    }
}
