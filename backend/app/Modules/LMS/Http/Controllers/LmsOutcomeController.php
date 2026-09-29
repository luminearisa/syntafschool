<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\LearningOutcomeService;
use App\Modules\LMS\Application\Services\OutcomeAssessmentService;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Learning outcome & capaiannya (§26–§28, §48, §54).
 */
class LmsOutcomeController extends LmsController
{
    public function __construct(
        private readonly LearningOutcomeService $outcomes,
        private readonly OutcomeAssessmentService $assessment,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.outcome.manage') || $actor->can('lms.course.view'),
            'Anda tidak berhak melihat learning outcome course ini.',
        );

        return $this->successResponse([
            'outcomes' => $this->outcomes->forCourse($course),
            'coverage' => $this->outcomes->coverage($course),
            'achievement' => $this->assessment->courseAchievement($course),
        ], 'Learning outcome berhasil diambil');
    }

    public function store(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.outcome.manage'),
            'Anda tidak berhak menambah learning outcome.',
        );

        $data = $this->validatePayload($request, [
            'statement' => ['required', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:40'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'achievement_thresholds' => ['nullable', 'array'],
            'achievement_thresholds.developing' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'achievement_thresholds.achieved' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'achievement_thresholds.mastered' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'activity_ids' => ['nullable', 'array'],
            'activity_ids.*' => ['integer'],
        ]);

        $outcome = $this->outcomes->create($course, $data, $actor);

        return $this->createdResponse($outcome->toStructure(), 'Learning outcome dibuat');
    }

    public function update(Request $request, LmsLearningOutcome $outcome): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOutcome($outcome, $actor);

        $data = $this->validatePayload($request, [
            'statement' => ['sometimes', 'string', 'max:500'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,ARCHIVED'],
            'achievement_thresholds' => ['sometimes', 'nullable', 'array'],
            'activity_ids' => ['sometimes', 'array'],
            'activity_ids.*' => ['integer'],
        ]);

        return $this->successResponse(
            $this->outcomes->update($outcome, $data, $actor)->toStructure(),
            'Learning outcome diperbarui',
        );
    }

    public function destroy(Request $request, LmsLearningOutcome $outcome): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOutcome($outcome, $actor);

        $this->outcomes->delete($outcome, $actor);

        return $this->successResponse(null, 'Learning outcome dihapus');
    }

    /** Petakan aktivitas → outcome (§27). */
    public function map(Request $request, LmsLearningOutcome $outcome): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOutcome($outcome, $actor);

        $data = $this->validatePayload($request, [
            'activity_ids' => ['required', 'array'],
            'activity_ids.*' => ['integer'],
        ]);

        return $this->successResponse(
            $this->outcomes->mapActivities($outcome, $data['activity_ids'], $actor),
            'Pemetaan aktivitas ke outcome tersimpan',
        );
    }

    /** Hitung ulang capaian outcome seluruh course (§28). */
    public function recalculate(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->access->canGrade($course, $actor) || $actor->can('lms.outcome.assess'),
            'Anda tidak berhak menghitung capaian outcome.',
        );

        $rows = $this->assessment->recalculateCourse($course, $actor);

        return $this->successResponse([
            'rows' => $rows,
            'achievement' => $this->assessment->courseAchievement($course),
        ], 'Capaian learning outcome dihitung ulang');
    }

    /** Capaian satu siswa pada course ini (guru/wali/siswa). */
    public function studentOutcomes(Request $request, LmsCourse $course, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $student === $actor->id
                || $this->access->canGrade($course, $actor)
                || ($actor->isParent() && $this->access->parentWatchesCourse($course, $actor)),
            'Anda tidak berhak melihat capaian siswa ini.',
        );

        return $this->successResponse(
            $this->assessment->forStudent($course, $student),
            'Capaian siswa berhasil diambil',
        );
    }

    private function authorizeOutcome(LmsLearningOutcome $outcome, LmsActor $actor): void
    {
        $course = $outcome->course()->firstOrFail();

        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.outcome.manage'),
            'Anda tidak berhak mengubah learning outcome ini.',
        );
    }
}
