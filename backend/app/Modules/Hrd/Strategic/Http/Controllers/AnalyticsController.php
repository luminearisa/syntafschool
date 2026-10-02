<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Analytics\Services\MetricEngine;
use App\Modules\Hrd\Strategic\Analytics\Services\PeopleAnalyticsService;
use App\Modules\Hrd\Strategic\Compensation\Services\CompensationIntelligenceService;
use App\Modules\Hrd\Strategic\Experience\Services\EmployeeExperienceService;
use App\Modules\Hrd\Strategic\Forecasting\Services\ForecastService;
use App\Modules\Hrd\Strategic\Retention\Services\RetentionAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController
{
    public function __construct(
        private readonly MetricEngine $metrics,
        private readonly PeopleAnalyticsService $analytics,
        private readonly ForecastService $forecast,
        private readonly CompensationIntelligenceService $compensation,
        private readonly RetentionAnalyticsService $retention,
        private readonly EmployeeExperienceService $experience,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->analytics->dashboard((string) $request->input('dashboard', 'EXECUTIVE'),
            (array) $request->input('filters', []), $request->user()));
    }

    public function coverage(): JsonResponse
    {
        return response()->json($this->analytics->coverage());
    }

    public function metrics(Request $request): JsonResponse
    {
        return response()->json($this->metrics->canonicalMetrics());
    }

    public function metric(Request $request, string $metric): JsonResponse
    {
        return response()->json([
            'evaluation' => $this->metrics->evaluate($metric, (array) $request->input('filters', []), $request->input('period'), $request->user()),
            'trend' => $this->metrics->trend($metric, (int) $request->input('periods', 12), (array) $request->input('filters', []), $request->user()),
            'lineage' => $this->metrics->lineage($metric),
        ]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json($this->metrics->catalog());
    }

    public function qualityCheck(): JsonResponse
    {
        return response()->json($this->metrics->runQualityChecks());
    }

    public function snapshots(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return response()->json($request->filled('domain')
                ? $this->analytics->captureDomainSnapshot((string) $request->input('domain'), $request->input('organization_id'), $request->user())
                : $this->analytics->captureWorkforceSnapshot((string) $request->input('granularity', 'MONTHLY'), $request->input('organization_id'), $request->user()));
        }

        return response()->json([
            'workforce' => \App\Modules\Hrd\Strategic\Analytics\Models\HrWorkforceSnapshot::query()->orderByDesc('snapshot_date')->limit(60)->get(),
            'domains' => \App\Modules\Hrd\Strategic\Analytics\Models\HrPeopleAnalyticsSnapshot::query()->orderByDesc('id')->limit(60)->get(),
        ]);
    }

    public function forecast(Request $request): JsonResponse
    {
        return response()->json($this->forecast->dashboard());
    }

    public function runForecast(Request $request): JsonResponse
    {
        return response()->json($this->forecast->run((string) $request->input('model_code', 'FCT-HEADCOUNT'),
            $request->all(), $request->user()));
    }

    public function compensation(Request $request): JsonResponse
    {
        return response()->json([
            'analytics' => $this->compensation->analytics($request->input('organization_id'), (int) $request->input('limit', 500), $request->user()),
            'budget_variance' => $this->compensation->budgetVariance($request->input('organization_id'), $request->input('period')),
        ]);
    }

    public function payEquity(Request $request): JsonResponse
    {
        return response()->json($this->compensation->payEquity($request->input('organization_id'), $request->all(), $request->user()));
    }

    public function simulateCompensation(Request $request): JsonResponse
    {
        return response()->json($this->compensation->simulate($request->all(), $request->user()));
    }

    public function simulateIncentive(Request $request): JsonResponse
    {
        return response()->json($this->compensation->simulateIncentive($request->all(), $request->user()));
    }

    public function retention(Request $request): JsonResponse
    {
        return response()->json($this->retention->dashboard($request->input('organization_id')));
    }

    public function retentionSignals(Request $request): JsonResponse
    {
        return response()->json($this->retention->signals($request->input('organization_id'), (int) $request->input('limit', 200), $request->user()));
    }

    public function attrition(Request $request): JsonResponse
    {
        return response()->json([
            'analytics' => $this->retention->attritionAnalytics($request->input('organization_id'), $request->input('period'), (string) $request->input('dimension', 'DEPARTMENT')),
            'series' => $this->retention->turnoverSeries((int) $request->input('months', 12), $request->input('organization_id')),
        ]);
    }

    public function experience(Request $request): JsonResponse
    {
        return response()->json($this->experience->captureSnapshot($request->input('organization_id'),
            (string) $request->input('granularity', 'MONTHLY'), $request->user()));
    }

    public function journey(Request $request): JsonResponse
    {
        return response()->json($this->experience->journeyAnalytics($request->input('organization_id'), (int) $request->input('months', 12)));
    }

    public function services(Request $request): JsonResponse
    {
        return response()->json($this->experience->serviceIntelligence($request->input('organization_id'), $request->input('period')));
    }
}
