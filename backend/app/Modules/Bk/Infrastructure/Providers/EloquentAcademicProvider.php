<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\AcademicProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan akademik dari nilai yang sudah diinput modul akademik (`scores` +
 * `assessments`). Tidak ada perhitungan ulang nilai resmi: yang ditampilkan
 * hanya rata-rata per mata pelajaran dan jumlah mata pelajaran di bawah ambang.
 */
class EloquentAcademicProvider implements AcademicProviderInterface
{
    public function summary(int $studentId): array
    {
        $minimum = (float) config('bk.academic.minimum_score', 70);

        $rows = DB::table('scores')
            ->join('assessments', 'assessments.id', '=', 'scores.assessment_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->where('scores.student_id', $studentId)
            ->whereNull('scores.deleted_at')
            ->whereNull('assessments.deleted_at')
            ->get(['subjects.name as subject', 'scores.score']);

        if ($rows->isEmpty()) {
            return [
                'average' => null,
                'subjects_assessed' => 0,
                'below_minimum' => 0,
                'lowest_subject' => null,
                'highest_subject' => null,
                'note' => 'Belum ada nilai pada periode ini.',
            ];
        }

        $perSubject = $rows->groupBy('subject')->map(fn ($group): float => round((float) $group->avg('score'), 2));
        $belowMinimum = $perSubject->filter(fn (float $average): bool => $average < $minimum);

        return [
            'average' => round((float) $rows->avg('score'), 2),
            'subjects_assessed' => $perSubject->count(),
            'below_minimum' => $belowMinimum->count(),
            'lowest_subject' => $perSubject->count() === 0 ? null : (string) $perSubject->sort()->keys()->first(),
            'highest_subject' => $perSubject->count() === 0 ? null : (string) $perSubject->sortDesc()->keys()->first(),
            'note' => $belowMinimum->isEmpty()
                ? null
                : $belowMinimum->count().' mata pelajaran di bawah ambang '.$minimum.'.',
        ];
    }
}
