<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Ringkasan kehadiran siswa untuk profil dukungan & indikator screening.
 */
interface AttendanceProviderInterface
{

    /**
     * @return array{present: int, late: int, sick: int, permitted: int, absent: int, total: int, percentage: ?float}
     */
    public function summary(int $studentId, string $from, string $to): array;
}
