<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\AssessmentResultService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\GradeCorrectionService;
use App\Modules\LMS\Application\Services\RubricAssessmentService;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penilaian per grade item (§17, §19–§21, §34, §54).
 *
 * Semua penulisan melewati `AssessmentResultService` sehingga nilai punya satu
 * jalur dan satu riwayat.
 */
class LmsAssessmentResultController extends LmsController
{
    public function __construct(
        private readonly AssessmentResultService $results,
        private readonly GradeCorrectionService $corrections,
        private readonly RubricAssessmentService $rubrics,
        private readonly CourseAccessService $access,
        private readonly GradebookService $gradebooks,
    ) {}

    /** Daftar nilai satu grade item (§34 kolom). */
    public function index(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeItem($request, $item, readOnly: true);

        $results = $item->results()->get()->keyBy('user_id');

        $students = $this->students($item)->map(fn (array $student) => [
            'user_id' => $student['user_id'],
            'name' => $student['name'],
            'student_id' => $student['student_id'],
            'result' => ($result = $results->get($student['user_id'])) === null ? null : [
                'id' => (int) $result->id,
                'raw_score' => $result->raw_score === null ? null : (float) $result->raw_score,
                'final_score' => $result->final_score === null ? null : (float) $result->final_score,
                'maximum_score' => (float) $result->maximum_score,
                'normalized' => $result->normalized(),
                'status' => $result->status->value,
                'status_label' => $result->status->label(),
                'status_tone' => $result->status->tone(),
                'feedback' => $result->feedback,
                'feedback_meta' => $result->feedback_meta,
                'is_exempt' => $result->isExempt(),
                'exemption_reason' => $result->exemption_reason,
                'graded_at' => $result->graded_at?->toIso8601String(),
                'released_at' => $result->released_at?->toIso8601String(),
                'rubric' => $this->rubrics->forStudent($item, (int) $student['user_id']),
            ],
        ])->all();

        return $this->successResponse([
            'item' => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'maximum_grade' => (float) $item->maximum_grade,
                'passing_grade' => $item->passingGrade(),
                'weight' => (float) $item->weight,
                'source' => $item->source->value,
                'activity_id' => $item->activity_id === null ? null : (int) $item->activity_id,
                'rubric_id' => $item->rubric_id === null ? null : (int) $item->rubric_id,
            ],
            'students' => $students,
            'statistics' => app(\App\Modules\LMS\Application\Services\GradeStatisticsService::class)->itemStatistics($item),
            'is_frozen' => $this->gradebooks->isFrozen($item->gradebook()->firstOrFail()),
        ], 'Nilai komponen berhasil diambil');
    }

    /** Simpan nilai satu siswa (§17 tahap Grade → Feedback → Save). */
    public function update(Request $request, LmsGradeItem $item, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeItem($request, $item);

        $data = $this->validatePayload($request, [
            'score' => ['nullable', 'numeric'],
            'final_score' => ['nullable', 'numeric'],
            'raw_score' => ['nullable', 'numeric'],
            'maximum_score' => ['nullable', 'numeric', 'gt:0'],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'feedback_private' => ['nullable', 'string', 'max:5000'],
            'feedback_meta' => ['nullable', 'array'],
            'feedback_meta.what_went_well' => ['nullable', 'string', 'max:2000'],
            'feedback_meta.what_to_improve' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:PENDING,GRADED,RETURNED'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->results->saveManual($item, $student, $data, $actor);

        return $this->successResponse($this->payload($result), 'Nilai berhasil disimpan');
    }

    /** Nilai massal satu kolom (§34 inline editing). */
    public function bulk(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeItem($request, $item);

        $data = $this->validatePayload($request, [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.user_id' => ['required', 'integer'],
            'entries.*.score' => ['nullable', 'numeric'],
            'entries.*.feedback' => ['nullable', 'string', 'max:5000'],
            'entries.*.status' => ['nullable', 'string', 'in:PENDING,GRADED,RETURNED'],
        ]);

        $entries = [];

        foreach ($data['entries'] as $entry) {
            $entries[(int) $entry['user_id']] = [
                'final_score' => $entry['score'] ?? null,
                'feedback' => $entry['feedback'] ?? null,
                'status' => $entry['status'] ?? null,
            ];
        }

        $saved = $this->results->bulkSave($item, $entries, $actor);

        return $this->successResponse(
            array_map(fn (LmsAssessmentResult $result) => $this->payload($result), $saved),
            'Nilai massal berhasil disimpan',
        );
    }

    /** Bebaskan siswa dari satu penilaian (§10). */
    public function exempt(Request $request, LmsGradeItem $item, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeItem($request, $item);

        $data = $this->validatePayload($request, [
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->results->exempt($item, $student, $data['reason'], $actor);

        return $this->successResponse($this->payload($result), 'Siswa dibebaskan dari penilaian ini');
    }

    public function unexempt(Request $request, LmsGradeItem $item, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeItem($request, $item);

        $result = $item->resultFor($student);

        if ($result === null) {
            return $this->errorResponse('Nilai tidak ditemukan untuk siswa ini.', 404);
        }

        return $this->successResponse(
            $this->payload($this->results->unexempt($result, $actor)),
            'Pembebasan dibatalkan',
        );
    }

    /** Kembalikan nilai + umpan balik ke siswa (§17, §21). */
    public function return(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeItem($request, $item);

        $data = $this->validatePayload($request, [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        $count = $this->results->returnToStudents($item, array_map('intval', $data['user_ids']), $actor);

        return $this->successResponse(['returned' => $count], 'Nilai dikembalikan ke siswa');
    }

    /** Koreksi nilai dengan jejak audit (§19). */
    public function correct(Request $request, LmsAssessmentResult $result): JsonResponse
    {
        $actor = $this->actor($request);
        $item = $result->item()->firstOrFail();
        $this->authorizeItem($request, $item);

        $data = $this->validatePayload($request, [
            'score' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $correction = $this->corrections->correct($result, (float) $data['score'], $data['reason'], $actor);

        return $this->successResponse([
            'result' => $this->payload($correction['result']),
            'previous' => $correction['previous'],
            'corrected' => $correction['corrected'],
            'history' => $this->results->historyFor($correction['result'])
                ->map(fn ($row) => [
                    'action' => $row->action->value,
                    'action_label' => $row->action->label(),
                    'previous_value' => $row->previous_value,
                    'new_value' => $row->new_value,
                    'reason' => $row->reason,
                    'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                    'created_at' => $row->created_at?->toIso8601String(),
                ])->all(),
        ], 'Nilai dikoreksi dan riwayat tersimpan');
    }

    /** Riwayat nilai satu hasil (§19, §53). */
    public function history(Request $request, LmsAssessmentResult $result): JsonResponse
    {
        $this->actor($request);
        $this->authorizeItem($request, $result->item()->firstOrFail(), readOnly: true);

        return $this->successResponse(
            $this->results->historyFor($result)->map(fn ($row) => [
                'action' => $row->action->value,
                'action_label' => $row->action->label(),
                'source' => $row->source,
                'previous_value' => $row->previous_value,
                'new_value' => $row->new_value,
                'reason' => $row->reason,
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
            'Riwayat nilai berhasil diambil',
        );
    }

    /** @return array<string, mixed> */
    private function payload(LmsAssessmentResult $result): array
    {
        return [
            'id' => (int) $result->id,
            'user_id' => (int) $result->user_id,
            'grade_item_id' => (int) $result->grade_item_id,
            'raw_score' => $result->raw_score === null ? null : (float) $result->raw_score,
            'final_score' => $result->final_score === null ? null : (float) $result->final_score,
            'maximum_score' => (float) $result->maximum_score,
            'normalized' => $result->normalized(),
            'percent' => $result->percent(),
            'status' => $result->status->value,
            'status_label' => $result->status->label(),
            'status_tone' => $result->status->tone(),
            'is_exempt' => $result->isExempt(),
            'exemption_reason' => $result->exemption_reason,
            'feedback' => $result->feedback,
            'feedback_private' => $result->feedback_private,
            'feedback_meta' => $result->feedback_meta,
            'graded_at' => $result->graded_at?->toIso8601String(),
            'released_at' => $result->released_at?->toIso8601String(),
        ];
    }

    private function authorizeItem(Request $request, LmsGradeItem $item, bool $readOnly = false): void
    {
        $actor = $this->actor($request);
        $course = $item->gradebook()->firstOrFail()->course()->firstOrFail();

        // Kepemilikan course (atau admin) selalu wajib: permission peran saja
        // tidak boleh membuka nilai course guru lain (§53).
        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course, readOnly: $readOnly),
            'Anda tidak berhak menilai komponen ini.',
        );
    }

    /**
     * Siswa terdaftar pada course item (§34) — selalu dari enrollment, bukan
     * dari daftar terpisah, supaya tidak ada siswa "hantu" di gradebook.
     *
     * @return list<array{user_id: int, name: string|null, student_id: int|null}>
     */
    private function students(LmsGradeItem $item): array
    {
        $courseId = $item->gradebook()->firstOrFail()->course_id;
        $provider = app(\App\Modules\LMS\Domain\Contracts\UserProviderInterface::class);

        return LmsEnrollment::query()
            ->where('course_id', $courseId)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->whereNotIn('status', ['CANCELLED'])
            ->orderBy('user_id')
            ->get()
            ->map(fn (LmsEnrollment $enrollment) => [
                'user_id' => (int) $enrollment->user_id,
                'name' => $provider->find((int) $enrollment->user_id)?->name,
                'student_id' => $enrollment->student_id === null ? null : (int) $enrollment->student_id,
            ])
            ->all();
    }
}
