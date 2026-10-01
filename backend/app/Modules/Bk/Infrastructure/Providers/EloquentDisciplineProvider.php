<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\DisciplineProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan kedisiplinan dari `student_violations` (poin & kategori).
 *
 * Yang dibaca hanya kategori dan jumlah poin — narasi kejadian tidak ikut,
 * karena BK akan memintanya langsung kepada bagian kedisiplinan bila perlu.
 */
class EloquentDisciplineProvider implements DisciplineProviderInterface
{
    public function summary(int $studentId, string $from, string $to): array
    {
        $rows = DB::table('student_violations')
            ->leftJoin('violation_types', 'violation_types.id', '=', 'student_violations.violation_type_id')
            ->where('student_violations.student_id', $studentId)
            ->whereBetween('student_violations.date', [$from, $to])
            ->whereNull('student_violations.deleted_at')
            ->orderByDesc('student_violations.date')
            ->get([
                'student_violations.date', 'student_violations.point',
                'violation_types.category as category',
            ]);

        $latest = $rows->first();

        return [
            'points' => (int) $rows->sum(fn ($row): int => (int) ($row->point ?? 0)),
            'incidents' => $rows->count(),
            'latest_date' => $latest?->date,
            'latest_category' => $latest->category ?? null,
            'status' => $this->statusFor((int) $rows->sum(fn ($row): int => (int) ($row->point ?? 0))),
        ];
    }

    /** Penanda tingkat poin — ambangnya dapat diubah lewat konfigurasi. */
    private function statusFor(int $points): string
    {
        $warning = (int) config('bk.discipline.warning_points', 25);
        $serious = (int) config('bk.discipline.serious_points', 50);

        return match (true) {
            $points >= $serious => 'SERIOUS',
            $points >= $warning => 'WARNING',
            $points > 0 => 'NOTED',
            default => 'CLEAR',
        };
    }
}
