<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Talent\Models\HrTalentOpportunity;
use App\Modules\Hrd\Strategic\Talent\Services\CareerIntelligenceService;
use App\Modules\Hrd\Strategic\Talent\Services\TalentIntelligenceService;
use App\Modules\Hrd\Strategic\Talent\Services\TalentMarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TalentController
{
    public function __construct(
        private readonly TalentIntelligenceService $talent,
        private readonly TalentMarketplaceService $marketplace,
        private readonly CareerIntelligenceService $career,
    ) {}

    public function intelligence(Request $request, int $employee): JsonResponse
    {
        return response()->json([
            'portfolio' => $this->talent->growthPortfolio($employee),
            'profile' => $this->talent->profile($employee),
            'segment' => $this->talent->segment($employee),
            'pools' => $this->talent->poolMembership($employee),
        ]);
    }

    public function segments(Request $request): JsonResponse
    {
        return response()->json($this->talent->segments($request->input('organization_id'), (int) $request->input('limit', 200)));
    }

    public function matrix(Request $request): JsonResponse
    {
        return response()->json($this->talent->matrix((string) $request->input('x', 'PERFORMANCE'),
            (string) $request->input('y', 'POTENTIAL'), $request->input('organization_id'), (int) $request->input('limit', 200)));
    }

    public function mobility(Request $request): JsonResponse
    {
        return response()->json($this->talent->mobilityIntelligence($request->input('organization_id'), (int) $request->input('limit', 25)));
    }

    public function marketplace(Request $request): JsonResponse
    {
        return response()->json([
            'opportunities' => HrTalentOpportunity::query()->orderByDesc('id')->limit((int) $request->input('limit', 20))->get(),
            'allocation' => $this->marketplace->allocationAnalysis($request->input('organization_id')),
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        return response()->json($this->marketplace->publish($request->all(), $request->user()));
    }

    public function match(Request $request, HrTalentOpportunity $opportunity): JsonResponse
    {
        return response()->json($this->marketplace->match($opportunity, $request->all()));
    }

    public function matchForEmployee(Request $request, int $employee): JsonResponse
    {
        return response()->json($this->marketplace->matchForEmployee($employee, $request->all()));
    }

    public function reviewMatch(Request $request, int $match): JsonResponse
    {
        return response()->json($this->marketplace->review($match, (string) $request->input('status', 'CONFIRMED'),
            $request->user(), $request->input('notes')));
    }

    public function assignProject(Request $request): JsonResponse
    {
        return response()->json($this->marketplace->assignProject($request->all(), $request->user()));
    }

    public function assignTemporary(Request $request): JsonResponse
    {
        return response()->json($this->marketplace->assignTemporary($request->all(), $request->user()));
    }

    public function allocation(Request $request): JsonResponse
    {
        return response()->json($this->marketplace->allocationAnalysis($request->input('organization_id'), (int) $request->input('limit', 200)));
    }

    public function candidates(Request $request): JsonResponse
    {
        return response()->json($this->marketplace->mobilityCandidates((string) $request->input('skill_code'),
            $request->input('organization_id'), (int) $request->input('limit', 20)));
    }

    public function careerSimulate(Request $request): JsonResponse
    {
        return response()->json($this->career->simulate((int) $request->input('employee_id'), (int) $request->input('target_position_id')));
    }

    public function careerPaths(Request $request): JsonResponse
    {
        return response()->json($this->career->paths($request->filled('from_position_id') ? (int) $request->input('from_position_id') : null,
            (int) $request->input('depth', 3)));
    }

    public function careerPlan(Request $request): JsonResponse
    {
        return response()->json($this->career->plan((int) $request->input('employee_id'), (int) $request->input('target_position_id'),
            $request->user(), $request->all()));
    }

    public function internalMobility(Request $request): JsonResponse
    {
        return response()->json($this->career->internalMobility($request->input('organization_id'), (int) $request->input('limit', 20)));
    }
}
