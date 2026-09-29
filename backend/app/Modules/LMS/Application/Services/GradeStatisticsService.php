<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradeRelease;
use App\Modules\LMS\Models\LmsGradebook;
use Illuminate\Support\Collection;

/**
 * Statistik nilai (§36–§37, §48–§49).
 *
 * Ranking **tidak** ditampilkan secara default: hanya bila institusi memang
 * mengaktifkannya pada gradebook (`is_ranking_visible`), karena tujuannya
 * membantu guru memahami proses belajar, bukan memeringkat anak.
 */
class GradeStatisticsService
{
    public function __construct(
        private readonly GradeCalculationService $calculation,
        private readonly GradebookService $gradebooks,
    ) {}

    /** Daftar user_id siswa aktif pada sebuah course. @return list<int> */
    public function studentIds(LmsCourse $course): array
    {
        return LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->whereNotIn('status', ['CANCELLED', 'SUSPENDED'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Ringkasan nilai satu course (§36) + distribusi (§37).
     *
     * @return array<string, mixed>
     */
    public function courseSummary(LmsGradebook $gradebook): array
    {
        $course = $gradebook->course()->firstOrFail();
        $userIds = $this->studentIds($course);

        $scores = [];
        $missing = 0;
        $incomplete = 0;

        foreach ($userIds as $userId) {
            $calculation = $this->calculation->calculate($gradebook, $userId);
            $normalized = $calculation['normalized'];

            if ($normalized === null) {
                $missing++;

                continue;
            }

            $scores[$userId] = (float) $normalized;

            if ($calculation['is_incomplete']) {
                $incomplete++;
            }
        }

        $values = array_values($scores);
        sort($values);

        $passing = $gradebook->passingGrade();
        $passed = count(array_filter($values, fn (float $value) => $value >= $passing));
        $total = count($values);

        $summary = [
            'student_count' => count($userIds),
            'graded_count' => $total,
            'missing_count' => $missing,
            'incomplete_count' => $incomplete,
            'average' => $total === 0 ? null : round(array_sum($values) / $total, 2),
            'median' => $total === 0 ? null : round($this->median($values), 2),
            'highest' => $values === [] ? null : round(max($values), 2),
            'lowest' => $values === [] ? null : round(min($values), 2),
            'passing_grade' => $passing,
            'passed_count' => $passed,
            'failed_count' => $total - $passed,
            'pass_rate' => $total === 0 ? null : round($passed / $total * 100, 2),
            'fail_rate' => $total === 0 ? null : round(($total - $passed) / $total * 100, 2),
            'released_count' => LmsGradeRelease::query()
                ->where('gradebook_id', $gradebook->id)
                ->whereIn('status', ['RELEASED', 'LOCKED'])
                ->count(),
            'distribution' => $this->distribution($values),
            'items' => $this->itemSummaries($gradebook),
        ];

        if ($gradebook->is_ranking_visible) {
            arsort($scores);
            $summary['ranking'] = array_map(
                fn (int $userId, int $index) => ['user_id' => $userId, 'rank' => $index + 1, 'score' => round($scores[$userId], 2)],
                array_keys($scores),
                array_keys(array_keys($scores)),
            );
        }

        return $summary;
    }

    /**
     * Distribusi nilai dalam bucket 90–100 / 80–89 / 70–79 / 60–69 / <60 (§37).
     *
     * @param  list<float>  $values
     * @return list<array{label: string, min: float, max: float|null, count: int, percent: float}>
     */
    public function distribution(array $values): array
    {
        $buckets = [
            ['label' => '90–100', 'min' => 90.0, 'max' => 100.0001],
            ['label' => '80–89', 'min' => 80.0, 'max' => 89.9999],
            ['label' => '70–79', 'min' => 70.0, 'max' => 79.9999],
            ['label' => '60–69', 'min' => 60.0, 'max' => 69.9999],
            ['label' => '<60', 'min' => -0.0001, 'max' => 59.9999],
        ];

        $total = count($values);

        return array_map(function (array $bucket) use ($values, $total): array {
            $count = count(array_filter(
                $values,
                fn (float $value) => $value >= $bucket['min'] && $value <= $bucket['max'],
            ));

            return [
                'label' => $bucket['label'],
                'min' => $bucket['min'],
                'max' => $bucket['max'] > 100 ? null : $bucket['max'],
                'count' => $count,
                'percent' => $total === 0 ? 0.0 : round($count / $total * 100, 2),
            ];
        }, $buckets);
    }

    /**
     * Statistik satu grade item (§36 kolom kedua).
     *
     * @return array<string, mixed>
     */
    public function itemStatistics(LmsGradeItem $item): array
    {
        $results = LmsAssessmentResult::query()
            ->where('grade_item_id', $item->id)
            ->get();

        $scored = $results
            ->filter(fn (LmsAssessmentResult $result) => $result->status->hasScore() && ! $result->isExempt())
            ->map(fn (LmsAssessmentResult $result) => (float) ($result->normalized() ?? 0.0))
            ->values()
            ->all();

        sort($scored);

        $course = $item->gradebook?->course;
        $enrolled = $course === null ? 0 : count($this->studentIds($course));

        $passing = $item->passingGrade() / max((float) $item->maximum_grade, 0.01) * 100;
        $passed = count(array_filter($scored, fn (float $value) => $value >= $passing));

        return [
            'submitted_count' => $results->count(),
            'graded_count' => count($scored),
            'exempt_count' => $results->filter(fn (LmsAssessmentResult $result) => $result->isExempt())->count(),
            'pending_count' => $results->filter(
                fn (LmsAssessmentResult $result) => $result->status === AssessmentResultStatus::PENDING
            )->count(),
            'missing_count' => max($enrolled - $results->count(), 0),
            'enrolled_count' => $enrolled,
            'average' => $scored === [] ? null : round(array_sum($scored) / count($scored), 2),
            'median' => $scored === [] ? null : round($this->median($scored), 2),
            'highest' => $scored === [] ? null : round(max($scored), 2),
            'lowest' => $scored === [] ? null : round(min($scored), 2),
            'pass_rate' => $scored === [] ? null : round($passed / count($scored) * 100, 2),
            'completion_rate' => $enrolled === 0 ? null : round(count($scored) / $enrolled * 100, 2),
            'distribution' => $this->distribution($scored),
        ];
    }

    /**
     * Tampilan performa kelas untuk guru (§48).
     *
     * @return array<string, mixed>
     */
    public function teacherPerformance(LmsCourse $course): array
    {
        $gradebook = $this->gradebooks->ensureForCourse($course);
        $summary = $this->courseSummary($gradebook);

        $activityStats = LmsGradeItem::query()
            ->where('gradebook_id', $gradebook->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (LmsGradeItem $item) => $this->itemStatistics($item) + [
                'grade_item_id' => (int) $item->id,
                'name' => $item->name,
                'source' => $item->source->value,
            ])
            ->all();

        $outcomeService = app(OutcomeAssessmentService::class);
        $competencyService = app(CompetencyAssessmentService::class);

        return [
            'course_id' => (int) $course->id,
            'course_title' => $course->name,
            'gradebook_id' => (int) $gradebook->id,
            'course_average' => $summary['average'],
            'completion_rate' => $summary['items'] === []
                ? null
                : round(array_sum(array_map(fn (array $item) => (float) ($item['completion_rate'] ?? 0), $summary['items'])) / count($summary['items']), 2),
            'pass_rate' => $summary['pass_rate'],
            'items' => $activityStats,
            'outcomes' => $outcomeService->courseAchievement($course),
            'competencies' => $competencyService->courseProgress($course),
            'distribution' => $summary['distribution'],
        ];
    }

    /**
     * Analitik akademik agregat untuk admin (§49).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function academicAnalytics(array $filters = []): array
    {
        $query = LmsCourse::query()->with('gradebook');

        if (! empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', (int) $filters['academic_year_id']);
        }

        if (! empty($filters['semester_id'])) {
            $query->where('semester_id', (int) $filters['semester_id']);
        }

        if (! empty($filters['class_id'])) {
            $query->where('class_id', (int) $filters['class_id']);
        }

        if (! empty($filters['teacher_id'])) {
            $query->where('teacher_id', (int) $filters['teacher_id']);
        }

        if (! empty($filters['course_id'])) {
            $query->whereKey((int) $filters['course_id']);
        }

        $courses = $query->get();

        $rows = [];
        $averages = [];
        $studentIds = [];
        $passRates = [];

        foreach ($courses as $course) {
            $gradebook = $course->gradebook;

            if ($gradebook === null) {
                continue;
            }

            $summary = $this->courseSummary($gradebook);
            $rows[] = [
                'course_id' => (int) $course->id,
                'course_title' => $course->name,
                'student_count' => $summary['student_count'],
                'average' => $summary['average'],
                'pass_rate' => $summary['pass_rate'],
                'missing_count' => $summary['missing_count'],
                'released_count' => $summary['released_count'],
                'completion_rate' => $summary['items'] === []
                    ? null
                    : round(array_sum(array_map(fn (array $item) => (float) ($item['completion_rate'] ?? 0), $summary['items'])) / count($summary['items']), 2),
            ];

            if ($summary['average'] !== null) {
                $averages[] = (float) $summary['average'];
            }

            if ($summary['pass_rate'] !== null) {
                $passRates[] = (float) $summary['pass_rate'];
            }

            foreach ($this->studentIds($course) as $userId) {
                $studentIds[$userId] = true;
            }
        }

        return [
            'filters' => $filters,
            'course_count' => count($rows),
            'student_count' => count($studentIds),
            'average' => $averages === [] ? null : round(array_sum($averages) / count($averages), 2),
            'pass_rate' => $passRates === [] ? null : round(array_sum($passRates) / count($passRates), 2),
            'courses' => $rows,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Ringkasan seluruh grade item pada sebuah gradebook.
     *
     * @return list<array<string, mixed>>
     */
    private function itemSummaries(LmsGradebook $gradebook): array
    {
        return $gradebook->items()
            ->with('category')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (LmsGradeItem $item) => $this->itemStatistics($item) + [
                'grade_item_id' => (int) $item->id,
                'name' => $item->name,
                'source' => $item->source->value,
                'source_label' => $item->source->label(),
                'category_name' => $item->category?->name,
                'weight' => (float) $item->weight,
            ])
            ->all();
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
