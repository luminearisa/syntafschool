<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Skills\Services\SkillsIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillsController
{
    public function __construct(private readonly SkillsIntelligenceService $skills) {}

    public function intelligence(Request $request): JsonResponse
    {
        return response()->json([
            'critical' => $this->skills->criticalSkills($request->input('organization_id')),
            'supply_demand' => $this->skills->supplyVsDemand($request->input('organization_id'), $request->input('period')),
            'heatmap' => $this->skills->heatmap($request->input('organization_id'), $request->input('department_id')),
        ]);
    }

    public function gaps(Request $request): JsonResponse
    {
        return $request->filled('employee_id')
            ? response()->json($this->skills->gapForEmployee((int) $request->input('employee_id'),
                (string) $request->input('scope_type', 'POSITION'),
                $request->filled('scope_id') ? (int) $request->input('scope_id') : null))
            : response()->json($this->skills->learningDemand($request->all()));
    }

    public function demand(Request $request): JsonResponse
    {
        return response()->json($this->skills->supplyVsDemand($request->input('organization_id'), $request->input('period')));
    }

    public function forecast(Request $request): JsonResponse
    {
        return response()->json([
            'forecasts' => $this->skills->forecastDemand($request->input('organization_id'), $request->input('period'),
                (array) $request->input('assumptions', [])),
            'learning_demand' => $this->skills->learningDemand($request->all()),
        ]);
    }

    public function heatmap(Request $request): JsonResponse
    {
        return response()->json($this->skills->heatmap($request->input('organization_id'), $request->input('department_id'),
            (int) $request->input('limit', 12)));
    }

    public function critical(Request $request): JsonResponse
    {
        return response()->json($this->skills->criticalSkills($request->input('organization_id')));
    }

    public function evidence(Request $request): JsonResponse
    {
        return response()->json($this->skills->recordEvidence((int) $request->input('employee_id'),
            (string) $request->input('skill_code'), $request->all(), $request->user()));
    }

    public function assess(Request $request): JsonResponse
    {
        return response()->json($this->skills->assess((int) $request->input('employee_id'), (string) $request->input('skill_code'),
            (string) $request->input('level'), (string) $request->input('method', 'MANAGER'),
            $request->filled('assessor_employee_id') ? (int) $request->input('assessor_employee_id') : null, $request->user()));
    }

    public function buildBuyBorrow(Request $request): JsonResponse
    {
        return response()->json($this->skills->buildBuyBorrow((string) $request->input('skill_code'),
            (int) $request->input('gap', 1), (array) $request->input('assumptions', [])));
    }

    public function governance(): JsonResponse
    {
        return response()->json($this->skills->governanceReview());
    }

    public function syncRequirements(Request $request): JsonResponse
    {
        return response()->json($this->skills->syncRequirementsFromPhase2());
    }
}
