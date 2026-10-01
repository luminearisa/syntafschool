<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Application\Services\ActivityAchievementService;
use App\Modules\Activity\Application\Services\ActivityAnalyticsService;
use App\Modules\Activity\Application\Services\ActivityCertificateService;
use App\Modules\Activity\Application\Services\ActivityPortfolioService;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityCertificate;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Prestasi, sertifikat, portofolio, transkrip, dan laporan (\§42\–45, \§52\–54, \§76, \§103\–104).
 */
class ActivityRecognitionController extends BaseApiController
{
    public function __construct(
        private readonly ActivityAchievementService $achievements,
        private readonly ActivityCertificateService $certificates,
        private readonly ActivityPortfolioService $portfolios,
        private readonly ActivityAnalyticsService $analytics,
        private readonly ActivityAccessService $access,
    ) {}

    // ----------------------------------------------------------- prestasi

    public function achievements(Request $request): JsonResponse
    {
        return $this->successResponse($this->achievements->paginate($request->all(), $request->user()));
    }

    public function storeAchievement(Request $request): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'team_id' => ['nullable', 'integer', 'exists:activity_competition_teams,id'],
            'program_id' => ['nullable', 'integer', 'exists:activity_programs,id'],
            'competition_id' => ['nullable', 'integer', 'exists:activity_competitions,id'],
            'ownership' => ['nullable', Rule::in(['INDIVIDUAL', 'TEAM'])],
            'type' => ['nullable', 'string', 'max:32'],
            'level' => ['nullable', Rule::in(['INTERNAL', 'SCHOOL', 'DISTRICT', 'CITY', 'PROVINCE', 'NATIONAL', 'INTERNATIONAL'])],
            'rank' => ['nullable', 'string', 'max:32'],
            'awarded_date' => ['nullable', 'date'],
            'members' => ['nullable', 'array'],
        ]);

        return $this->createdResponse($this->achievements->create($request->all(), $request->user()), 'Prestasi dicatat.');
    }

    public function verifyAchievement(ActivityAchievement $achievement, Request $request): JsonResponse
    {
        $request->validate([
            'decision' => ['required', Rule::in(['VERIFIED', 'REJECTED', 'REVOKED'])],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->successResponse(
            $this->achievements->verify($achievement, $request->user(), (string) $request->input('decision'), $request->input('notes')),
            'Status verifikasi prestasi diperbarui.',
        );
    }

    // --------------------------------------------------------- sertifikat

    public function certificates(Request $request): JsonResponse
    {
        return $this->successResponse($this->certificates->paginate($request->all(), $request->user()));
    }

    public function issueCertificate(ActivityCompletion $completion, Request $request): JsonResponse
    {
        $request->validate(['force' => ['nullable', 'boolean']]);

        return $this->createdResponse(
            $this->certificates->issueForCompletion($completion, $request->user(), $request->all()),
            'Sertifikat diterbitkan.',
        );
    }

    public function issueAchievementCertificate(ActivityAchievement $achievement, Request $request): JsonResponse
    {
        return $this->createdResponse(
            $this->certificates->issueForAchievement($achievement, $request->user(), $request->all()),
            'Sertifikat prestasi diterbitkan.',
        );
    }

    public function revokeCertificate(ActivityCertificate $certificate, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse(
            $this->certificates->revoke($certificate, $request->user(), (string) $request->input('reason')),
            'Sertifikat dicabut.',
        );
    }

    /** Verifikasi publik \— tanpa autentikasi, tanpa data pribadi (\§53). */
    public function verifyCertificate(Request $request): JsonResponse
    {
        $request->validate(['number' => ['required', 'string', 'max:64']]);

        return $this->successResponse(
            $this->certificates->verify((string) $request->input('number'), $request->ip(), $request->userAgent()),
            'Hasil verifikasi sertifikat.',
        );
    }

    // ---------------------------------------------------------- portofolio

    public function portfolio(int $studentId, Request $request): JsonResponse
    {
        if (! $this->access->canViewStudent($request->user(), $studentId)) {
            return $this->errorResponse('Anda tidak berwenang melihat portofolio siswa ini.', 403);
        }

        return $this->successResponse($this->portfolios->build($studentId, $request->integer('period_id') ?: null));
    }

    public function storePortfolio(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'period_id' => ['nullable', 'integer', 'exists:activity_periods,id'],
        ]);

        return $this->createdResponse(
            $this->portfolios->store((int) $request->input('student_id'), $request->integer('period_id') ?: null, $request->user()),
            'Portofolio disimpan.',
        );
    }

    public function sharePortfolio(ActivityPortfolio $portfolio, Request $request): JsonResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->successResponse(
            $this->portfolios->share($portfolio, $request->user(), (bool) $request->boolean('enabled')),
            'Pengaturan berbagi portofolio diperbarui.',
        );
    }

    public function publicPortfolio(string $token): JsonResponse
    {
        $snapshot = $this->portfolios->publicView($token);

        return $snapshot === null
            ? $this->errorResponse('Portofolio tidak ditemukan atau tidak dibagikan.', 404)
            : $this->successResponse($snapshot);
    }

    public function transcript(int $studentId, Request $request): JsonResponse
    {
        if (! $this->access->canViewStudent($request->user(), $studentId)) {
            return $this->errorResponse('Anda tidak berwenang melihat transkrip aktivitas siswa ini.', 403);
        }

        return $this->successResponse($this->portfolios->transcript($studentId, $request->integer('period_id') ?: null));
    }

    // -------------------------------------------------------------- laporan

    public function report(string $type, Request $request): JsonResponse
    {
        if (! $request->user()->can('activity.report.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat laporan aktivitas.', 403);
        }

        return $this->successResponse($this->analytics->report($type, $request->all()));
    }
}
