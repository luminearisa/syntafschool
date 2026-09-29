<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\LessonService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsLesson;
use App\Modules\LMS\Models\LmsLessonPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lesson: halaman, cabang, dan progres belajar (§37, §53).
 */
class LmsLessonController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly LessonService $lessons,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    /** Tampilan lesson untuk siswa. */
    public function show(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $state = $this->availability->studentState($model, $enrollment);

        if ($state['locked']) {
            return $this->successResponse(['locked' => true, 'state' => $state], $state['reason_label'] ?? 'Aktivitas terkunci');
        }

        $lesson = $this->lessons->ensureConfig($model);
        $pageId = $request->query('page_id') === null ? null : (int) $request->query('page_id');

        return $this->successResponse(
            $this->lessons->pageView($lesson, $pageId, $actor->id) + ['state' => $state, 'locked' => false],
            'Lesson berhasil diambil',
        );
    }

    /** Catat kunjungan halaman + jawaban (§37). */
    public function progress(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $data = $this->validatePayload($request, [
            'page_id' => ['required', 'integer'],
            'answer_key' => ['nullable', 'string', 'max:100'],
        ]);

        $lesson = $this->lessons->ensureConfig($model);
        $page = $lesson->pages()->with(['question.options', 'branches'])->findOrFail((int) $data['page_id']);

        return $this->successResponse(
            $this->lessons->recordView($lesson, $page, $enrollment, $data),
            'Progres lesson disimpan',
        );
    }

    /** Struktur lesson untuk guru (editor). */
    public function builder(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        $lesson = $this->lessons->ensureConfig($model);

        return $this->successResponse([
            'lesson' => $this->lessons->configPayload($lesson),
            'pages' => $lesson->pages()->with(['branches', 'question'])->get()->map(fn (LmsLessonPage $page) => [
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'content' => $page->content,
                'page_type' => $page->page_type?->value,
                'question_id' => $page->question_id === null ? null : (int) $page->question_id,
                'is_required' => (bool) $page->is_required,
                'sort_order' => (int) $page->sort_order,
                'branches' => $page->branches->map(fn ($branch) => [
                    'id' => (int) $branch->id,
                    'label' => $branch->label,
                    'answer_key' => $branch->answer_key,
                    'is_correct' => (bool) $branch->is_correct,
                    'next_page_id' => $branch->next_page_id === null ? null : (int) $branch->next_page_id,
                ])->all(),
            ])->all(),
            'statistics' => $this->lessons->statistics($model),
        ], 'Builder lesson berhasil diambil');
    }

    public function updateConfig(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'require_all_pages' => ['sometimes', 'boolean'],
            'max_attempts' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'allow_review' => ['sometimes', 'boolean'],
        ]);

        $this->lessons->updateForActivity($model, $data);

        return $this->successResponse($this->lessons->configPayload($this->lessons->ensureConfig($model->fresh('lesson'))), 'Konfigurasi lesson disimpan');
    }

    /** Tambah halaman (dengan cabang opsional) (§37). */
    public function storePage(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['nullable', 'string'],
            'page_type' => ['nullable', 'string', 'in:CONTENT,QUESTION'],
            'question_id' => ['nullable', 'integer', 'exists:lms_questions,id'],
            'is_required' => ['nullable', 'boolean'],
            'branches' => ['nullable', 'array'],
            'branches.*.next_page_id' => ['nullable', 'integer'],
            'branches.*.label' => ['nullable', 'string', 'max:200'],
            'branches.*.is_correct' => ['nullable', 'boolean'],
            'branches.*.answer_key' => ['nullable', 'string', 'max:100'],
        ]);

        $lesson = $this->lessons->ensureConfig($model);
        $page = $this->lessons->createPage($lesson, $data);

        if (! empty($data['branches'])) {
            $this->lessons->updatePage($page, ['branches' => $data['branches']]);
        }

        return $this->createdResponse(['id' => (int) $page->id], 'Halaman lesson berhasil dibuat');
    }

    public function updatePage(Request $request, LmsLessonPage $page): JsonResponse
    {
        $activity = $page->lesson?->activity;
        $this->authorizeManage($request, $activity);

        $data = $this->validatePayload($request, [
            'title' => ['sometimes', 'string', 'max:200'],
            'content' => ['nullable', 'string'],
            'page_type' => ['nullable', 'string', 'in:CONTENT,QUESTION'],
            'question_id' => ['nullable', 'integer', 'exists:lms_questions,id'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
            'branches' => ['nullable', 'array'],
            'branches.*.next_page_id' => ['nullable', 'integer'],
            'branches.*.label' => ['nullable', 'string', 'max:200'],
            'branches.*.is_correct' => ['nullable', 'boolean'],
            'branches.*.answer_key' => ['nullable', 'string', 'max:100'],
        ]);

        $updated = $this->lessons->updatePage($page, $data);

        return $this->successResponse(['id' => (int) $updated->id, 'branches' => $updated->branches->count()], 'Halaman lesson diperbarui');
    }

    public function destroyPage(Request $request, LmsLessonPage $page): JsonResponse
    {
        $activity = $page->lesson?->activity;
        $this->authorizeManage($request, $activity);

        $this->lessons->deletePage($page);

        return $this->successResponse(null, 'Halaman lesson dihapus');
    }

    /** Statistik lesson untuk guru. */
    public function statistics(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse($this->lessons->statistics($model), 'Statistik lesson berhasil diambil');
    }

    // ------------------------------------------------------------------ alias §53

    /** POST /lms/lessons/{lesson}/progress. */
    public function progressByLesson(Request $request, LmsLesson $lesson): JsonResponse
    {
        return $this->progress($request, (int) $lesson->activity_id);
    }

    /** GET /lms/lessons/{lesson}. */
    public function showByLesson(Request $request, LmsLesson $lesson): JsonResponse
    {
        return $this->show($request, (int) $lesson->activity_id);
    }
}
