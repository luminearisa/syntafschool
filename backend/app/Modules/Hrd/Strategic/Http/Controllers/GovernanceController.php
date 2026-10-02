<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrAction;
use App\Modules\Hrd\Strategic\Governance\Services\RiskService;
use App\Modules\Hrd\Strategic\Governance\Services\ScorecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GovernanceController
{
    public function __construct(
        private readonly RiskService $risks,
        private readonly ScorecardService $scorecard,
    ) {}

    public function risks(Request $request): JsonResponse
    {
        return response()->json($this->risks->register($request->input('organization_id'), (int) $request->input('limit', 50)));
    }

    public function scan(Request $request): JsonResponse
    {
        return response()->json($this->risks->scan($request->input('organization_id'), $request->user()));
    }

    public function actions(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return response()->json($this->risks->createAction($request->all(), $request->user()));
        }

        return response()->json(HrStrategicHrAction::query()->orderByDesc('id')->limit((int) $request->input('limit', 50))->get());
    }

    public function decideAction(Request $request, HrStrategicHrAction $action): JsonResponse
    {
        return response()->json($this->risks->approveAction($action, $request->user(), (string) $request->input('decision', 'APPROVED')));
    }

    public function measureAction(Request $request, HrStrategicHrAction $action): JsonResponse
    {
        return response()->json($this->risks->measure($action, (array) $request->input('measurements', []), $request->user()));
    }

    public function reviewAction(Request $request, HrStrategicHrAction $action): JsonResponse
    {
        return response()->json($this->risks->review($action, $request->all(), $request->user()));
    }

    public function commandCenter(Request $request): JsonResponse
    {
        return response()->json($this->risks->commandCenter($request->input('organization_id'), $request->user()));
    }

    public function scorecard(Request $request): JsonResponse
    {
        return response()->json($this->scorecard->scorecard($request->input('organization_id'), (array) $request->input('targets', []), $request->user()));
    }

    public function executive(Request $request): JsonResponse
    {
        return response()->json($this->scorecard->executive($request->input('organization_id'), $request->user()));
    }

    public function foundation(Request $request): JsonResponse
    {
        return response()->json($this->scorecard->foundation((int) $request->input('limit', 10), $request->user()));
    }

    public function analyticsBoard(Request $request): JsonResponse
    {
        return response()->json($this->scorecard->analyticsBoard($request->input('organization_id'), $request->user()));
    }
}
