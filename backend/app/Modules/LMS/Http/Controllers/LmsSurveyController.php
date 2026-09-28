<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\SurveyService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Models\LmsSurvey;
use App\Modules\LMS\Models\LmsSurveyQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Survei umpan balik pembelajaran (§39, §53). */
class LmsSurveyController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly SurveyService $surveys,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    public function show(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        $course = $model->course;
        $enrollment = $course?->enrollmentFor($actor->id);
        $isStaff = $course !== null && $this->access->canGrade($course, $actor);

        $state = $this->availability->studentState($model, $enrollment, $isStaff);

        if (! $isStaff && $state['locked']) {
            return $this->successResponse(['locked' => true, 'state' => $state], $state['reason_label'] ?? 'Aktivitas terkunci');
        }

        return $this->successResponse(
            $this->surveys->view($model, $enrollment?->user_id) + ['state' => $state, 'can_manage' => $isStaff],
            'Survei berhasil diambil',
        );
    }

    public function updateConfig(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'anonymous' => ['sometimes', 'boolean'],
            'allow_multiple_submissions' => ['sometimes', 'boolean'],
            'show_results_to_students' => ['sometimes', 'boolean'],
            'questions' => ['nullable', 'array'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.text' => ['required_with:questions', 'string'],
            'questions.*.question_type' => ['nullable', 'string', 'in:MULTIPLE_CHOICE,RATING,TEXT,YES_NO'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.rating_min' => ['nullable', 'integer'],
            'questions.*.rating_max' => ['nullable', 'integer'],
            'questions.*.is_required' => ['nullable', 'boolean'],
        ]);

        $this->surveys->updateForActivity($model, $data);

        if (! empty($data['questions'])) {
            $survey = $this->surveys->ensureConfig($model);

            foreach ($data['questions'] as $index => $question) {
                $existing = isset($question['id'])
                    ? LmsSurveyQuestion::query()->where('survey_id', $survey->id)->find((int) $question['id'])
                    : null;

                if ($existing !== null) {
                    $this->surveys->updateQuestion($existing, $question + ['sort_order' => $question['sort_order'] ?? $index + 1]);
                } else {
                    $this->surveys->createQuestion($survey, $question + ['sort_order' => $index + 1]);
                }
            }
        }

        return $this->successResponse($this->surveys->view($model->fresh('survey'), null), 'Survei berhasil diperbarui');
    }

    /** Isi survei (§39). */
    public function submit(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [, $enrollment] = $this->requireEnrollment($request, $model);

        $data = $this->validatePayload($request, [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.response_text' => ['nullable', 'string'],
            'answers.*.response_value' => ['nullable', 'numeric'],
            'answers.*.response_data' => ['nullable'],
        ]);

        return $this->successResponse(
            $this->surveys->submit($model, $enrollment, $data['answers']),
            'Terima kasih, jawaban survei Anda tersimpan',
        );
    }

    /** Rekap hasil survei untuk guru (§39). */
    public function results(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse($this->surveys->statistics($model), 'Hasil survei berhasil diambil');
    }

    public function destroyQuestion(Request $request, LmsSurveyQuestion $question): JsonResponse
    {
        $activity = $question->survey?->activity;
        $this->authorizeManage($request, $activity);

        $this->surveys->deleteQuestion($question);

        return $this->successResponse(null, 'Pertanyaan survei dihapus');
    }

    // ------------------------------------------------------------------ alias §53

    /** GET /lms/surveys/{survey}. */
    public function showBySurvey(Request $request, LmsSurvey $survey): JsonResponse
    {
        return $this->show($request, (int) $survey->activity_id);
    }

    /** POST /lms/surveys/{survey}/submit. */
    public function submitBySurvey(Request $request, LmsSurvey $survey): JsonResponse
    {
        return $this->submit($request, (int) $survey->activity_id);
    }
}
