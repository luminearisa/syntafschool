<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityGradeService;
use App\Modules\LMS\Application\Services\AssessmentResultService;
use App\Modules\LMS\Application\Services\CompetencyAssessmentService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseProgressService;
use App\Modules\LMS\Application\Services\FinalGradeService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\GradeCalculationService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Application\Services\GradeReviewService;
use App\Modules\LMS\Application\Services\GradeStatisticsService;
use App\Modules\LMS\Application\Services\LmsCalendarService;
use App\Modules\LMS\Application\Services\OutcomeAssessmentService;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradeHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tampilan nilai: siswa (§32), wali (§33), dan ringkasan performa (§47–§48).
 *
 * Aturan privasi dijaga di sini: siswa hanya melihat nilainya, wali hanya anak
 * yang sah, dan catatan privat guru tidak pernah keluar dari sisi pengajar
 * (§33, §53).
 */
class LmsGradeViewController extends LmsController
{
    public function __construct(
        private readonly FinalGradeService $finalGrades,
        private readonly GradeCalculationService $calculation,
        private readonly GradeReleaseService $releases,
        private readonly GradebookService $gradebooks,
        private readonly GradeStatisticsService $statistics,
        private readonly OutcomeAssessmentService $outcomes,
        private readonly CompetencyAssessmentService $competencies,
        private readonly GradeReviewService $reviews,
        private readonly CourseAccessService $access,
        private readonly AssessmentResultService $results,
        private readonly ActivityGradeService $activityGrades,
    ) {}

    /**
     * "Nilai saya" untuk seluruh course siswa (§32).
     *
     * Wali dapat memakai `user_id` anak yang sah; guru memakai `user_id`
     * siswanya (mode baca) — semuanya lewat satu endpoint agar aturan privasi
     * hanya ada di satu tempat.
     */
    public function myGrades(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $target = $this->resolveTarget($request, $actor);

        $courses = $this->courseIdsFor($target);

        $rows = [];

        foreach ($courses as $course) {
            $gradebook = $this->gradebooks->ensureForCourse($course);
            $release = $this->releases->forStudent($gradebook, $target);
            $isReleased = $release?->isVisible() ?? false;
            $calculation = $this->calculation->calculate($gradebook, $target);

            $rows[] = [
                'course_id' => (int) $course->id,
                'course_title' => $course->name,
                'course_thumbnail' => $course->thumbnail_path,
                'gradebook_id' => (int) $gradebook->id,
                'is_released' => $isReleased,
                'release' => $release === null ? null : $this->releases->releasePayload($release),
                'display' => $isReleased
                    ? $this->finalGrades->decorate($gradebook, $calculation)['display']
                    : ['value' => null, 'text' => 'Belum dirilis', 'letter' => null, 'percentage' => null, 'points' => null],
                'current_grade' => $isReleased ? $calculation['normalized'] : null,
                'letter' => $isReleased ? $calculation['letter'] : null,
                'predicate' => $isReleased ? $calculation['predicate'] : null,
                'passed' => $isReleased ? $calculation['passed'] : null,
                'is_incomplete' => $calculation['is_incomplete'],
                'missing_count' => $calculation['missing_count'],
                'items' => array_map(fn (array $row) => $this->itemPayload($row, $isReleased), $calculation['items']),
                'categories' => $this->categoryPayload($calculation),
                'breakdown' => $this->finalGrades->componentSnapshot($calculation),
                'feedback' => array_values(array_filter(array_map(
                    fn (array $row) => $row['feedback'] === null ? null : [
                        'grade_item_id' => $row['grade_item_id'],
                        'name' => $row['name'],
                        'score' => $row['final_score'],
                        'feedback' => $row['feedback'],
                        'graded_at' => $row['graded_at'],
                    ],
                    $calculation['items'],
                ))),
                'reviews' => array_values(array_filter(
                    $this->reviews->forStudent($target),
                    fn (array $review) => (int) $review['gradebook_id'] === (int) $gradebook->id,
                )),
            ];
        }

        return $this->successResponse([
            'user_id' => $target,
            'is_self' => $target === $actor->id,
            'courses' => $rows,
            'summary' => $this->summary($rows),
        ], 'Nilai berhasil diambil');
    }

