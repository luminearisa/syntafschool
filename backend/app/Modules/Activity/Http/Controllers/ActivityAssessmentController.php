<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAssessmentService;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Models\ActivityAssessment;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rubrik, penilaian, progres, dan penyelesaian (\§36\–41).
 */
class ActivityAssessmentController extends BaseApiController
{
    public function __construct(
        private readonly ActivityAssessmentService $assessments,
        private readonly ActivityAccessService $access,
    ) {}

    // ----------------------------------------------------------- rubrik

    public function rubrics(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse(ActivityRubric::query()->with('items')->where('program_id', $program->id)->get());
    }

    public function storeRubric(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'type' => ['nullable', 'string', 'max:24'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.code' => ['required', 'string', 'max:48'],
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.weight' => ['nullable', 'numeric', 'min:0'],
            'items.*.max_score' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->createdResponse(
            $this->assessments->createRubric($program, $request->all(), $request->user()),
            'Rubrik dibuat.',
        );
    }

    // -------------------------------------------------------- penilaian

    public function index(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($program->assessments()->with('rubric:id,title')->orderByDesc('id')->get());
    }

    public function store(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'type' => ['nullable', Rule::in(['SKILL', 'PERFORMANCE', 'PARTICIPATION', 'PROJECT', 'COMPETENCY', 'BEHAVIOR'])],
            'max_score' => ['nullable', 'numeric', 'min:1'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_final' => ['nullable', 'boolean'],
            'session_id' => ['nullable', 'integer', 'exists:activity_sessions,id'],
            'rubric_id' => ['nullable', 'integer', 'exists:activity_rubrics,id'],
        ]);

        return $this->createdResponse(
            $this->assessments->createAssessment($program, $request->all(), $request->user()),
            'Penilaian dibuat.',
        );
    }

    public function publish(ActivityAssessment $assessment, Request $request): JsonResponse
    {
        return $this->successResponse($this->assessments->publishAssessment($assessment, $request->user()), 'Penilaian diterbitkan.');
    }

    public function results(ActivityAssessment $assessment): JsonResponse
    {
        return $this->successResponse($this->assessments->results($assessment));
    }

    public function submitResult(ActivityAssessment $assessment, Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'score' => ['nullable', 'numeric', 'min:0'],
            'level' => ['nullable', Rule::in(['BEGINNER', 'DEVELOPING', 'COMPETENT', 'ADVANCED'])],
            'criteria_scores' => ['nullable', 'array'],
            'feedback' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        return $this->successResponse(
            $this->assessments->submitResult($assessment, (int) $request->input('student_id'), $request->all(), $request->user()),
            'Nilai dicatat.',
        );
    }

    public function submitResults(ActivityAssessment $assessment, Request $request): JsonResponse
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'rows.*.score' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        return $this->successResponse(
            $this->assessments->submitResults($assessment, $request->input('rows'), $request->user(), $request->input('idempotency_key')),
            'Nilai kelas dicatat.',
        );
    }

    // --------------------------------------------------- progres & selesai

    public function progress(ActivityMembership $membership, Request $request): JsonResponse
    {
        if (! $this->access->canViewStudent($request->user(), (int) $membership->student_id)) {
            return $this->errorResponse('Anda tidak berwenang melihat progres ini.', 403);
        }

        return $this->successResponse($this->assessments->recalculateProgress((int) $membership->id));
    }

    public function evaluateCompletion(ActivityMembership $membership, Request $request): JsonResponse
    {
        if (! $this->access->canViewStudent($request->user(), (int) $membership->student_id)
            && ! $request->user()->can('activity.assessment.manage')) {
            return $this->errorResponse('Anda tidak berwenang mengevaluasi penyelesaian ini.', 403);
        }

        return $this->successResponse($this->assessments->evaluateCompletion($membership));
    }

    public function complete(ActivityMembership $membership, Request $request): JsonResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string'],
            'waive_rules' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        return $this->successResponse(
            $this->assessments->complete($membership, $request->user(), $request->all()),
            'Penyelesaian aktivitas ditetapkan.',
        );
    }
}
