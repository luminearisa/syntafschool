<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlan;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use App\Modules\Hrd\Strategic\Workforce\Services\OrganizationDesignService;
use App\Modules\Hrd\Strategic\Workforce\Services\ScenarioService;
use App\Modules\Hrd\Strategic\Workforce\Services\WorkforcePlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategicController
{
    public function __construct(
        private readonly WorkforcePlanningService $planning,
        private readonly ScenarioService $scenarios,
        private readonly OrganizationDesignService $design,
    ) {}

    public function workforce(Request $request): JsonResponse
    {
        $plan = $request->filled('plan_id')
            ? HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'))
            : HrWorkforcePlan::query()->orderByDesc('id')->first();

        return response()->json($plan
            ? $this->planning->dashboard($plan)
            : ['message' => 'Belum ada rencana tenaga kerja. Buat lewat POST strategic/workforce/plans.']);
    }

    public function plans(Request $request): JsonResponse
    {
        return response()->json($this->planning->createPlan($request->all(), $request->user()));
    }

    public function demand(Request $request): JsonResponse
    {
        $plan = HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'));

        return response()->json(['lines' => $this->planning->addDemand($plan, (array) $request->input('lines', []), $request->user())]);
    }

    public function supply(Request $request): JsonResponse
    {
        $plan = HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'));

        return response()->json($this->planning->captureSupply($plan, (array) $request->input('filter', []), $request->user()));
    }

    public function gaps(Request $request): JsonResponse
    {
        $plan = HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'));

        return response()->json($this->planning->analyseGaps($plan, $request->user()));
    }

    public function cost(Request $request): JsonResponse
    {
        $plan = HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'));

        return response()->json($this->planning->forecastCost($plan, (array) $request->input('assumptions', []), $request->user()));
    }

    public function positions(Request $request): JsonResponse
    {
        return response()->json($this->planning->optimisePositions($request->filled('organization_id') ? (int) $request->input('organization_id') : null));
    }

    public function capacity(Request $request): JsonResponse
    {
        return response()->json($this->planning->capacity(
            $request->filled('organization_id') ? (int) $request->input('organization_id') : null,
            $request->input('period'),
            $request->user(),
        ));
    }

    public function scenarios(Request $request): JsonResponse
    {
        $plan = HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'));

        return response()->json($this->scenarios->createScenario($plan, $request->all(), $request->user()));
    }

    public function simulateScenario(Request $request, HrWorkforceScenario $scenario): JsonResponse
    {
        return response()->json($this->scenarios->simulate($scenario, $request->user()));
    }

    public function whatIf(Request $request): JsonResponse
    {
        $plan = HrWorkforcePlan::query()->findOrFail((int) $request->input('plan_id'));

        return response()->json($this->scenarios->whatIf($plan, (array) $request->input('levers', []), $request->user()));
    }

    public function compareScenarios(Request $request): JsonResponse
    {
        return response()->json($this->scenarios->compare((array) $request->input('scenario_ids', [])));
    }

    public function organizationDesign(Request $request): JsonResponse
    {
        return response()->json($this->design->propose($request->all(), $request->user()));
    }

    public function simulateOrganizationDesign(Request $request, int $scenario): JsonResponse
    {
        $model = \App\Modules\Hrd\Strategic\Workforce\Models\HrOrganizationDesignScenario::query()->findOrFail($scenario);

        return response()->json($this->design->simulate($model, $request->user()));
    }

    public function spanOfControl(Request $request): JsonResponse
    {
        return response()->json($this->design->spanOfControl(
            $request->filled('organization_id') ? (int) $request->input('organization_id') : null,
            $request->user(),
        ));
    }

    public function network(Request $request): JsonResponse
    {
        return response()->json($this->design->network(
            $request->filled('organization_id') ? (int) $request->input('organization_id') : null,
            (int) $request->input('limit', 80),
            $request->user(),
        ));
    }
}
