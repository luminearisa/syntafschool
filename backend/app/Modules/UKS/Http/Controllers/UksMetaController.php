<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\LetterService;
use App\Modules\UKS\Application\Services\UksAccessService;
use App\Modules\UKS\Domain\Enums\IncidentStatus;
use App\Modules\UKS\Domain\Enums\LetterType;
use App\Modules\UKS\Domain\Enums\OfficerRole;
use App\Modules\UKS\Domain\Enums\ReferralStatus;
use App\Modules\UKS\Domain\Enums\ScreeningResult;
use App\Modules\UKS\Domain\Enums\ScreeningType;
use App\Modules\UKS\Domain\Enums\Severity;
use App\Modules\UKS\Domain\Enums\StockReason;
use App\Modules\UKS\Domain\Enums\VisitStatus;
use App\Modules\UKS\Domain\Enums\VisitType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UksMetaController extends UksController
{
    public function __construct(private readonly UksAccessService $access)
    {
    }

    /** Daftar nilai untuk isian layar (jenis, status, tingkat). */
    public function metadata(): JsonResponse
    {
        $options = fn (array $cases): array => array_map(
            fn ($case): array => ['value' => $case->value, 'label' => $case->label()],
            $cases,
        );

        return $this->successResponse([
            'visit_types' => $options(VisitType::cases()),
            'severities' => $options(Severity::cases()),
            'visit_statuses' => $options(VisitStatus::cases()),
            'screening_types' => $options(ScreeningType::cases()),
            'screening_results' => $options(ScreeningResult::cases()),
            'incident_statuses' => $options(IncidentStatus::cases()),
            'letter_types' => $options(LetterType::cases()),
            'officer_roles' => $options(OfficerRole::cases()),
            'referral_statuses' => $options(ReferralStatus::cases()),
            'stock_reasons' => $options(StockReason::cases()),
        ], 'Metadata modul UKS.');
    }

    /** Konteks pengguna yang masuk: peran UKS-nya dan apa yang boleh dilakukan. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = $this->access->context($user);
        $officer = $this->access->officer($user);

        return $this->successResponse([
            'is_officer' => $officer !== null,
            'officer_role' => $officer?->role->value,
            'is_student' => $context->isStudent(),
            'is_guardian' => $context->isGuardian(),
            'is_employee' => $context->isEmployee(),
            'can_view_all' => $this->access->canViewAll($user),
            'can_operate' => $this->access->canOperate($user),
            'student_id' => $context->student?->id,
            'guardian_id' => $context->guardian?->id,
        ], 'Konteks UKS.');
    }
}
