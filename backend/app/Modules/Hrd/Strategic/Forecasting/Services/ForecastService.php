<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastInput;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastModel;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastOutput;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastRun;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrModelRegistry;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Workforce\Services\WorkforcePlanningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Workforce Forecasting (§49–§51, §115): headcount, attrition, hiring, biaya,
 * pensiun, dan permintaan keterampilan — dengan metode yang dapat dikonfigurasi
 * (trend historis, moving average, musiman, asumsi bisnis) dan siap untuk ML.
 */
class ForecastService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly WorkforcePlanningService $planning,
    ) {}

    public function defineModel(array $data): HrForecastModel
    {
        $model = HrForecastModel::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'purpose' => strtoupper((string) ($data['purpose'] ?? 'HEADCOUNT')),
                'method' => strtoupper((string) ($data['method'] ?? 'HISTORICAL_TREND')),
                'version' => (int) ($data['version'] ?? 1),
                'parameters' => $data['parameters'] ?? ['window' => (int) $this->ctx->config('forecasting.moving_average_window', 3)],
                'features' => $data['features'] ?? ['hire_date', 'termination_date', 'headcount_history'],
                'status' => 'ACTIVE', 'metrics' => $data['metrics'] ?? [],
                'owner_role' => $data['owner_role'] ?? 'HR_ANALYST', 'is_active' => true,
            ]
        );

        HrModelRegistry::query()->updateOrCreate(
            ['code' => $model->code, 'version' => (int) $model->version],
            [
                'name' => $model->name, 'model_type' => 'FORECAST', 'purpose' => $model->purpose,
                'training_period_start' => $data['training_period_start'] ?? now()->subYears(2)->toDateString(),
                'training_period_end' => $data['training_period_end'] ?? now()->toDateString(),
                'features' => $model->features, 'metrics' => $model->metrics,
                'approval_status' => $data['approval_status'] ?? 'APPROVED',
                'approved_at' => now(), 'status' => 'ACTIVE',
                'monitoring_notes' => 'Tinjau error proyeksi setiap kuartal.',
            ]
        );

        return $model;
    }

    /**
     * Jalankan proyeksi. Idempotensi dijaga per model+periode+scope.
     *
     * @return array<string, mixed>
     */
    public function run(string $modelCode, array $inputs = [], mixed $actor = null): array
    {
        $model = HrForecastModel::query()->where('code', strtoupper($modelCode))->first();
        if (! $model) {
            return ['error' => 'Model tidak terdaftar: '.$modelCode];
        }

        $period = (string) ($inputs['period'] ?? now()->format('Y-m'));
        $organizationId = $inputs['organization_id'] ?? null;
        $idempotencyKey = 'forecast:'.$model->code.':'.$period.':'.($organizationId ?? 'ALL');

        if (! $this->ctx->firstTime($idempotencyKey, 3600)) {
            $existing = HrForecastRun::query()->where('idempotency_key', $idempotencyKey)->first();

            return ['run' => $existing?->code, 'reused' => true, 'outputs' => (array) ($existing->outputs ?? [])];
        }

        $horizon = (int) ($inputs['horizon_months'] ?? $this->ctx->config('forecasting.default_horizon_months', 12));
        $run = HrForecastRun::query()->create([
            'model_code' => $model->code, 'code' => $this->ctx->nextCode('hr_forecast_runs', 'FRN'),
            'purpose' => $model->purpose, 'scope' => $organizationId ? 'ORGANIZATION' : 'GLOBAL',
            'organization_id' => $organizationId, 'period' => $period, 'horizon_months' => $horizon,
            'status' => 'RUNNING', 'generated_by_user_id' => $this->ctx->actorId($actor),
            'idempotency_key' => $idempotencyKey, 'started_at' => now(),
        ]);

        foreach ($inputs as $key => $value) {
            HrForecastInput::query()->create([
                'run_id' => $run->getKey(), 'input_type' => is_numeric($value) ? 'ASSUMPTION' : 'EXTERNAL',
                'key' => (string) $key, 'value' => is_numeric($value) ? (float) $value : null,
                'value_json' => is_numeric($value) ? null : ['value' => $value],
                'period' => $period, 'source' => strtoupper((string) ($inputs['source'] ?? 'HR')),
            ]);
        }

        $history = $this->history($model->purpose, (int) ($inputs['history_months'] ?? 12), $organizationId);
        $projection = $this->project($model, $history, $horizon, $inputs);

        $rows = [];
        foreach ($projection['points'] as $point) {
            $rows[] = HrForecastOutput::query()->create([
                'run_id' => $run->getKey(), 'period' => $point['period'], 'metric_key' => $model->purpose,
                'value' => $point['value'], 'lower_bound' => $point['lower_bound'], 'upper_bound' => $point['upper_bound'],
                'confidence' => $point['confidence'], 'method' => $projection['method'], 'drivers' => $projection['drivers'],
            ]);
        }

        $summary = [
            'method' => $projection['method'], 'history_months' => count($history['series']),
            'latest_actual' => $history['latest'], 'projected_end' => end($projection['points'])['value'] ?? null,
            'change_percent' => $this->ctx->change((float) $history['latest'], (float) (end($projection['points'])['value'] ?? 0)),
            'confidence' => $projection['confidence'],
            'assumptions' => $projection['drivers'],
        ];

        $run->forceFill([
            'status' => 'COMPLETED', 'summary' => $summary, 'outputs' => $rows === [] ? [] : [
                'points' => count($rows), 'metric' => $model->purpose,
            ],
            'confidence' => $projection['confidence'] / 100, 'completed_at' => now(),
        ])->save();

        $this->ctx->audit('strategic.forecast.generated', HrForecastRun::class, $run->getKey(), $summary, null, $actor);
        $this->ctx->event('FORECASTING', 'ForecastGenerated', [
            'title' => 'Proyeksi dihasilkan', 'description' => $model->purpose.' ('.$model->method.')', 'meta' => $summary,
        ], null, HrForecastRun::class, $run->getKey(), $actor);

        return ['run' => $run->code, 'model' => $model->code, 'summary' => $summary, 'outputs' => $rows];
    }

    /**
     * Deret historis dari data aktual.
     *
     * @return array<string, mixed>
     */
    public function history(string $purpose, int $months = 12, ?int $organizationId = null): array
    {
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $period = Carbon::now()->subMonths($i);
            $value = match (strtoupper($purpose)) {
                'HEADCOUNT' => $this->headcountAt($period, $organizationId),
                'ATTRITION' => $this->leaversIn($period, $organizationId),
                'COST' => $this->payrollCostIn($period, $organizationId),
                'HIRING' => $this->hiresIn($period, $organizationId),
                default => $this->headcountAt($period, $organizationId),
            };
            $series[] = ['period' => $period->format('Y-m'), 'value' => $value];
        }

        return [
            'purpose' => strtoupper($purpose),
            'series' => $series,
            'latest' => (float) (end($series)['value'] ?? 0),
            'average' => $series === [] ? 0.0 : round(array_sum(array_column($series, 'value')) / count($series), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $runs = HrForecastRun::query()->orderByDesc('id')->limit(20)->get();

        return [
            'models' => HrForecastModel::query()->where('is_active', true)->get()
                ->map(fn (HrForecastModel $model): array => [
                    'code' => $model->code, 'purpose' => $model->purpose, 'method' => $model->method, 'version' => (int) $model->version,
                ])->all(),
            'runs' => $runs->map(fn (HrForecastRun $run): array => [
                'code' => $run->code, 'purpose' => $run->purpose, 'status' => $run->status,
                'confidence' => (float) $run->confidence, 'summary' => $run->summary,
            ])->all(),
            'registry' => HrModelRegistry::query()->orderByDesc('id')->limit(10)->get()
                ->map(fn (HrModelRegistry $row): array => ['code' => $row->code, 'type' => $row->model_type, 'status' => $row->status])->all(),
            'accuracy_note' => 'Proyeksi disertai batas bawah/atas dan asumsi; selalu ditinjau manusia sebelum menjadi keputusan.',
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function project(HrForecastModel $model, array $history, int $horizon, array $inputs): array
    {
        $series = array_map(fn (array $row): float => (float) $row['value'], $history['series']);
        $latest = (float) $history['latest'];
        $method = $model->method;
        $drivers = [
            'history_average' => $history['average'],
            'history_latest' => $latest,
            'method' => $method,
            'inputs' => $inputs,
        ];

        $slope = 0.0;
        if (count($series) >= 2 && $latest > 0) {
            $first = $series[0];
            $steps = max(count($series) - 1, 1);
            $slope = ($latest - $first) / $steps;
        }

        $window = (int) ($model->parameters['window'] ?? 3);
        $movingAverage = $series === [] ? 0.0 : array_sum(array_slice($series, -$window)) / min($window, count($series));
        $growthAssumption = (float) ($inputs['growth_percent'] ?? 0);

        $points = [];
        $cursor = Carbon::now();
        for ($month = 1; $month <= $horizon; $month++) {
            $cursor = $cursor->copy()->addMonth();
            $base = match ($method) {
                'MOVING_AVERAGE' => $movingAverage + ($slope * $month),
                'SEASONALITY' => $latest * (1 + (($month % 12 === 0 ? 0.03 : 0.01) * (($month % 4 === 0) ? 1.5 : 1))),
                'BUSINESS_ASSUMPTION' => $latest * (1 + $growthAssumption / 100) * $month / max($horizon, 1) + $latest * 0.02,
                default => $latest + ($slope * $month),
            };

            if (strtoupper($model->purpose) === 'ATTRITION') {
                $rate = $history['average'] > 0 ? $history['average'] : 1.0;
                $base = $rate;
            }

            $confidence = max((float) $this->ctx->config('forecasting.confidence_floor', 0.4),
                min(0.95, 0.9 - ($month / max($horizon, 1)) * 0.35));

            $points[] = [
                'period' => $cursor->format('Y-m'),
                'value' => round(max($base, 0), 2),
                'lower_bound' => round(max($base * (1 - (1 - $confidence) * 0.5), 0), 2),
                'upper_bound' => round($base * (1 + (1 - $confidence) * 0.5), 2),
                'confidence' => round($confidence, 2),
            ];
        }

        return [
            'method' => $method, 'points' => $points,
            'confidence' => round(($points[0]['confidence'] ?? 0.5) * 100, 2),
            'drivers' => $drivers + ['slope_per_month' => round($slope, 2), 'moving_average' => round($movingAverage, 2)],
        ];
    }

    private function headcountAt(Carbon $period, ?int $organizationId = null): float
    {
        $end = $period->copy()->endOfMonth()->toDateString();

        return (float) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('hire_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>', $end))
            ->count(), 0);
    }

    private function leaversIn(Carbon $period, ?int $organizationId = null): float
    {
        return (float) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('termination_date')
            ->whereBetween('termination_date', [$period->copy()->startOfMonth()->toDateString(), $period->copy()->endOfMonth()->toDateString()])
            ->count(), 0);
    }

    private function hiresIn(Carbon $period, ?int $organizationId = null): float
    {
        return (float) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereBetween('hire_date', [$period->copy()->startOfMonth()->toDateString(), $period->copy()->endOfMonth()->toDateString()])
            ->count(), 0);
    }

    private function payrollCostIn(Carbon $period, ?int $organizationId = null): float
    {
        return (float) $this->ctx->safe(function () use ($period, $organizationId): float {
            if (! $this->ctx->hasTable('hr_payroll_items')) {
                return 0.0;
            }

            return (float) DB::table('hr_payroll_items')->where('created_at', 'like', $period->format('Y-m').'%')->sum('net_amount');
        }, 0.0);
    }
}
