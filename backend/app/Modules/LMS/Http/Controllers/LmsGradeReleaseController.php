<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Application\Services\GradeStatisticsService;
use App\Modules\LMS\Application\Services\GradeSyncService;
use App\Modules\LMS\Application\Services\AcademicIntegrationService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradeRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rilis & penguncian nilai, serta sinkronisasi ke SIAKAD (§16–§18, §41–§42, §54).
 */
class LmsGradeReleaseController extends LmsController
{
    public function __construct(
        private readonly GradeReleaseService $releases,
        private readonly GradeSyncService $sync,
        private readonly AcademicIntegrationService $integration,
        private readonly GradebookService $gradebooks,
        private readonly GradeStatisticsService $statistics,
        private readonly CourseAccessService $access,
    ) {}

    /** Rilis nilai: satu siswa, sebagian, atau semua (§18). */
    public function release(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $course);

        $data = $this->validatePayload($request, [
            'scope' => ['required', 'string', 'in:individual,selected,all'],
            'user_ids' => ['required_if:scope,selected', 'array'],
            'user_ids.*' => ['integer'],
            'user_id' => ['required_if:scope,individual', 'integer'],
        ]);

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        $payload = match ($data['scope']) {
            'individual' => [
                'released' => [$this->releases->releaseStudent($gradebook, (int) $data['user_id'], $actor)],
                'skipped' => [],
            ],
            'selected' => $this->releases->releaseSelected($gradebook, array_map('intval', $data['user_ids']), $actor),
            default => $this->releases->releaseAll($gradebook, $this->statistics->studentIds($course), $actor),
        };

        return $this->successResponse($payload, 'Nilai berhasil dirilis');
    }

    /** Daftar status rilis gradebook. */
    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $course);

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        return $this->successResponse([
            'releases' => $this->releases->releases($gradebook),
            'sync' => $this->sync->status($gradebook),
            'integration' => $this->integration->status(),
        ], 'Status rilis nilai berhasil diambil');
    }

    /** Batalkan rilis (§18). */
    public function revoke(Request $request, LmsGradeRelease $release): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $release->gradebook()->firstOrFail()->course()->firstOrFail());

        $data = $this->validatePayload($request, [
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->successResponse(
            $this->releases->releasePayload($this->releases->revoke($release, $actor, $data['reason'] ?? null)),
            'Rilis nilai dibatalkan',
        );
    }

    /** Kunci nilai yang sudah dirilis (§42). */
    public function lock(Request $request, LmsGradeRelease $release): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $release->gradebook()->firstOrFail()->course()->firstOrFail());

        $data = $this->validatePayload($request, ['reason' => ['nullable', 'string', 'max:255']]);

        return $this->successResponse(
            $this->releases->releasePayload($this->releases->lock($release, $actor, $data['reason'] ?? null)),
            'Nilai dikunci',
        );
    }

    /** Buka kunci nilai — memerlukan izin koreksi + alasan (§42). */
    public function unlock(Request $request, LmsGradeRelease $release): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $release->gradebook()->firstOrFail()->course()->firstOrFail());

        $data = $this->validatePayload($request, [
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->successResponse(
            $this->releases->releasePayload($this->releases->unlock($release, $actor, $data['reason'])),
            'Kunci nilai dibuka dengan alasan tercatat',
        );
    }

    /** Sinkronkan nilai akhir ke SIAKAD (§41). */
    public function sync(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $course);
        $this->authorizeLms($actor->can('lms.grade.sync'), 'Anda tidak memiliki izin sinkronisasi nilai.');

        $data = $this->validatePayload($request, [
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
        ]);

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);
        $userIds = isset($data['user_ids']) && $data['user_ids'] !== []
            ? array_map('intval', $data['user_ids'])
            : $this->statistics->studentIds($course);

        return $this->successResponse(
            $this->sync->syncGradebook($gradebook, $userIds, $actor),
            'Sinkronisasi nilai selesai diproses',
        );
    }

    /** Status integrasi akademik (§40, §63). */
    public function integration(Request $request): JsonResponse
    {
        $this->actor($request);

        return $this->successResponse($this->integration->status(), 'Status integrasi akademik');
    }

    /** Pratinjau payload integrasi untuk satu siswa (§40). */
    public function payload(Request $request, LmsCourse $course, int $student): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRelease($request, $course);

        $gradebook = $this->gradebooks->ensureForCourse($course, $actor->id);

        return $this->successResponse([
            'payload' => $this->integration->payload($gradebook, $student),
            'provider' => [
                'key' => $this->integration->provider()->key(),
                'label' => $this->integration->provider()->label(),
                'available' => $this->integration->provider()->isAvailable(),
            ],
        ], 'Payload integrasi nilai');
    }

    private function authorizeRelease(Request $request, LmsCourse $course): void
    {
        $actor = $this->actor($request);

        $this->authorizeLms(
            $this->canAccessCourseGrades($actor, $course),
            'Anda tidak berhak merilis nilai course ini.',
        );
    }
}
