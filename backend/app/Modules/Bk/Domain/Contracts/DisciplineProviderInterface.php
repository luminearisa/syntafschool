<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Ringkasan kedisiplinan siswa (poin & catatan) untuk indikator screening.
 */
interface DisciplineProviderInterface
{

    /**
     * @return array{points: int, incidents: int, latest_date: ?string, latest_category: ?string, status: string}
     */
    public function summary(int $studentId, string $from, string $to): array;
}
