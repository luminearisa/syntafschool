<?php

namespace App\Services\Academic;

use App\Models\AcademicYear;
use App\Models\ReportCard;
use App\Models\Student;
use App\Models\Transcript;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Menyusun transkrip kumulatif dari rapor yang sudah diterbitkan.
 *
 * Hanya rapor berstatus `published` yang dipakai, sehingga transkrip tidak
 * pernah memuat nilai yang belum resmi dibagikan ke orang tua.
 */
class TranscriptService
{
    /** @return Collection<int, ReportCard> */
    protected function publishedCards(Student $student, ?int $academicYearId): Collection
    {
        return ReportCard::query()
            ->where('student_id', $student->id)
            ->where('status', ReportCard::STATUS_PUBLISHED)
            ->when($academicYearId, fn ($q) => $q->where('academic_year_id', $academicYearId))
            ->with([
                'semester:id,name,academic_year_id',
                'academicYear:id,name',
                'items.subject:id,code,name',
            ])
            ->orderBy('academic_year_id')
            ->orderBy('semester_id')
            ->orderBy('id')
            ->get();
    }

    public function generate(Student $student, ?int $academicYearId, ?int $userId): Transcript
    {
        $cards = $this->publishedCards($student, $academicYearId);

        if ($cards->isEmpty()) {
            throw ValidationException::withMessages([
                'student_id' => 'Belum ada rapor yang diterbitkan untuk siswa ini.',
            ]);
        }

        /** @var array<int, array<string, mixed>> $subjects */
        $subjects = [];

        foreach ($cards as $card) {
            foreach ($card->items as $item) {
                $subjectId = (int) $item->subject_id;

                $subjects[$subjectId] ??= [
                    'subject_id' => $subjectId,
                    'code' => $item->subject?->code,
                    'name' => $item->subject?->name,
                    'scores' => [],
                    'total' => 0.0,
                    'count' => 0,
                ];

                if ($item->score !== null) {
                    $subjects[$subjectId]['scores'][] = [
                        'academic_year' => $card->academicYear?->name,
                        'semester' => $card->semester?->name,
                        'type' => $card->type,
                        'score' => (float) $item->score,
                        'grade' => $item->grade,
                        'predicate' => $item->predicate,
                    ];
                    $subjects[$subjectId]['total'] += (float) $item->score;
                    $subjects[$subjectId]['count']++;
                }
            }
        }

        $rows = collect($subjects)
            ->map(function (array $row): array {
                $row['average'] = $row['count'] > 0 ? round($row['total'] / $row['count'], 2) : null;
                unset($row['total'], $row['count']);

                return $row;
            })
            ->sortBy('name')
            ->values();

        $averages = $rows->pluck('average')->filter(fn ($value) => $value !== null);
        $overall = $averages->isEmpty() ? null : round((float) $averages->avg(), 2);

        $year = $academicYearId !== null ? AcademicYear::query()->find($academicYearId) : null;

        $data = [
            'student' => [
                'id' => (int) $student->id,
                'nis' => $student->nis,
                'full_name' => $student->full_name,
            ],
            'scope' => [
                'academic_year' => $year?->name,
                'label' => $year ? "Tahun Ajaran {$year->name}" : 'Kumulatif (seluruh tahun ajaran)',
            ],
            'subjects' => $rows->all(),
            'overall_average' => $overall,
            'report_card_count' => $cards->count(),
        ];

        $transcript = Transcript::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $academicYearId],
            [
                'semester_id' => null,
                'data' => $data,
                'generated_at' => now(),
                'generated_by' => $userId,
            ],
        );

        return $transcript->fresh(['student', 'academicYear']);
    }
}
