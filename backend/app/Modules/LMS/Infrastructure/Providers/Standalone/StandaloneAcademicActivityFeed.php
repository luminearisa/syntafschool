<?php

namespace App\Modules\LMS\Infrastructure\Providers\Standalone;

use App\Modules\LMS\Domain\Contracts\AcademicActivityFeedInterface;

/**
 * Standalone Mode: tidak ada modul akademik ERP, jadi umpan ini kosong.
 * Dashboard tetap utuh karena aktivitas LMS (penyelesaian item, pengumuman,
 * jadwal ketersediaan) tidak bergantung padanya.
 */
class StandaloneAcademicActivityFeed implements AcademicActivityFeedInterface
{
    public function upcomingForStudent(int $studentId, int $limit = 5): array
    {
        return [];
    }

    public function pendingGrading(?int $employeeId, int $limit = 10): array
    {
        return [];
    }

    public function recentSubmissions(?int $employeeId, int $limit = 10): array
    {
        return [];
    }

    public function upcomingForClasses(array $classIds, int $limit = 5): array
    {
        return [];
    }

    public function gradesForStudent(int $studentId): array
    {
        return ['count' => 0, 'average' => null, 'recent' => [], 'source' => 'lms'];
    }

    public function gradingCounters(?int $employeeId): array
    {
        return ['pending' => 0, 'graded' => 0, 'total' => 0];
    }
}
