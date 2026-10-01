<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Application\Services\ActivityAchievementService;
use App\Modules\Activity\Application\Services\ActivityAnalyticsService;
use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityCertificateService;
use App\Modules\Activity\Application\Services\ActivityConsentService;
use App\Modules\Activity\Domain\Contracts\CounselingProviderInterface;
use App\Modules\Activity\Models\ActivityConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Portal siswa & orang tua (\§9\–10, \§61, \§94) dan jembatan BK (\§60).
 */
class ActivityPortalController extends BaseApiController
{
    public function __construct(
        private readonly ActivityAccessService $access,
        private readonly ActivityAnalyticsService $analytics,
        private readonly ActivityConsentService $consents,
        private readonly ActivityAttendanceService $attendance,
        private readonly ActivityAchievementService $achievements,
        private readonly ActivityCertificateService $certificates,
        private readonly CounselingProviderInterface $counseling,
    ) {}

    /** Dasbor orang tua: pilih anak \→ aktivitas, jadwal, kehadiran, konsen (\§80, \§94). */
    public function parentDashboard(Request $request): JsonResponse
    {
        return $this->successResponse($this->analytics->parentDashboard($request->user()));
    }

    public function parentChild(int $studentId, Request $request): JsonResponse
    {
        if (! $this->access->canViewStudent($request->user(), $studentId)) {
            return $this->errorResponse('Anda tidak berwenang melihat data siswa ini.', 403);
        }

        return $this->successResponse([
            'dashboard' => $this->analytics->studentDashboard($studentId),
            'attendance' => [],
            'achievements' => $this->achievements->forStudent($studentId),
            'certificates' => $this->certificates->forStudent($studentId),
            'pending_consents' => $this->consents->pendingForUser($request->user()),
        ]);
    }

    /** Persetujuan yang menunggu keputusan orang tua. */
    public function pendingConsents(Request $request): JsonResponse
    {
        return $this->successResponse($this->consents->pendingForUser($request->user()));
    }

    public function decideConsent(ActivityConsent $consent, Request $request): JsonResponse
    {
        $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'DECLINED', 'REVOKED'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->consents->decide($consent, $request->user(), (string) $request->input('decision'), $request->input('notes')),
            'Persetujuan dicatat.',
        );
    }

    /** Kehadiran saya pada satu program. */
    public function myAttendance(Request $request, int $programId): JsonResponse
    {
        $studentId = $this->access->studentIdForUser($request->user());

        if ($studentId === null) {
            return $this->errorResponse('Akun ini tidak terhubung ke data siswa.', 403);
        }

        return $this->successResponse($this->attendance->studentSummary($studentId, $programId));
    }

    /**
     * Ringkasan aktivitas untuk BK (\§60).
     *
     * Hanya sinyal perkembangan \— tidak ada catatan konseling yang melewati
     * endpoint ini, dan setiap permintaannya diperiksa izin khusus.
     */
    public function counselingSummary(int $studentId, Request $request): JsonResponse
    {
        if (! $request->user()->can('activity.counseling.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat ringkasan BK.', 403);
        }

        if (! $this->counseling->canExposeSummary((int) $request->user()->id, $studentId)) {
            return $this->errorResponse('Ringkasan aktivitas siswa ini tidak dapat dibuka untuk Anda.', 403);
        }

        return $this->successResponse([
            'signals' => $this->counseling->developmentSignals($studentId),
            'note' => 'Ringkasan ini berisi capaian aktivitas saja; catatan konseling tidak pernah melewati modul aktivitas.',
        ]);
    }
}
