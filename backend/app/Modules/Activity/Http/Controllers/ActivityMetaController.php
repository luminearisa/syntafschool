<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Application\Services\ActivityAnalyticsService;
use App\Modules\Activity\Application\Services\ActivityAuditService;
use App\Modules\Activity\Application\Services\ActivityCategoryService;
use App\Modules\Activity\Application\Services\ActivityIntegrationService;
use App\Modules\Activity\Application\Services\ActivityNotificationService;
use App\Modules\Activity\Domain\Enums\MembershipRole;
use App\Modules\Activity\Domain\Enums\ProgramDeliveryType;
use App\Modules\Activity\Domain\Enums\ProgramType;
use App\Modules\Activity\Domain\Enums\RequirementOperator;
use App\Modules\Activity\Domain\Enums\RequirementType;
use App\Modules\Activity\Domain\Enums\SelectionMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konteks modul: siapa saya, apa saja pilihan yang tersedia, dan bagaimana
 * kesehatan modul ini (\§76\–80, \§91).
 */
class ActivityMetaController extends BaseApiController
{
    public function __construct(
        private readonly ActivityCategoryService $categories,
        private readonly ActivityAccessService $access,
        private readonly ActivityAnalyticsService $analytics,
        private readonly ActivityAuditService $audit,
        private readonly ActivityIntegrationService $integrations,
        private readonly ActivityNotificationService $notifications,
    ) {}

    /** Siapa saya di modul ini: siswa? wali? pembina? */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'user_id' => (int) $user->id,
            'student_id' => $this->access->studentIdForUser($user),
            'employee_id' => $this->access->employeeIdForUser($user),
            'child_student_ids' => $this->access->childStudentIds($user),
            'coached_program_ids' => $this->access->coachedProgramIds($user),
            'pending_registration_decisions' => $this->access->pendingDecisionCount($user),
            'permissions' => $user->getAllPermissions()->pluck('name')->filter(fn ($name) => str_starts_with((string) $name, 'activity'))->values()->all(),
        ]);
    }

    /** Pilihan & enum untuk layar (dipakai builder dan penyaring). */
    public function metadata(): JsonResponse
    {
        return $this->successResponse([
            'catalog' => $this->categories->metadata(),
            'program_types' => $this->options(ProgramType::cases()),
            'delivery_types' => $this->options(ProgramDeliveryType::cases()),
            'selection_methods' => $this->options(SelectionMethod::cases()),
            'requirement_types' => $this->options(RequirementType::cases()),
            'requirement_operators' => $this->options(RequirementOperator::cases()),
            'membership_roles' => $this->options(MembershipRole::cases()),
            'notification_events' => $this->notifications->events(),
            'integrations' => $this->integrations->health(),
            'report_types' => [
                'participation', 'membership', 'attendance', 'achievement', 'competition',
                'certificate', 'capacity', 'waitlist', 'dropout', 'completion', 'coach_performance',
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $studentId = $this->access->studentIdForUser($user);

        $payload = [
            'admin' => $user->can('activity.analytics.view') ? $this->analytics->adminDashboard($request->only(['period_id'])) : null,
            'coach' => $this->analytics->coachDashboard($user),
        ];

        if ($studentId !== null) {
            $payload['student'] = $this->analytics->studentDashboard($studentId);
        }

        if ($this->access->childStudentIds($user) !== []) {
            $payload['parent'] = $this->analytics->parentDashboard($user);
        }

        return $this->successResponse($payload);
    }

    public function health(Request $request): JsonResponse
    {
        if (! $request->user()->can('activity.settings.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat kesehatan modul.', 403);
        }

        return $this->successResponse([
            'data' => $this->analytics->health(),
            'integrations' => $this->integrations->health(),
            'recent_integration_calls' => $this->integrations->recent(null, 15),
            'audit' => $this->audit->summary(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        if (! $request->user()->can('activity.settings.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat pengaturan modul.', 403);
        }

        return $this->successResponse([
            'config' => [
                'limits' => config('activity.limits'),
                'conflict' => config('activity.conflict'),
                'attendance' => config('activity.attendance'),
                'consent' => config('activity.consent'),
                'finance' => config('activity.finance'),
                'facility' => config('activity.facility'),
                'certificates' => config('activity.certificates'),
                'sessions' => config('activity.sessions'),
            ],
            'notification_events' => $this->notifications->events(),
            'integration_health' => $this->integrations->health(),
        ]);
    }

    /** @param list<object> $cases */
    protected function options(array $cases): array
    {
        return array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);
    }
}