    /**
     * Learning performance seorang siswa (§47) — tren antar periode, bukan
     * kesimpulan otomatis tentang kemampuan anak.
     */
    public function myPerformance(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $target = $this->resolveTarget($request, $actor);
        $courses = $this->courseIdsFor($target);

        $courseRows = [];
        $averages = [];
        $outcomes = [];
        $competencies = [];

        foreach ($courses as $course) {
            $gradebook = $this->gradebooks->ensureForCourse($course);
            $release = $this->releases->forStudent($gradebook, $target);
            $isReleased = $release?->isVisible() ?? false;

            $targetActor = app(UserProviderInterface::class)->find($target);
            $progress = $targetActor === null
                ? null
                : app(CourseProgressService::class)->summary($course, $targetActor);

            $calculation = $this->calculation->calculate($gradebook, $target);

            if ($isReleased && $calculation['normalized'] !== null) {
                $averages[] = (float) $calculation['normalized'];
            }

            $courseRows[] = [
                'course_id' => (int) $course->id,
                'course_title' => $course->name,
                'current_grade' => $isReleased ? $calculation['normalized'] : null,
                'letter' => $isReleased ? $calculation['letter'] : null,
                'is_released' => $isReleased,
                'completion' => $progress['percent'] ?? null,
                'completed_items' => $progress['completed'] ?? null,
                'required_items' => $progress['required'] ?? null,
                'assignments' => $this->countBySource($calculation, 'ASSIGNMENT'),
                'quizzes' => $this->countBySource($calculation, 'QUIZ'),
                'missing_count' => $calculation['missing_count'],
            ];

            foreach ($this->outcomes->forStudent($course, $target) as $outcome) {
                $outcomes[] = $outcome + ['course_title' => $course->name];
            }

            foreach ($this->competencies->forStudent($course, $target) as $competency) {
                $competencies[] = $competency + ['course_title' => $course->name];
            }
        }

        return $this->successResponse([
            'user_id' => $target,
            'courses' => $courseRows,
            'average' => $averages === [] ? null : round(array_sum($averages) / count($averages), 2),
            'outcomes' => $outcomes,
            'competencies' => $competencies,
            'trend' => $this->trend($courseRows),
        ], 'Ringkasan performa belajar berhasil diambil');
    }

    /** Capaian learning outcome siswa (§28, §32). */
    public function myOutcomes(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $target = $this->resolveTarget($request, $actor);
        $this->authorizeRead($course, $actor, $target);

        return $this->successResponse(
            $this->outcomes->forStudent($course, $target),
            'Capaian learning outcome berhasil diambil',
        );
    }

    /** Kompetensi siswa beserta buktinya (§30–§31). */
    public function myCompetencies(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $target = $this->resolveTarget($request, $actor);
        $this->authorizeRead($course, $actor, $target);

        return $this->successResponse(
            $this->competencies->forStudent($course, $target),
            'Kompetensi berhasil diambil',
        );
    }

