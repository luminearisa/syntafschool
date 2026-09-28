<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\ActivityService;
use App\Modules\LMS\Application\Services\AssignmentGradingService;
use App\Modules\LMS\Application\Services\AssignmentService;
use App\Modules\LMS\Application\Services\AssignmentSubmissionService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Models\LmsAssignment;
use App\Modules\LMS\Models\LmsAssignmentSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tugas: tampilan siswa, konfigurasi guru, tabel submission, dan statistik
 * (§3–§4, §10–§11, §53).
 */
class LmsAssignmentController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly ActivityService $activities,
        private readonly AssignmentSubmissionService $submissions,
        private readonly AssignmentGradingService $grading,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    /** Tampilan tugas untuk siswa (§10). */
    public function show(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $state = $this->availability->studentState($model, $enrollment);

        if ($state['locked']) {
            return $this->successResponse([
                'locked' => true,
                'state' => $state,
            ], $state['reason_label'] ?? 'Aktivitas terkunci');
        }

        $view = $this->assignments->studentView($model, $enrollment, $actor->id);

        return $this->successResponse($view + ['state' => $state, 'locked' => false], 'Detail tugas berhasil diambil');
    }

    /** Ringkasan guru: konfigurasi, statistik, dan tabel submission (§11). */
    public function overview(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        $filters = $request->only(['status', 'late', 'graded', 'search']);

        $submissions = $this->assignments->teacherOverview($model, $filters, $this->perPage($request, 25));

        return $this->successResponse([
            'activity' => [
                'id' => (int) $model->id,
                'title' => (string) $model->title,
                'status' => $model->status?->value,
                'due_at' => $model->due_at?->toIso8601String(),
                'close_at' => $model->close_at?->toIso8601String(),
            ],
            'assignment' => $this->assignments->configPayload($this->assignments->ensureConfig($model)),
            'statistics' => $this->assignments->statistics($model),
            'submissions' => $submissions->through(fn (LmsAssignmentSubmission $submission) => $this->assignments->submissionPayload($submission) + [
                'student' => [
                    'user_id' => (int) $submission->user_id,
                    'name' => $submission->user?->name,
                    'student_id' => $submission->student_id === null ? null : (int) $submission->student_id,
                ],
            ]),
        ], 'Ringkasan tugas berhasil diambil');
    }

    /** Ubah konfigurasi tugas (tab Grading/Settings pada wizard) (§4, §43). */
    public function updateConfig(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'submission_type' => ['sometimes', 'string', 'in:ONLINE_TEXT,FILE,ONLINE_TEXT_AND_FILE,NONE'],
            'grading_type' => ['sometimes', 'string', 'in:POINTS,PASS_FAIL,RUBRIC'],
            'maximum_grade' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            'passing_grade' => ['nullable', 'numeric', 'min:0'],
            'maximum_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'allow_late_submission' => ['sometimes', 'boolean'],
            'late_penalty_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'late_penalty_days_step' => ['nullable', 'integer', 'min:1'],
            'late_penalty_max_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'group_submission' => ['sometimes', 'boolean'],
            'require_file' => ['sometimes', 'boolean'],
            'require_text' => ['sometimes', 'boolean'],
            'allowed_file_types' => ['nullable', 'array'],
            'allowed_file_types.*' => ['string', 'in:PDF,DOC,DOCX,PPT,PPTX,XLS,XLSX,ZIP,IMAGE'],
            'max_file_size_kb' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->assignments->updateForActivity($model, $data);

        return $this->successResponse(
            $this->assignments->configPayload($this->assignments->ensureConfig($model->fresh('assignment'))),
            'Konfigurasi tugas berhasil disimpan',
        );
    }

    /** Statistik saja (dipakai tab Statistics). */
    public function statistics(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse([
            'statistics' => $this->assignments->statistics($model),
            'policy' => $this->grading->summary($model),
        ], 'Statistik tugas berhasil diambil');
    }

    /** Penilaian massal sederhana (§11). */
    public function bulkGrade(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeGrade($request, $model);

        $data = $this->validatePayload($request, [
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.submission_id' => ['required', 'integer'],
            'grades.*.grade' => ['required', 'numeric', 'min:0'],
            'grades.*.feedback' => ['nullable', 'string'],
        ]);

        $result = $this->grading->bulkGrade($data['grades'], $actor);

        return $this->successResponse($result, $result['graded'].' submission berhasil dinilai');
    }

    // ------------------------------------------------------------------ alias §53

    /** GET /lms/assignments/{assignment} — sama dengan show(activity). */
    public function showByAssignment(Request $request, LmsAssignment $assignment): JsonResponse
    {
        return $this->show($request, (int) $assignment->activity_id);
    }

    /** POST /lms/assignments/{assignment}/submit — pengumpulan oleh siswa. */
    public function submitByAssignment(Request $request, LmsAssignment $assignment): JsonResponse
    {
        return app(LmsSubmissionController::class)->submit($request, (int) $assignment->activity_id);
    }

    /** GET /lms/assignments/{assignment}/submissions — tabel pengumpulan. */
    public function submissionsByAssignment(Request $request, LmsAssignment $assignment): JsonResponse
    {
        return app(LmsSubmissionController::class)->index($request, (int) $assignment->activity_id);
    }
}
