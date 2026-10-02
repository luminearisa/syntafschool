<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;
use App\Modules\Hrd\Strategic\Succession\Services\SuccessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuccessionController
{
    public function __construct(private readonly SuccessionService $succession) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->succession->dashboard($request->input('organization_id')));
    }

    public function coverage(Request $request): JsonResponse
    {
        return response()->json($this->succession->coverage($request->input('organization_id')));
    }

    public function flagCritical(Request $request): JsonResponse
    {
        return response()->json($this->succession->flagCriticalPosition((int) $request->input('position_id'), $request->all(), $request->user()));
    }

    public function createPlan(Request $request, HrCriticalPosition $critical): JsonResponse
    {
        return response()->json($this->succession->plan($critical, $request->all(), $request->user()));
    }

    public function nominate(Request $request, HrSuccessionPlan $plan): JsonResponse
    {
        return response()->json($this->succession->nominate($plan, (int) $request->input('employee_id'),
            (string) $request->input('candidate_type', 'SECONDARY'), $request->user()));
    }

    public function risks(Request $request, HrSuccessionPlan $plan): JsonResponse
    {
        return response()->json($this->succession->assessRisks($plan, $request->user()));
    }

    public function scenario(Request $request, HrCriticalPosition $critical): JsonResponse
    {
        return response()->json($this->succession->scenario($critical, (string) $request->input('departure_type', 'RESIGN'), $request->user()));
    }

    public function pipeline(Request $request): JsonResponse
    {
        return response()->json($this->succession->pipeline($request->input('organization_id')));
    }

    public function addToPipeline(Request $request): JsonResponse
    {
        return response()->json($this->succession->addToPipeline((int) $request->input('employee_id'),
            (string) $request->input('stage', 'EMERGING_LEADER'), $request->all(), $request->user()));
    }
}