    /**
     * Tampilan wali: ringkasan nilai anak (§33).
     *
     * Hanya nilai yang sudah dirilis, tanpa catatan privat guru.
     */
    public function childGrades(Request $request, int $child): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $actor->isParent() && ! $actor->can('lms.grade.view')) {
            $this->authorizeLms(false, 'Hanya wali yang dapat membuka tampilan ini.');
        }

        if ($actor->isParent() && ! $this->parentWatches($actor, $child)) {
            $this->authorizeLms(false, 'Anda tidak memantau anak ini.');
        }

        $courses = $this->courseIdsFor($child);
        $childActor = app(UserProviderInterface::class)->find($child);
        $rows = [];
        $upcoming = [];

        foreach ($courses as $course) {
            $gradebook = $this->gradebooks->ensureForCourse($course);

            if (! $gradebook->is_parent_visible) {
                continue;
            }

            $release = $this->releases->forStudent($gradebook, $child);

            if ($release === null || ! $release->isVisible()) {
                continue;
            }

            $calculation = $this->calculation->calculate($gradebook, $child);

            $recent = array_values(array_filter(array_map(
                fn (array $row) => $row['final_score'] === null ? null : [
                    'grade_item_id' => $row['grade_item_id'],
                    'name' => $row['name'],
                    'source' => $row['source'],
                    'source_label' => $row['source_label'],
                    'score' => $row['final_score'],
                    'maximum' => $row['maximum_grade'],
                    'percent' => $row['percent'],
                    'feedback' => (bool) config('lms.parent.can_view_feedback', true) ? $row['feedback'] : null,
                    'graded_at' => $row['graded_at'],
                ],
                $calculation['items'],
            )));

            usort($recent, fn (array $a, array $b) => strcmp((string) $b['graded_at'], (string) $a['graded_at']));

            $rows[] = [
                'course_id' => (int) $course->id,
                'course_title' => $course->name,
                'current_grade' => $calculation['normalized'],
                'letter' => $calculation['letter'],
                'predicate' => $calculation['predicate'],
                'passed' => $calculation['passed'],
                'released_at' => $release->released_at?->toIso8601String(),
                'components' => $this->finalGrades->componentSnapshot($calculation),
                'recent_results' => array_slice($recent, 0, 6),
            ];

            if ($childActor !== null) {
                $agenda = app(LmsCalendarService::class)
                    ->agenda($childActor, now()->toDateString(), now()->addDays(45)->toDateString());

                foreach (array_merge($agenda['this_week'], $agenda['upcoming']) as $entry) {
                    if ((int) ($entry['course_id'] ?? 0) === (int) $course->id) {
                        $upcoming[] = $entry + ['course_title' => $course->name];
                    }
                }
            }
        }

        return $this->successResponse([
            'child_user_id' => $child,
            'courses' => $rows,
            'average' => $rows === []
                ? null
                : round(array_sum(array_map(fn (array $row) => (float) $row['current_grade'], $rows)) / count($rows), 2),
            'upcoming' => array_slice($upcoming, 0, 10),
            'reviews' => $this->reviews->forChildren([$child]),
            'can_view_feedback' => (bool) config('lms.parent.can_view_feedback', true),
        ], 'Nilai anak berhasil diambil');
    }

    /** Nilai seorang siswa pada satu course untuk guru/wali (§32, §33). */
    public function courseGrades(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $target = $this->resolveTarget($request, $actor);
        $this->authorizeRead($course, $actor, $target);

        $gradebook = $this->gradebooks->ensureForCourse($course);
        $release = $this->releases->forStudent($gradebook, $target);
        $isSelf = $target === $actor->id;
        $isStaff = $this->access->canGrade($course, $actor);
        $isReleased = $release?->isVisible() ?? false;

        $calculation = $this->calculation->calculate($gradebook, $target);
        $decorated = $this->finalGrades->decorate($gradebook, $calculation);

        $visibleItems = array_map(
            fn (array $row) => $this->itemPayload($row, $isReleased, $isStaff),
            $calculation['items'],
        );

        return $this->successResponse([
            'course_id' => (int) $course->id,
            'course_title' => $course->name,
            'user_id' => $target,
            'read_only' => ! $isStaff,
            // Ringkasan nilai aktivitas Phase 2 tetap dipertahankan supaya
            // kontrak lama (dan halaman yang sudah memakainya) tidak berubah.
            'summary' => $this->activityGrades->courseSummary((int) $course->id, $target),
            'is_released' => $isReleased,
            'release' => $release === null ? null : $this->releases->releasePayload($release),
            'display' => $isReleased || $isStaff
                ? $decorated['display']
                : ['value' => null, 'text' => 'Belum dirilis', 'letter' => null, 'percentage' => null, 'points' => null],
            'current_grade' => $isReleased || $isStaff ? $calculation['normalized'] : null,
            'letter' => $isReleased || $isStaff ? $decorated['letter'] : null,
            'passed' => $calculation['passed'],
            'is_incomplete' => $calculation['is_incomplete'],
            'missing_count' => $calculation['missing_count'],
            'items' => $visibleItems,
            'categories' => $this->categoryPayload($calculation),
            'breakdown' => $this->finalGrades->componentSnapshot($calculation),
            'history' => $isStaff ? $this->historyForCourse($gradebook, $target) : [],
        ], 'Nilai course berhasil diambil');
    }

    // ------------------------------------------------------------------ internal

    /** @return list<array<string, mixed>> */
    private function historyForCourse($gradebook, int $userId): array
    {
        return LmsGradeHistory::query()
            ->where('gradebook_id', $gradebook->id)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'action' => $row->action->value,
                'action_label' => $row->action->label(),
                'source' => $row->source,
                'reason' => $row->reason,
                'previous_value' => $row->previous_value,
                'new_value' => $row->new_value,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all();
    }

    /** @param array<string, mixed> $row */
    private function itemPayload(array $row, bool $isReleased, bool $isStaff = false): array
    {
        $visible = $isStaff || $isReleased;

        return [
            'grade_item_id' => $row['grade_item_id'],
            'name' => $row['name'],
            'source' => $row['source'],
            'source_label' => $row['source_label'],
            'category_id' => $row['category_id'],
            'category_name' => $row['category_name'],
            'weight' => $row['weight'],
            'maximum_grade' => $row['maximum_grade'],
            'score' => $visible ? $row['final_score'] : null,
            'percent' => $visible ? $row['percent'] : null,
            'status' => $row['status'],
            'status_label' => $visible ? $row['status_label'] : 'Belum dirilis',
            'is_missing' => $row['is_missing'],
            'is_exempt' => $row['is_exempt'],
            'exemption_reason' => $row['exemption_reason'],
            'feedback' => $visible ? $row['feedback'] : null,
            'feedback_meta' => $visible ? ($row['feedback_meta'] ?? null) : null,
            'graded_at' => $row['graded_at'],
            'passed' => $row['passed'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categoryPayload(array $calculation): array
    {
        return array_map(fn (array $block) => [
            'id' => $block['id'],
            'name' => $block['name'],
            'weight' => $block['weight'],
            'value' => $block['value'],
            'aggregation' => $block['aggregation'],
            'item_count' => count($block['items'] ?? []),
            'missing_count' => $block['missing'] ?? 0,
            'is_extra_credit' => $block['is_extra_credit'] ?? false,
        ], $calculation['blocks']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function summary(array $rows): array
    {
        $grades = array_values(array_filter(
            array_map(fn (array $row) => $row['current_grade'], $rows),
            fn ($value) => $value !== null,
        ));

        return [
            'course_count' => count($rows),
            'graded_course_count' => count($grades),
            'average' => $grades === [] ? null : round(array_sum($grades) / count($grades), 2),
            'passed_count' => count(array_filter($rows, fn (array $row) => $row['passed'] === true)),
            'release_pending_count' => count(array_filter($rows, fn (array $row) => $row['is_released'] === false)),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function trend(array $rows): array
    {
        $grades = array_values(array_filter(
            array_map(fn (array $row) => $row['current_grade'], $rows),
            fn ($value) => $value !== null,
        ));

        if (count($grades) < 2) {
            return ['direction' => 'insufficient_data', 'delta' => null];
        }

        $first = (float) $grades[0];
        $last = (float) $grades[count($grades) - 1];
        $delta = round($last - $first, 2);

        return [
            'direction' => $delta > 0.5 ? 'improving' : ($delta < -0.5 ? 'declining' : 'stable'),
            'delta' => $delta,
            'previous' => $first,
            'current' => $last,
        ];
    }

    private function countBySource(array $calculation, string $source): array
    {
        $rows = array_values(array_filter($calculation['items'], fn (array $row) => $row['source'] === $source));

        return [
            'total' => count($rows),
            'graded' => count(array_filter($rows, fn (array $row) => $row['final_score'] !== null)),
            'average' => $rows === []
                ? null
                : round(array_sum(array_map(fn (array $row) => (float) ($row['percent'] ?? 0), array_filter($rows, fn (array $row) => $row['percent'] !== null))) / max(count(array_filter($rows, fn (array $row) => $row['percent'] !== null)), 1), 2),
        ];
    }

    /** @return list<LmsCourse> */
    private function courseIdsFor(int $userId): array
    {
        $courseIds = LmsEnrollment::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['CANCELLED'])
            ->pluck('course_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        return $courseIds === [] ? [] : LmsCourse::query()->whereIn('id', $courseIds)->orderBy('title')->get()->all();
    }

    private function parentWatches(LmsActor $actor, int $childUserId): bool
    {
        foreach (app(StudentProviderInterface::class)->childrenOf($actor) as $child) {
            if ((int) ($child->userId ?? 0) === $childUserId) {
                return true;
            }
        }

        return false;
    }

    private function authorizeRead(LmsCourse $course, LmsActor $actor, int $target): void
    {
        $isSelf = $target === $actor->id;
        $isStaff = $this->canAccessCourseGrades($actor, $course, readOnly: true);
        $isParent = $actor->isParent() && $this->parentWatches($actor, $target);

        $this->authorizeLms(
            $isSelf || $isStaff || $isParent,
            'Anda tidak berhak melihat nilai ini.',
        );
    }

    /** Siswa hanya nilai sendiri; wali hanya anak yang sah (§53). */
    private function resolveTarget(Request $request, LmsActor $actor): int
    {
        $requested = $request->query('user_id');

        if ($requested === null) {
            return $actor->id;
        }

        // Siswa hanya boleh meminta nilainya sendiri: permintaan eksplisit ke
        // siswa lain ditolak, bukan diam-diam dialihkan (§53).
        if ($actor->isStudent()) {
            $this->authorizeLms((int) $requested === $actor->id, 'Anda hanya dapat melihat nilai Anda sendiri.');

            return $actor->id;
        }

        $target = (int) $requested;

        if ($target === $actor->id) {
            return $target;
        }

        if ($actor->isParent()) {
            $this->authorizeLms($this->parentWatches($actor, $target), 'Anda tidak memantau siswa ini.');

            return $target;
        }

        $this->authorizeLms(
            $actor->can('lms.grade.view') || $actor->can('lms.report.view'),
            'Anda tidak berhak melihat nilai siswa lain.',
        );

        return $target;
    }
}
