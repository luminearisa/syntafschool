<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityGradeService;
use App\Modules\LMS\Application\Services\ActivityGradeSyncService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catatan nilai aktivitas (§46–§47, §48, §53).
 *
 * Nilai milik LMS; pengiriman ke SIAKAD selalu eksplisit dan berizin.
 */
class LmsGradeController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly ActivityGradeService $grades,
        private readonly ActivityGradeSyncService $sync,
        private readonly CourseAccessService $access,
    ) {}

    /** Nilai saya pada sebuah course (siswa) — atau anak yang dipantau (wali). */
    public function courseGrades(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $userId = $request->query('user_id') === null ? $actor->id : (int) $request->query('user_id');

        $isSelf = $userId === $actor->id;
        $isStaff = $this->access->canGrade($course, $actor);
        $isParent = $actor->isParent() && $this->access->parentWatchesCourse($course, $actor);

        $this->authorizeLms($isSelf || $isStaff || $isParent, 'Anda tidak berhak melihat nilai ini.');

        return $this->successResponse([
            'summary' => $this->grades->courseSummary((int) $course->id, $userId),
            'read_only' => ! $isSelf,
        ], 'Nilai course berhasil diambil');
    }

    /** Nilai seluruh siswa pada satu aktivitas (guru). */
    public function activityGrades(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse(
            $this->grades->forActivity($model)->map(fn (LmsActivityGrade $grade) => $this->sync->gradePayload($grade) + [
                'user_id' => (int) $grade->user_id,
                'student_id' => $grade->student_id === null ? null : (int) $grade->student_id,
            ])->all(),
            'Daftar nilai aktivitas berhasil diambil',
        );
    }

    /** Simpan/ubah nilai manual (mis. penilaian forum). */
    public function store(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeGrade($request, $model);

        $data = $this->validatePayload($request, [
            'user_id' => ['required', 'integer'],
            'grade' => ['required', 'numeric', 'min:0'],
            'maximum_grade' => ['nullable', 'numeric', 'min:1'],
            'feedback' => ['nullable', 'string'],
        ]);

        $maximum = (float) ($data['maximum_grade'] ?? 100);

        if ((float) $data['grade'] > $maximum) {
            $this->invalid('grade', 'Nilai tidak boleh melebihi nilai maksimum.');
        }

        $grade = $this->grades->record($model, (int) $data['user_id'], [
            'grade' => (float) $data['grade'],
            'maximum_grade' => $maximum,
            'feedback' => $data['feedback'] ?? null,
            'graded_by' => $actor->id,
            'source' => $model->activity_type?->value,
        ]);

        return $this->successResponse($this->sync->gradePayload($grade), 'Nilai berhasil disimpan');
    }

    /** Hapus catatan nilai aktivitas (koreksi). */
    public function destroy(Request $request, LmsActivityGrade $grade): JsonResponse
    {
        $activity = LmsActivity::query()->find($grade->activity_id);
        $this->authorizeGrade($request, $activity);

        $grade->delete();

        return $this->successResponse(null, 'Catatan nilai dihapus');
    }

    /**
     * Kirim nilai ke integration layer SIAKAD — hanya bila diminta eksplisit
     * dan pemanggil memiliki `lms.grade.manage` (§8, §47).
     */
    public function push(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeGrade($request, $model);

        $this->authorizeLms($actor->can('lms.grade.manage') || $actor->can('lms.manage'), 'Anda tidak berhak mengirim nilai ke SIAKAD.');

        $data = $this->validatePayload($request, [
            'user_id' => ['required', 'integer'],
        ]);

        return $this->successResponse(
            $this->sync->push($model, (int) $data['user_id'], $actor),
            'Nilai disiapkan untuk integration layer akademik',
        );
    }

    /** Ringkasan nilai LMS seorang siswa pada satu aktivitas (wali/siswa/guru). */
    public function activitySummary(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        $course = $model->course;

        $userId = (int) $request->query('user_id', $actor->id);

        $isSelf = $userId === $actor->id;
        $isStaff = $this->access->canGrade($course, $actor);
        $isParent = $actor->isParent() && $this->access->parentWatchesCourse($course, $actor);

        $this->authorizeLms($isSelf || $isStaff || $isParent, 'Anda tidak berhak melihat nilai ini.');

        $grade = $this->grades->forActivityUser($model, $userId);

        return $this->successResponse([
            'grade' => $grade === null ? null : $this->sync->gradePayload($grade),
            'read_only' => ! $isStaff,
        ], 'Nilai aktivitas berhasil diambil');
    }
}
