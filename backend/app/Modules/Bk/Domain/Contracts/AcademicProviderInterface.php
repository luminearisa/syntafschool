<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Ringkasan akademik siswa untuk profil dukungan & indikator screening.
 */
interface AcademicProviderInterface
{

    /**
     * Ringkasan akademik yang aman dibagikan.
     *
     * @return array{
     *     average: ?float, subjects_assessed: int, below_minimum: int,
     *     lowest_subject: ?string, highest_subject: ?string, note: ?string
     * }
     */
    public function summary(int $studentId): array;
}
