<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkAuditService;
use App\Modules\Bk\Application\Services\BkDashboardService;
use App\Modules\Bk\Application\Services\BkHealthService;
use App\Modules\Bk\Application\Services\BkSupportProfileService;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\ClosureReason;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Domain\Enums\InterventionStatus;
use App\Modules\Bk\Domain\Enums\InterventionType;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Domain\Enums\ReferralStatus;
use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use App\Modules\Bk\Domain\Enums\Severity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konteks modul BK: siapa saya, apa saja pilihannya, bagaimana dasbornya.
 *
 * Dasbor sengaja memuat **agregat** saja; daftar kasus tetap diambil dari
 * endpoint kasus yang sudah disaring wewenang.
 */
class BkMetaController extends BaseApiController
{
    public function __construct(
        private readonly BkDashboardService $dashboard,
        private readonly BkSupportProfileService $profiles,
        private readonly BkHealthService $health,
        private readonly BkAuditService $audit,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'counselor' => $user->id === null ? null : \App\Modules\Bk\Models\BkCounselor::query()->where('user_id', $user->id)->first(),
        ], 'Konteks modul BK.');
    }

    public function metadata(): JsonResponse
    {
        return $this->successResponse([
            'enums' => [
                'case_status' => CaseStatus::options(),
                'case_type' => CaseType::options(),
                'closure_reason' => ClosureReason::options(),
                'confidentiality_level' => ConfidentialityLevel::options(),
                'follow_up_status' => FollowUpStatus::options(),
                'follow_up_type' => FollowUpType::options(),
                'intervention_status' => InterventionStatus::options(),
                'intervention_type' => InterventionType::options(),
                'priority' => Priority::options(),
                'referral_category' => ReferralCategory::options(),
                'referral_source' => ReferralSource::options(),
                'referral_status' => ReferralStatus::options(),
                'screening_outcome' => ScreeningOutcome::options(),
                'severity' => Severity::options(),
            ],
            'report_types' => \App\Modules\Bk\Application\Services\BkReportService::types(),
        ], 'Metadata modul BK.');
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->successResponse($this->dashboard->widgets($request->user()), 'Dasbor BK.');
    }

    public function todaysWorkload(Request $request): JsonResponse
    {
        return $this->successResponse($this->dashboard->todaysWorkload($request->user()), 'Antrean kerja hari ini.');
    }

    public function agingCases(Request $request): JsonResponse
    {
        return $this->successResponse($this->dashboard->agingCases((int) $request->integer('limit', 10)), 'Kasus terlama.');
    }

    public function supportProfile(Request $request, int $studentId): JsonResponse
    {
        return $this->successResponse($this->profiles->build($request->user(), $studentId), 'Profil dukungan siswa.');
    }

    public function health(): JsonResponse
    {
        return $this->successResponse($this->health->check(), 'Kesehatan modul BK.');
    }

    public function audit(Request $request): JsonResponse
    {
        $filters = $request->only(['action', 'case_id', 'student_id', 'actor_id', 'from', 'to']);

        return $this->successResponse($this->audit->paginate($filters, (int) $request->integer('per_page', 25)), 'Jejak audit BK.');
    }
}
