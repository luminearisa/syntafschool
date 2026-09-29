<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\RubricAssessmentService;
use App\Modules\LMS\Application\Services\RubricService;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsRubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rubric penilaian (§22–§25, §58).
 *
 * Guru dapat memakai rubric yang sudah ada atau membuat baru; template
 * institusi (`course_id` null) tersedia untuk semua course.
 */
class LmsRubricController extends LmsController
{
    public function __construct(
        private readonly RubricService $rubrics,
        private readonly RubricAssessmentService $assessments,
        private readonly CourseAccessService $access,
    ) {}

    /** Daftar rubric yang dapat dipakai course ini (§25). */
    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);

        $this->authorizeLms(
            $this->access->canEditContent($course, $actor) || $actor->can('lms.rubric.create') || $actor->can('lms.course.view'),
            'Anda tidak berhak melihat rubric course ini.',
        );

        return $this->successResponse([
            'rubrics' => $this->rubrics->availableFor($course),
            'templates' => $this->rubrics->availableFor(null, true),
        ], 'Daftar rubric berhasil diambil');
    }

    /** Semua template institusi (§25). */
    public function templates(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.rubric.create') || $actor->can('lms.course.view') || $actor->isTeacher(), 'Anda tidak berhak melihat template rubric.');

        return $this->successResponse($this->rubrics->availableFor(null), 'Template rubric berhasil diambil');
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.rubric.create') || $actor->isTeacher(), 'Anda tidak berhak membuat rubric.');

        $data = $this->validatePayload($request, $this->rules());
        $rubric = $this->rubrics->create($data + ['owner_id' => $actor->id], $actor);

        return $this->createdResponse($rubric->toStructure(), 'Rubric dibuat');
    }

    public function update(Request $request, LmsRubric $rubric): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRubric($rubric, $actor);

        $updated = $this->rubrics->update($rubric, $this->validatePayload($request, $this->rules(partial: true)), $actor);

        return $this->successResponse($updated->toStructure(), 'Rubric diperbarui');
    }

    public function archive(Request $request, LmsRubric $rubric): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRubric($rubric, $actor);

        return $this->successResponse(
            $this->rubrics->archive($rubric, $actor)->toStructure(),
            'Rubric diarsipkan',
        );
    }

    public function destroy(Request $request, LmsRubric $rubric): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRubric($rubric, $actor);

        $this->rubrics->delete($rubric, $actor);

        return $this->successResponse(null, 'Rubric dihapus');
    }

    /** Salin rubric menjadi rubric baru (§25). */
    public function duplicate(Request $request, LmsRubric $rubric): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.rubric.create') || $actor->isTeacher(), 'Anda tidak berhak menyalin rubric.');

        $data = $this->validatePayload($request, [
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'name' => ['nullable', 'string', 'max:180'],
        ]);

        $copy = $this->rubrics->duplicate($rubric, $actor, $data['course_id'] ?? null, $data['name'] ?? null);

        return $this->createdResponse($copy->toStructure(), 'Rubric disalin');
    }

    /** Pasang/lepas rubric pada sebuah grade item (§24). */
    public function attach(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $item->gradebook()->firstOrFail()->course()->firstOrFail();
        $this->authorizeLms($this->access->canGrade($course, $actor), 'Anda tidak berhak mengubah rubric komponen ini.');

        $data = $this->validatePayload($request, [
            'rubric_id' => ['nullable', 'integer', 'exists:lms_rubrics,id'],
        ]);

        $rubric = $data['rubric_id'] === null ? null : LmsRubric::query()->findOrFail((int) $data['rubric_id']);

        return $this->successResponse(
            ['rubric' => $this->rubrics->attachToGradeItem($item, $rubric, $actor)->rubric_id],
            $rubric === null ? 'Rubric dilepas dari komponen nilai' : 'Rubric dipasang pada komponen nilai',
        );
    }

    /** Penilaian rubric seorang siswa (§58 guru). */
    public function studentAssessment(Request $request, LmsGradeItem $item, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $item->gradebook()->firstOrFail()->course()->firstOrFail();
        $this->authorizeLms($this->access->canGrade($course, $actor), 'Anda tidak berhak menilai komponen ini.');

        return $this->successResponse([
            'rubric' => $item->rubric_id === null ? null : LmsRubric::query()->find((int) $item->rubric_id)?->toStructure(),
            'assessment' => $this->assessments->forStudent($item, $student),
        ], 'Penilaian rubric berhasil diambil');
    }

    /**
     * Simpan penilaian rubric: pilih level per kriteria → sistem menghitung
     * total → nilai masuk gradebook (§24, §58, §61).
     */
    public function assess(Request $request, LmsGradeItem $item, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $item->gradebook()->firstOrFail()->course()->firstOrFail();
        $this->authorizeLms($this->access->canGrade($course, $actor) || $actor->can('lms.rubric.assess'), 'Anda tidak berhak menilai komponen ini.');

        $data = $this->validatePayload($request, [
            'rubric_id' => ['required', 'integer', 'exists:lms_rubrics,id'],
            'selections' => ['required', 'array', 'min:1'],
            'selections.*.level_id' => ['nullable', 'integer', 'exists:lms_rubric_levels,id'],
            'selections.*.score' => ['nullable', 'numeric', 'min:0'],
            'selections.*.comment' => ['nullable', 'string', 'max:255'],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'release' => ['nullable', 'boolean'],
        ]);

        $rubric = LmsRubric::query()->findOrFail((int) $data['rubric_id']);
        $assessment = $this->assessments->start($rubric, $student, $item, null, $actor);

        $result = $this->assessments->assess(
            $assessment,
            $data['selections'],
            $actor,
            (bool) ($data['release'] ?? false),
            $data['feedback'] ?? null,
        );

        return $this->successResponse([
            'breakdown' => $result['breakdown'],
            'total' => $result['total'],
            'maximum' => $result['maximum'],
            'normalized' => $result['normalized'],
            'grade' => $result['grade_result'] === null ? null : [
                'final_score' => $result['grade_result']->final_score === null ? null : (float) $result['grade_result']->final_score,
                'status' => $result['grade_result']->status->value,
            ],
            'assessment' => $this->assessments->forStudent($item, $student),
        ], 'Penilaian rubric tersimpan dan nilai dikirim ke gradebook');
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'name' => [$required, 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'grading_method' => ['nullable', 'string', 'in:POINTS,WEIGHTED,PERCENTAGE'],
            'maximum_score' => ['nullable', 'numeric', 'gt:0'],
            'status' => ['nullable', 'string', 'in:DRAFT,ACTIVE,ARCHIVED'],
            'is_template' => ['nullable', 'boolean'],
            'is_shared' => ['nullable', 'boolean'],
            'criteria' => [$required, 'array', 'min:1'],
            'criteria.*.name' => ['required', 'string', 'max:180'],
            'criteria.*.description' => ['nullable', 'string'],
            'criteria.*.weight' => ['nullable', 'numeric', 'min:0'],
            'criteria.*.maximum_score' => ['nullable', 'numeric', 'min:0'],
            'criteria.*.levels' => ['required', 'array', 'min:1'],
            'criteria.*.levels.*.name' => ['required', 'string', 'max:120'],
            'criteria.*.levels.*.description' => ['nullable', 'string'],
            'criteria.*.levels.*.score' => ['required', 'numeric', 'min:0'],
        ];
    }

    private function authorizeRubric(LmsRubric $rubric, LmsActor $actor): void
    {
        $isOwner = (int) $rubric->owner_id === $actor->id || (int) $rubric->created_by === $actor->id;
        $canManage = $actor->can('lms.rubric.manage') || $actor->can('lms.rubric.create');

        $this->authorizeLms($canManage && ($isOwner || $rubric->is_template || $actor->isAdmin()), 'Anda tidak berhak mengubah rubric ini.');
    }
}
