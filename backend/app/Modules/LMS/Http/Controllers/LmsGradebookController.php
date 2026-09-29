<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\AssessmentResultService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\FinalGradeService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\GradeCalculationService;
use App\Modules\LMS\Application\Services\GradeExportService;
use App\Modules\LMS\Application\Services\GradeItemService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Application\Services\GradeStatisticsService;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeCategory;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradeScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Gradebook guru (§2–§11, §34–§38, §54).
 *
 * Controller ini sengaja tipis: seluruh perhitungan ada di
 * `GradeCalculationService`/`FinalGradeService`, konfigurasi di
 * `GradebookService`.
 */
class LmsGradebookController extends LmsController
{
    public function __construct(
        private readonly GradebookService $gradebooks,
        private readonly GradeItemService $items,
        private readonly GradeCalculationService $calculation,
        private readonly FinalGradeService $finalGrades,
        private readonly GradeReleaseService $releases,
        private readonly GradeStatisticsService $statistics,
        private readonly GradeExportService $export,
        private readonly AssessmentResultService $results,
        private readonly CourseAccessService $access,
    ) {}

    /** Gradebook sebuah course (guru pemilik / staf). */
    public function show(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course, readOnly: true),
            'Anda tidak berhak melihat gradebook course ini.',
        );

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        return $this->successResponse([
            'gradebook' => $this->gradebooks->configuration($gradebook),
            'items' => $gradebook->items()->with('category')->get()
                ->map(fn (LmsGradeItem $item) => $this->items->toRow($item, withStats: false))
                ->all(),
            'sources' => $this->gradebooks->sources(),
            'scales' => $this->gradebooks->scales((int) $course->id)->map(fn (LmsGradeScale $scale) => [
                'id' => (int) $scale->id,
                'name' => $scale->name,
                'scope' => $scale->scope->value,
                'is_default' => (bool) $scale->is_default,
                'entries' => $scale->entries->map(fn ($entry) => [
                    'grade' => $entry->grade,
                    'min_score' => (float) $entry->min_score,
                    'max_score' => (float) $entry->max_score,
                    'predicate' => $entry->predicate,
                ])->all(),
            ])->all(),
        ], 'Gradebook berhasil diambil');
    }

    /** Ubah konfigurasi gradebook (§5–§11). */
    public function update(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);

        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string'],
            'grading_method' => ['sometimes', 'string', 'in:RAW,PERCENTAGE,LETTER,POINTS'],
            'aggregation_method' => ['sometimes', 'string', 'in:WEIGHTED_MEAN,SIMPLE_MEAN,SUM,HIGHEST,LOWEST,MEDIAN'],
            'missing_grade_policy' => ['sometimes', 'string', 'in:MISSING_AS_ZERO,EXCLUDE,PENDING'],
            'weight_policy' => ['sometimes', 'string', 'in:STRICT,NORMALIZE'],
            'extra_credit_policy' => ['sometimes', 'string', 'in:CAP,UNCAPPED,IGNORE'],
            'maximum_grade' => ['sometimes', 'numeric', 'gt:0'],
            'passing_grade' => ['sometimes', 'numeric', 'min:0'],
            'grade_scale_id' => ['sometimes', 'nullable', 'integer', 'exists:lms_grade_scales,id'],
            'is_parent_visible' => ['sometimes', 'boolean'],
            'is_ranking_visible' => ['sometimes', 'boolean'],
            'is_letter_visible' => ['sometimes', 'boolean'],
            'allow_extra_credit' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE,LOCKED,FROZEN,ARCHIVED'],
        ]);

        $gradebook = $this->gradebooks->update($gradebook, $data, $actor);

        return $this->successResponse($this->gradebooks->configuration($gradebook->refresh()), 'Konfigurasi gradebook diperbarui');
    }

    /** §44 — bekukan gradebook. */
    public function freeze(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);

        return $this->successResponse(
            $this->gradebooks->configuration($this->gradebooks->freeze($gradebook, $actor)),
            'Gradebook dibekukan. Perubahan nilai memerlukan izin khusus.',
        );
    }

    public function unfreeze(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);

        return $this->successResponse(
            $this->gradebooks->configuration($this->gradebooks->unfreeze($gradebook, $actor)),
            'Gradebook diaktifkan kembali',
        );
    }

    // ------------------------------------------------------------- kategori

    public function storeCategory(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_grade_categories,id'],
            'description' => ['nullable', 'string'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'aggregation_method' => ['nullable', 'string', 'in:WEIGHTED_MEAN,SIMPLE_MEAN,SUM,HIGHEST,LOWEST,MEDIAN'],
            'missing_grade_policy' => ['nullable', 'string', 'in:MISSING_AS_ZERO,EXCLUDE,PENDING'],
            'drop_lowest' => ['nullable', 'integer', 'min:0', 'max:20'],
            'is_extra_credit' => ['nullable', 'boolean'],
            'include_in_final' => ['nullable', 'boolean'],
        ]);

        $category = $this->gradebooks->createCategory($gradebook, $data, $actor);

        return $this->createdResponse([
            'category' => ['id' => (int) $category->id, 'name' => $category->name, 'weight' => (float) $category->weight],
            'weights' => $this->gradebooks->weightSummary($gradebook->refresh()),
        ], 'Kategori nilai dibuat');
    }

    public function updateCategory(Request $request, LmsGradeCategory $category): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $category->gradebook()->firstOrFail()->course()->firstOrFail()),
            'Anda tidak berhak mengubah kategori nilai ini.',
        );

        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:lms_grade_categories,id'],
            'description' => ['sometimes', 'nullable', 'string'],
            'weight' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'aggregation_method' => ['sometimes', 'nullable', 'string', 'in:WEIGHTED_MEAN,SIMPLE_MEAN,SUM,HIGHEST,LOWEST,MEDIAN'],
            'missing_grade_policy' => ['sometimes', 'nullable', 'string', 'in:MISSING_AS_ZERO,EXCLUDE,PENDING'],
            'drop_lowest' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'is_extra_credit' => ['sometimes', 'boolean'],
            'include_in_final' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $this->gradebooks->updateCategory($category, $data, $actor);

        return $this->successResponse([
            'category' => ['id' => (int) $updated->id, 'name' => $updated->name, 'weight' => (float) $updated->weight],
            'weights' => $this->gradebooks->weightSummary($updated->gradebook()->firstOrFail()),
        ], 'Kategori nilai diperbarui');
    }

    public function deleteCategory(Request $request, LmsGradeCategory $category): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $category->gradebook()->firstOrFail();
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $gradebook->course()->firstOrFail()),
            'Anda tidak berhak menghapus kategori nilai ini.',
        );

        $this->gradebooks->deleteCategory($category, $actor);

        return $this->successResponse(null, 'Kategori nilai dihapus; item dipindahkan tanpa kategori');
    }

    // ------------------------------------------------------------- grade item

    public function items(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course, readOnly: true),
            'Anda tidak berhak melihat komponen nilai course ini.',
        );

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        return $this->successResponse(
            $gradebook->items()->with('category')->orderBy('sort_order')->get()
                ->map(fn (LmsGradeItem $item) => $this->items->toRow($item, withStats: true))
                ->all(),
            'Komponen nilai berhasil diambil',
        );
    }

    public function storeItem(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:180'],
            'category_id' => ['nullable', 'integer', 'exists:lms_grade_categories,id'],
            'activity_id' => ['nullable', 'integer', 'exists:lms_activities,id'],
            'source' => ['nullable', 'string', 'in:ASSIGNMENT,QUIZ,FORUM,LESSON,MANUAL,EXTERNAL'],
            'description' => ['nullable', 'string'],
            'maximum_grade' => ['nullable', 'numeric', 'gt:0'],
            'passing_grade' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grade_type' => ['nullable', 'string', 'in:RAW,PERCENTAGE,LETTER,POINTS'],
            'visibility' => ['nullable', 'string', 'in:VISIBLE,HIDDEN,RELEASED_ONLY'],
            'is_extra_credit' => ['nullable', 'boolean'],
            'include_in_final' => ['nullable', 'boolean'],
            'rubric_id' => ['nullable', 'integer', 'exists:lms_rubrics,id'],
        ]);

        $item = $this->items->create($gradebook, $data, $actor);

        return $this->createdResponse([
            'item' => $this->items->toRow($item, withStats: true),
            'weights' => $this->gradebooks->weightSummary($gradebook->refresh()),
        ], 'Komponen nilai ditambahkan');
    }

    public function updateItem(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $item->gradebook()->firstOrFail()->course()->firstOrFail();
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course),
            'Anda tidak berhak mengubah komponen nilai ini.',
        );

        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'max:180'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:lms_grade_categories,id'],
            'description' => ['sometimes', 'nullable', 'string'],
            'maximum_grade' => ['sometimes', 'numeric', 'gt:0'],
            'passing_grade' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'aggregation_method' => ['sometimes', 'nullable', 'string', 'in:WEIGHTED_MEAN,SIMPLE_MEAN,SUM,HIGHEST,LOWEST,MEDIAN'],
            'grade_type' => ['sometimes', 'string', 'in:RAW,PERCENTAGE,LETTER,POINTS'],
            'visibility' => ['sometimes', 'string', 'in:VISIBLE,HIDDEN,RELEASED_ONLY'],
            'is_extra_credit' => ['sometimes', 'boolean'],
            'include_in_final' => ['sometimes', 'boolean'],
            'rubric_id' => ['sometimes', 'nullable', 'integer', 'exists:lms_rubrics,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $this->items->update($item, $data, $actor);

        return $this->successResponse([
            'item' => $this->items->toRow($updated->refresh(), withStats: true),
            'weights' => $this->gradebooks->weightSummary($updated->gradebook()->firstOrFail()),
        ], 'Komponen nilai diperbarui');
    }

    public function deleteItem(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $item->gradebook()->firstOrFail()->course()->firstOrFail();
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course),
            'Anda tidak berhak menghapus komponen nilai ini.',
        );

        $this->items->delete($item, $actor);

        return $this->successResponse(null, 'Komponen nilai dihapus');
    }

    /** Sinkronkan aktivitas bernilai course menjadi grade item (§64). */
    public function syncItems(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);

        $created = $this->items->syncFromCourse($gradebook, $actor->id);
        $pulled = $this->results->pullFromActivities($gradebook, $actor);

        return $this->successResponse([
            'created_items' => $created,
            'pulled_results' => $pulled,
            'weights' => $this->gradebooks->weightSummary($gradebook->refresh()),
        ], 'Gradebook disinkronkan dengan aktivitas course');
    }

    // ------------------------------------------------------------- skala nilai

    public function scales(Request $request, LmsCourse $course): JsonResponse
    {
        $this->actor($request);

        return $this->successResponse(
            $this->gradebooks->scales((int) $course->id)->map(fn (LmsGradeScale $scale) => [
                'id' => (int) $scale->id,
                'code' => $scale->code,
                'name' => $scale->name,
                'scope' => $scale->scope->value,
                'scope_label' => $scale->scope->label(),
                'is_default' => (bool) $scale->is_default,
                'is_active' => (bool) $scale->is_active,
                'entries' => $scale->entries->map(fn ($entry) => [
                    'grade' => $entry->grade,
                    'min_score' => (float) $entry->min_score,
                    'max_score' => (float) $entry->max_score,
                    'predicate' => $entry->predicate,
                    'gpa' => $entry->gpa === null ? null : (float) $entry->gpa,
                ])->all(),
            ])->all(),
            'Daftar skala nilai berhasil diambil',
        );
    }

    public function storeScale(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'scope' => ['nullable', 'string', 'in:SCHOOL,COURSE,ACADEMIC_YEAR'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'academic_year_id' => ['nullable', 'integer'],
            'semester_id' => ['nullable', 'integer'],
            'is_default' => ['nullable', 'boolean'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.grade' => ['required', 'string', 'max:10'],
            'entries.*.min_score' => ['required', 'numeric'],
            'entries.*.max_score' => ['required', 'numeric', 'gte:entries.*.min_score'],
            'entries.*.predicate' => ['nullable', 'string', 'max:60'],
            'entries.*.gpa' => ['nullable', 'numeric'],
        ]);

        $scale = $this->gradebooks->createScale($data, $actor, $data['entries']);

        return $this->createdResponse([
            'id' => (int) $scale->id,
            'name' => $scale->name,
            'entries' => $scale->entries->count(),
        ], 'Skala nilai dibuat');
    }

    // ------------------------------------------------------------- guru: spreadsheet

    /**
     * Tabel nilai guru (§34–§35) — baris siswa, kolom grade item + nilai akhir.
     */
    public function sheet(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course, readOnly: true),
            'Anda tidak berhak melihat tabel nilai course ini.',
        );

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);
        $userIds = $this->statistics->studentIds($course);

        $filters = [
            'student' => $request->query('student'),
            'group_id' => $request->query('group_id'),
            'category_id' => $request->query('category_id'),
            'grade_item_id' => $request->query('grade_item_id'),
            'status' => $request->query('status'),
            'only_missing' => filter_var($request->query('only_missing', false), FILTER_VALIDATE_BOOLEAN),
            'below_passing' => filter_var($request->query('below_passing', false), FILTER_VALIDATE_BOOLEAN),
        ];

        $rows = [];
        $releases = $this->releases->releases($gradebook);

        foreach ($userIds as $userId) {
            $calculation = $this->calculation->calculate($gradebook, $userId);

            if ($filters['only_missing'] && $calculation['missing_count'] === 0) {
                continue;
            }

            if ($filters['below_passing'] && ($calculation['passed'] ?? true)) {
                continue;
            }

            $rows[] = [
                'user_id' => $userId,
                'name' => $this->studentName($userId, $course),
                'student_id' => $this->studentId($course, $userId),
                'results' => array_map(fn (array $row) => [
                    'grade_item_id' => $row['grade_item_id'],
                    'final_score' => $row['final_score'],
                    'normalized' => $row['normalized'],
                    'status' => $row['status'],
                    'is_exempt' => $row['is_exempt'],
                    'is_missing' => $row['is_missing'],
                    'feedback' => $row['feedback'],
                ], $calculation['items']),
                'final' => [
                    'normalized' => $calculation['normalized'],
                    'final_score' => $calculation['final_score'],
                    'letter' => $calculation['letter'],
                    'passed' => $calculation['passed'],
                    'is_incomplete' => $calculation['is_incomplete'],
                ],
                'release' => $releases[$userId] ?? null,
            ];
        }

        if (is_string($filters['student']) && $filters['student'] !== '') {
            $needle = mb_strtolower($filters['student']);
            $rows = array_values(array_filter($rows, fn (array $row) => str_contains(mb_strtolower((string) $row['name']), $needle)));
        }

        return $this->successResponse([
            'gradebook' => $this->gradebooks->configuration($gradebook->refresh()),
            'items' => $gradebook->items()->with('category')->orderBy('sort_order')->get()
                ->map(fn (LmsGradeItem $item) => $this->items->toRow($item))
                ->all(),
            'rows' => $rows,
            'statistics' => $this->statistics->courseSummary($gradebook),
            'filters' => $filters,
        ], 'Tabel nilai berhasil diambil');
    }

    /** Hitung ulang nilai akhir seluruh siswa (§14, §43). */
    public function calculate(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $gradebook = $this->gradebookForWrite($request, $course);
        $userIds = $this->statistics->studentIds($course);

        $rows = [];

        foreach ($userIds as $userId) {
            $grade = $this->finalGrades->forStudent($gradebook, $userId);
            $rows[] = [
                'user_id' => $userId,
                'name' => $this->studentName($userId, $course),
                'normalized' => $grade['normalized'],
                'final_score' => $grade['final_score'],
                'letter' => $grade['letter'],
                'passed' => $grade['passed'],
                'is_incomplete' => $grade['is_incomplete'],
                'breakdown' => $this->finalGrades->componentSnapshot($grade),
            ];
        }

        $gradebook->last_calculated_at = now();
        $gradebook->save();

        return $this->successResponse([
            'gradebook_id' => (int) $gradebook->id,
            'calculated_at' => $gradebook->last_calculated_at?->toIso8601String(),
            'weights' => $this->gradebooks->weightSummary($gradebook),
            'students' => $rows,
            'statistics' => $this->statistics->courseSummary($gradebook),
        ], 'Nilai berhasil dihitung ulang');
    }

    /** Statistik & distribusi nilai (§36–§37). */
    public function statistics(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course, readOnly: true),
            'Anda tidak berhak melihat statistik nilai course ini.',
        );

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        return $this->successResponse([
            'summary' => $this->statistics->courseSummary($gradebook),
            'performance' => $this->statistics->teacherPerformance($course),
        ], 'Statistik nilai berhasil diambil');
    }

    /** Ekspor nilai mengikuti filter aktif (§38). */
    public function export(Request $request, LmsCourse $course): Response|JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course, readOnly: true),
            'Anda tidak berhak mengekspor nilai course ini.',
        );

        $format = strtolower((string) $request->query('format', 'csv'));
        $this->export->assertFormatSupported($format);

        if ($format !== 'csv') {
            return $this->errorResponse('Format ekspor belum tersedia pada instalasi ini.', 422);
        }

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        $filters = [
            'category_id' => $request->query('category_id'),
            'grade_item_id' => $request->query('grade_item_id'),
            'user_ids' => $request->query('user_ids'),
        ];

        $csv = $this->export->csv($gradebook, array_filter($filters, fn ($value) => $value !== null && $value !== ''));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->export->filename($gradebook).'"',
        ]);
    }

    // ------------------------------------------------------------- helpers

    private function gradebookForWrite(Request $request, LmsCourse $course): LmsGradebook
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course),
            'Anda tidak berhak mengelola gradebook course ini.',
        );

        return $this->gradebooks->ensureForCourse($course, $actor->id);
    }

    private function studentName(int $userId, LmsCourse $course): ?string
    {
        $provider = app(UserProviderInterface::class);

        return $provider->find($userId)?->name;
    }

    private function studentId(LmsCourse $course, int $userId): ?int
    {
        return $course->enrollmentFor($userId)?->student_id;
    }
}
