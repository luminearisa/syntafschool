<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\ChoiceService;
use App\Modules\LMS\Models\LmsChoice;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Polling sederhana (§38, §53). */
class LmsChoiceController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly ChoiceService $choices,
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
            $this->choices->view($model, $enrollment?->user_id) + ['state' => $state, 'can_manage' => $isStaff],
            'Polling berhasil diambil',
        );
    }

    public function updateConfig(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'allow_multiple' => ['sometimes', 'boolean'],
            'limit_responses' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'anonymous' => ['sometimes', 'boolean'],
            'show_available_choices' => ['sometimes', 'boolean'],
            'results_visibility' => ['sometimes', 'string', 'in:AFTER_RESPONSE,AFTER_CLOSE,ALWAYS,NEVER'],
            'options' => ['nullable', 'array', 'min:2'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.text' => ['required_with:options', 'string', 'max:500'],
            'options.*.limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->choices->updateForActivity($model, $data);

        return $this->successResponse($this->choices->view($model->fresh('choice'), null), 'Polling berhasil diperbarui');
    }

    /** Kirim jawaban polling (§38). */
    public function respond(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $data = $this->validatePayload($request, [
            'option_ids' => ['required', 'array', 'min:1'],
            'option_ids.*' => ['integer', 'exists:lms_choice_options,id'],
        ]);

        $result = $this->choices->respond($model, $enrollment, $data['option_ids'], $actor->studentId);

        return $this->successResponse($result, 'Jawaban polling tersimpan');
    }

    /** Hasil polling untuk guru (§38). */
    public function results(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse($this->choices->statistics($model), 'Hasil polling berhasil diambil');
    }

    // ------------------------------------------------------------------ alias §53

    /** GET /lms/choices/{choice}. */
    public function showByChoice(Request $request, LmsChoice $choice): JsonResponse
    {
        return $this->show($request, (int) $choice->activity_id);
    }

    /** POST /lms/choices/{choice}/respond. */
    public function respondByChoice(Request $request, LmsChoice $choice): JsonResponse
    {
        return $this->respond($request, (int) $choice->activity_id);
    }
}
