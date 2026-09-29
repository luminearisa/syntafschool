<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CompetencyAssessmentService;
use App\Modules\LMS\Application\Services\CompetencyService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Domain\Enums\CompetencyEvidenceType;
use App\Modules\LMS\Domain\Enums\CompetencyStatus;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCompetency;
use App\Modules\LMS\Models\LmsCompetencyFramework;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kerangka kompetensi & penetapan status siswa (§29–§31, §48, §54).
 */
class LmsCompetencyController extends LmsController
{
    public function __construct(
        private readonly CompetencyService $competencies,
        private readonly CompetencyAssessmentService $assessment,
        private readonly CourseAccessService $access,
    ) {}

    /** Kerangka & kompetensi (admin/guru). */
    public function frameworks(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $actor->can('lms.competency.manage') || $actor->can('lms.course.view') || $actor->isTeacher(),
            'Anda tidak berhak melihat kerangka kompetensi.',
        );

        return $this->successResponse($this->competencies->frameworks(), 'Kerangka kompetensi berhasil diambil');
    }

    public function storeFramework(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.competency.manage'), 'Anda tidak berhak membuat kerangka kompetensi.');

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->createdResponse(
            $this->competencies->frameworkPayload($this->competencies->createFramework($data, $actor)),
            'Kerangka kompetensi dibuat',
        );
    }

    public function storeCompetency(Request $request, LmsCompetencyFramework $framework): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.competency.manage'), 'Anda tidak berhak menambah kompetensi.');

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:180'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_competencies,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->createdResponse(
            $this->competencies->competencyPayload($this->competencies->createCompetency($framework, $data, $actor)),
            'Kompetensi dibuat',
        );
    }

    public function updateCompetency(Request $request, LmsCompetency $competency): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.competency.manage'), 'Anda tidak berhak mengubah kompetensi.');

        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'max:180'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:lms_competencies,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,ARCHIVED'],
        ]);

        return $this->successResponse(
            $this->competencies->competencyPayload($this->competencies->updateCompetency($competency, $data, $actor)),
            'Kompetensi diperbarui',
        );
    }

    /** Kompetensi course beserta pemetaan aktivitasnya (§29). */
    public function courseCompetencies(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.course.view') || $actor->isStudent(),
            'Anda tidak berhak melihat kompetensi course ini.',
        );

        return $this->successResponse([
            'competencies' => $this->competencies->forCourse($course),
            'progress' => $this->assessment->courseProgress($course),
        ], 'Kompetensi course berhasil diambil');
    }

    public function mapCourse(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.competency.manage'),
            'Anda tidak berhak memetakan kompetensi course ini.',
        );

        $data = $this->validatePayload($request, [
            'competency_ids' => ['required', 'array'],
            'competency_ids.*' => ['integer'],
        ]);

        return $this->successResponse(
            $this->competencies->mapToCourse($course, $data['competency_ids'], $actor),
            'Pemetaan kompetensi course tersimpan',
        );
    }

    public function mapActivity(Request $request, LmsActivity $activity): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $activity->course()->firstOrFail();
        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.competency.manage'),
            'Anda tidak berhak memetakan kompetensi aktivitas ini.',
        );

        $data = $this->validatePayload($request, [
            'competency_ids' => ['required', 'array'],
            'competency_ids.*' => ['integer'],
        ]);

        return $this->successResponse(
            $this->competencies->mapToActivity($activity, $data['competency_ids'], $actor),
            'Pemetaan kompetensi aktivitas tersimpan',
        );
    }

    /** Tetapkan status kompetensi siswa + bukti (§30–§31). */
    public function assess(Request $request, LmsCompetency $competency, int $student): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $this->validatePayload($request, [
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'status' => ['required', 'string', 'in:NOT_STARTED,DEVELOPING,COMPETENT,MASTERED'],
            'note' => ['nullable', 'string', 'max:255'],
            'score' => ['nullable', 'numeric', 'min:0'],
            'evidence' => ['nullable', 'array'],
            'evidence.*.evidence_type' => ['nullable', 'string', 'in:ACTIVITY,ASSESSMENT,RUBRIC,MANUAL,EXTERNAL'],
            'evidence.*.activity_id' => ['nullable', 'integer', 'exists:lms_activities,id'],
            'evidence.*.title' => ['nullable', 'string', 'max:180'],
            'evidence.*.score' => ['nullable', 'numeric'],
            'evidence.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $course = isset($data['course_id']) ? LmsCourse::query()->find((int) $data['course_id']) : null;
        $this->authorizeLms($actor->can('lms.competency.assess'), 'Anda tidak berhak menilai kompetensi.');

        $record = $this->assessment->assess(
            $competency,
            $student,
            CompetencyStatus::from($data['status']),
            $actor,
            $course,
            $data,
        );

        return $this->successResponse([
            'competency_id' => (int) $record->competency_id,
            'user_id' => (int) $record->user_id,
            'status' => $record->status->value,
            'status_label' => $record->status->label(),
            'note' => $record->note,
            'assessed_at' => $record->assessed_at?->toIso8601String(),
            'course' => $course === null ? null : $this->assessment->forStudent($course, $student),
        ], 'Status kompetensi tersimpan beserta buktinya');
    }

    /** Catat bukti tambahan dari aktivitas LMS (§31). */
    public function evidence(Request $request, LmsCompetency $competency, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.competency.assess'), 'Anda tidak berhak mencatat bukti kompetensi.');

        $data = $this->validatePayload($request, [
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'activity_id' => ['nullable', 'integer', 'exists:lms_activities,id'],
            'evidence_type' => ['nullable', 'string', 'in:ACTIVITY,ASSESSMENT,RUBRIC,MANUAL,EXTERNAL'],
            'title' => ['nullable', 'string', 'max:180'],
            'score' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $course = isset($data['course_id']) ? LmsCourse::query()->find((int) $data['course_id']) : null;
        $record = $this->assessment->ensure($competency, $student, $course);

        $evidence = isset($data['activity_id']) && (int) $data['activity_id'] > 0
            ? $this->assessment->recordActivityEvidence($record, LmsActivity::query()->findOrFail((int) $data['activity_id']), $actor, $data['note'] ?? null)
            : $this->assessment->recordEvidence(
                $record,
                CompetencyEvidenceType::tryFrom((string) ($data['evidence_type'] ?? 'MANUAL')) ?? CompetencyEvidenceType::MANUAL,
                $actor,
                $data + ['course_id' => $course?->id],
            );

        return $this->createdResponse([
            'id' => (int) $evidence->id,
            'evidence_type' => $evidence->evidence_type->value,
            'evidence_type_label' => $evidence->evidence_type->label(),
            'title' => $evidence->title,
            'score' => $evidence->score === null ? null : (float) $evidence->score,
            'recorded_at' => $evidence->recorded_at?->toIso8601String(),
        ], 'Bukti kompetensi tercatat');
    }

    /** Kompetensi seorang siswa pada sebuah course. */
    public function studentCompetencies(Request $request, LmsCourse $course, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $student === $actor->id
                || $this->access->canGrade($course, $actor)
                || ($actor->isParent() && $this->access->parentWatchesCourse($course, $actor)),
            'Anda tidak berhak melihat kompetensi siswa ini.',
        );

        return $this->successResponse(
            $this->assessment->forStudent($course, $student),
            'Kompetensi siswa berhasil diambil',
        );
    }
}
