<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Services;

use App\Modules\Hrd\Strategic\Analytics\Models\HrDataCatalog;
use App\Modules\Hrd\Strategic\Analytics\Models\HrDataQualityRule;
use App\Modules\Hrd\Strategic\Analytics\Models\HrMetricDefinition;
use App\Modules\Hrd\Strategic\Analytics\Models\HrMetricLineage;
use App\Modules\Hrd\Strategic\Analytics\Models\HrMetricSnapshot;
use App\Modules\Hrd\Strategic\Analytics\Models\HrSemanticEntity;
use App\Modules\Hrd\Strategic\Analytics\Models\HrSemanticField;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR Metric Engine (§44, §107–§109): metrik adalah DATA, bukan kode.
 *
 * Evaluasi hanya boleh menyentuh tabel/kolom yang terdaftar di lapisan
 * semantik (hr_semantic_entities/hr_semantic_fields) — tidak ada SQL bebas
 * dari LLM atau pengguna, dan setiap metrik punya silsilah (lineage).
 */
class MetricEngine
{
    /** Cadangan bila lapisan semantik belum di-seed. */
    private const DEFAULT_WHITELIST = [
        'hr_employees' => ['id', 'is_active', 'lifecycle_state', 'organization_id', 'department_id', 'position_id',
            'location_id', 'worker_type', 'employment_type', 'hire_date', 'termination_date', 'manager_employee_id'],
        'hr_positions' => ['id', 'organization_id', 'department_id', 'authorized_headcount', 'filled_headcount', 'is_critical', 'status'],
        'hr_payroll_items' => ['id', 'employee_id', 'run_id', 'base_amount', 'net_amount', 'overtime_amount', 'tax_total', 'gross_amount'],
        'hr_attendance_records' => ['id', 'employee_id', 'date', 'status', 'worked_minutes', 'late_minutes', 'overtime_minutes'],
        'hr_leave_requests' => ['id', 'employee_id', 'status', 'total_days', 'start_date', 'end_date'],
        'hr_performance_reviews' => ['id', 'employee_id', 'status', 'overall_score', 'rating_label'],
        'hr_training_assignments' => ['id', 'employee_id', 'status', 'training_id'],
        'hr_training_completions' => ['id', 'employee_id', 'training_id', 'completed_at'],
        'hr_internal_job_applications' => ['id', 'employee_id', 'status', 'created_at'],
        'hr_employee_skills' => ['id', 'employee_id', 'skill_id', 'proficiency_level_code', 'verified'],
        'hr_talent_pool_members' => ['id', 'employee_id', 'pool_id', 'status', 'readiness'],
        'hr_job_openings' => ['id', 'organization_id', 'openings_count', 'filled_count', 'status', 'published_at'],
        'hr_talent_review_results' => ['id', 'employee_id', 'performance_rating', 'potential_rating', 'nine_box_code', 'readiness'],
        'hr_skill_forecasts' => ['id', 'skill_code', 'organization_id', 'period', 'current_supply', 'future_demand', 'gap'],
        'hr_skill_demand' => ['id', 'skill_code', 'organization_id', 'period', 'required_headcount', 'criticality', 'source'],
        'hr_critical_positions' => ['id', 'position_id', 'criticality', 'status', 'incumbent_employee_id', 'retirement_risk', 'turnover_risk'],
        'hr_succession_candidates' => ['id', 'plan_id', 'employee_id', 'candidate_type', 'readiness', 'readiness_score', 'status'],
        'hr_retention_signals' => ['id', 'employee_id', 'signal_level', 'retention_score', 'period', 'status'],
        'hr_risk_signals' => ['id', 'risk_id', 'signal_type', 'severity', 'employee_id', 'organization_id', 'period', 'status'],
        'hr_strategic_hr_actions' => ['id', 'code', 'priority', 'status', 'owner_employee_id', 'due_date', 'budget_amount'],
        'hr_workforce_demand' => ['id', 'plan_id', 'organization_id', 'department_id', 'position_id', 'required_headcount', 'period_start', 'period_end', 'status'],
        'hr_workforce_gaps' => ['id', 'plan_id', 'gap_type', 'dimension_key', 'gap_value', 'severity', 'status'],
        'hr_overtime_records' => ['id', 'employee_id', 'date', 'status', 'verified_minutes'],
        'hr_leave_balances' => ['id', 'employee_id', 'leave_type_id', 'period_code', 'entitlement_days', 'used_days', 'pending_days', 'available_days'],
        'hr_engagement_snapshots' => ['id', 'period', 'metric', 'value', 'respondents', 'organization_id'],
    ];

    public function __construct(private readonly Phase3Context $ctx) {}

    public function define(array $data): HrMetricDefinition
    {
        $definition = HrMetricDefinition::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'category' => $data['category'] ?? 'WORKFORCE',
                'entity' => $data['entity'] ?? 'EMPLOYEE', 'formula_type' => strtoupper((string) ($data['formula_type'] ?? 'COUNT')),
                'numerator' => $data['numerator'] ?? [], 'denominator' => $data['denominator'] ?? [],
                'filters' => $data['filters'] ?? [], 'dimensions' => $data['dimensions'] ?? [],
                'period_basis' => $data['period_basis'] ?? 'POINT_IN_TIME', 'unit' => $data['unit'] ?? 'orang',
                'description' => $data['description'] ?? null, 'owner_role' => $data['owner_role'] ?? 'HR_DIRECTOR',
                'steward_role' => $data['steward_role'] ?? 'HR_ANALYST',
                'data_classification' => $data['data_classification'] ?? 'INTERNAL',
                'refresh_frequency' => $data['refresh_frequency'] ?? 'DAILY',
                'is_canonical' => (bool) ($data['is_canonical'] ?? true),
                'source_tables' => $data['source_tables'] ?? array_values(array_unique(array_filter([
                    $data['numerator']['table'] ?? null, $data['denominator']['table'] ?? null,
                ]))),
                'sensitivity' => $data['sensitivity'] ?? 'NORMAL',
                'version' => (int) ($data['version'] ?? 1), 'is_active' => true,
            ]
        );

        foreach ((array) ($data['dimensions'] ?? []) as $dimension) {
            \App\Modules\Hrd\Strategic\Analytics\Models\HrMetricDimension::query()->updateOrCreate(
                ['metric_definition_id' => $definition->getKey(), 'dimension' => strtoupper((string) $dimension)],
                ['source_field' => strtolower((string) $dimension)]
            );
        }

        HrMetricLineage::query()->updateOrCreate(
            ['metric_code' => $definition->code, 'source_table' => $definition->source_tables[0] ?? 'hr_employees'],
            [
                'source_field' => $data['numerator']['column'] ?? 'id',
                'filter_expression' => json_encode(($data['numerator']['filters'] ?? []) + ($data['filters'] ?? [])),
                'calculation' => $definition->formula_type.'('.json_encode($definition->numerator).')',
                'period_basis' => $definition->period_basis, 'notes' => $definition->description,
            ]
        );

        return $definition->refresh();
    }

    /**
     * Evaluasi metrik. Hanya tabel & kolom terdaftar yang boleh disentuh.
     *
     * @return array<string, mixed>
     */
    public function evaluate(string $metricCode, array $filters = [], ?string $period = null, mixed $user = null): array
    {
        $definition = HrMetricDefinition::query()->where('code', strtoupper($metricCode))->where('is_active', true)->first();
        if (! $definition) {
            return ['metric' => strtoupper($metricCode), 'value' => null, 'error' => 'Metrik tidak terdaftar sebagai definisi kanonik.'];
        }

        $scope = $this->scopeFilter($user, $filters);
        $period = $period ?? now()->format('Y-m');
        $numerator = $this->aggregate((array) $definition->numerator, $scope, $period);
        $denominator = $this->aggregate((array) $definition->denominator, $scope, $period);

        $value = match ($definition->formula_type) {
            'RATIO' => $denominator['value'] > 0 ? $this->ctx->score($numerator['value'] / $denominator['value']) : 0.0,
            'PERCENT' => $this->ctx->percent($numerator['value'], $denominator['value']),
            'AVERAGE' => $numerator['value'],
            default => $numerator['value'],
        };

        return [
            'metric' => $definition->code, 'name' => $definition->name, 'category' => $definition->category,
            'period' => $period, 'unit' => $definition->unit, 'value' => $this->ctx->score($value),
            'numerator' => $numerator, 'denominator' => $denominator,
            'filters' => $scope, 'basis' => $definition->period_basis,
            'source_tables' => $definition->source_tables, 'classification' => $definition->data_classification,
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $metricCode, ?string $period = null, array $filters = [], mixed $user = null): HrMetricSnapshot
    {
        $result = $this->evaluate($metricCode, $filters, $period, $user);
        $period = $result['period'] ?? now()->format('Y-m');

        return HrMetricSnapshot::query()->updateOrCreate(
            ['metric_code' => strtoupper($metricCode), 'period' => $period, 'scope' => $filters['scope'] ?? 'GLOBAL',
                'organization_id' => $filters['organization_id'] ?? null],
            [
                'as_of' => now()->toDateString(), 'value' => $result['value'] ?? 0,
                'numerator_value' => $result['numerator']['value'] ?? 0,
                'denominator_value' => $result['denominator']['value'] ?? 0,
                'sample_size' => (int) ($result['denominator']['rows'] ?? 0),
                'computed_at' => now(), 'model_version' => 'metric-engine-v1',
            ]
        );
    }

    /**
     * Deret waktu metrik + MoM/QoQ/YoY.
     *
     * @return array<string, mixed>
     */
    public function trend(string $metricCode, int $periods = 12, array $filters = [], mixed $user = null): array
    {
        $series = [];
        for ($i = $periods - 1; $i >= 0; $i--) {
            $period = now()->subMonths($i)->format('Y-m');
            $result = $this->evaluate($metricCode, $filters, $period, $user);
            $series[] = ['period' => $period, 'value' => (float) ($result['value'] ?? 0)];
            $this->snapshot($metricCode, $period, $filters, $user);
        }

        return [
            'metric' => strtoupper($metricCode),
            'series' => $series,
            'trend' => $this->ctx->trend(array_column($series, 'value')),
            'periods' => $periods,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lineage(string $metricCode): array
    {
        $definition = HrMetricDefinition::query()->where('code', strtoupper($metricCode))->first();
        $rows = HrMetricLineage::query()->where('metric_code', strtoupper($metricCode))->get();

        return [
            'metric' => strtoupper($metricCode),
            'definition' => $definition ? [
                'formula_type' => $definition->formula_type, 'numerator' => $definition->numerator,
                'denominator' => $definition->denominator, 'filters' => $definition->filters,
                'period_basis' => $definition->period_basis, 'owner_role' => $definition->owner_role,
                'classification' => $definition->data_classification, 'is_canonical' => (bool) $definition->is_canonical,
            ] : null,
            'lineage' => $rows->map(fn (HrMetricLineage $row): array => [
                'source_table' => $row->source_table, 'source_field' => $row->source_field,
                'filter' => $row->filter_expression, 'calculation' => $row->calculation, 'period' => $row->period_basis,
            ])->all(),
        ];
    }

    /**
     * Katalog data & aturan kualitas (§106, §109).
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $rows = HrDataCatalog::query()->orderBy('dataset')->get();
        $rules = HrDataQualityRule::query()->get();

        return [
            'datasets' => $rows->map(fn (HrDataCatalog $row): array => [
                'code' => $row->code, 'dataset' => $row->dataset, 'owner_role' => $row->owner_role,
                'steward_role' => $row->steward_role, 'source_tables' => $row->source_tables,
                'refresh' => $row->refresh_frequency, 'sensitivity' => $row->sensitivity,
                'classification' => $row->classification, 'access_roles' => $row->access_roles,
            ])->all(),
            'quality_rules' => $rules->map(fn (HrDataQualityRule $row): array => [
                'code' => $row->code, 'dataset' => $row->dataset, 'rule_type' => $row->rule_type,
                'severity' => $row->severity, 'status' => $row->status,
            ])->all(),
            'count' => $rows->count(),
        ];
    }

    /**
     * Menjalankan aturan kualitas data pada kolom kunci.
     *
     * @return array<string, mixed>
     */
    public function runQualityChecks(): array
    {
        $checks = [
            'EMPLOYEE' => ['table' => 'hr_employees', 'column' => 'employee_number'],
            'POSITION' => ['table' => 'hr_positions', 'column' => 'code'],
            'PAYROLL' => ['table' => 'hr_payroll_items', 'column' => 'employee_id'],
            'SKILLS' => ['table' => 'hr_employee_skills', 'column' => 'skill_id'],
        ];
        $results = [];

        foreach (HrDataQualityRule::query()->get() as $rule) {
            $check = $checks[$rule->dataset] ?? null;
            if (! $check || ! Schema::hasTable($check['table'])) {
                continue;
            }
            $total = (int) DB::table($check['table'])->count();
            $missing = (int) DB::table($check['table'])->whereNull($check['column'])->count();
            $score = $this->ctx->percent($total - $missing, max($total, 1));
            $status = $score >= 98 ? 'PASS' : ($score >= 90 ? 'WARN' : 'FAIL');

            $rule->forceFill([
                'last_run_at' => now(),
                'last_result' => ['total' => $total, 'missing' => $missing, 'score' => $score, 'status' => $status],
                'status' => $status,
            ])->save();

            $results[] = ['code' => $rule->code, 'dataset' => $rule->dataset, 'score' => $score, 'status' => $status];
        }

        return ['rules' => count($results), 'results' => $results];
    }

    /**
     * Definisi metrik kanonik (§108): satu angka, satu definisi, satu pemilik.
     *
     * @return array<string, mixed>
     */
    public function canonicalMetrics(): array
    {
        return [
            'metrics' => HrMetricDefinition::query()->where('is_canonical', true)->get()
                ->map(fn (HrMetricDefinition $row): array => [
                    'code' => $row->code, 'name' => $row->name, 'category' => $row->category,
                    'unit' => $row->unit, 'owner_role' => $row->owner_role, 'basis' => $row->period_basis,
                ])->all(),
            'note' => 'Definisi ini yang dipakai seluruh dashboard HR, Finance, dan AI — mencegah dua angka berbeda untuk satu istilah.',
        ];
    }

    /**
     * Daftar tabel/kolom yang boleh diakses AI & analitik (lapisan semantik, §123).
     *
     * @return array<string, array<int, string>>
     */
    public function semanticWhitelist(): array
    {
        $entities = HrSemanticEntity::query()->where('is_active', true)->get();
        if ($entities->isEmpty()) {
            return self::DEFAULT_WHITELIST;
        }

        $whitelist = [];
        foreach ($entities as $entity) {
            $fields = HrSemanticField::query()->where('entity_code', $entity->code)->pluck('source_field')
                ->filter()->all();
            $table = $this->entityTable($entity->code);
            if ($table) {
                $whitelist[$table] = $fields === [] ? (self::DEFAULT_WHITELIST[$table] ?? ['id']) : $fields;
            }
        }

        return $whitelist === [] ? self::DEFAULT_WHITELIST : $whitelist;
    }

    public function entityTable(string $code): ?string
    {
        return match (strtoupper($code)) {
            'EMPLOYEE' => 'hr_employees', 'POSITION' => 'hr_positions', 'PAYROLL_ITEM' => 'hr_payroll_items',
            'ATTENDANCE' => 'hr_attendance_records', 'LEAVE_REQUEST' => 'hr_leave_requests',
            'PERFORMANCE_REVIEW' => 'hr_performance_reviews', 'TRAINING_ASSIGNMENT' => 'hr_training_assignments',
            'TRAINING_COMPLETION' => 'hr_training_completions', 'INTERNAL_APPLICATION' => 'hr_internal_job_applications',
            'EMPLOYEE_SKILL' => 'hr_employee_skills', 'TALENT_POOL_MEMBER' => 'hr_talent_pool_members',
            'JOB_OPENING' => 'hr_job_openings',
            'TALENT_REVIEW_RESULT' => 'hr_talent_review_results',
            'SKILL_FORECAST' => 'hr_skill_forecasts',
            'SKILL_DEMAND' => 'hr_skill_demand',
            'CRITICAL_POSITION' => 'hr_critical_positions',
            'SUCCESSION_CANDIDATE' => 'hr_succession_candidates',
            'RETENTION_SIGNAL' => 'hr_retention_signals',
            'RISK_SIGNAL' => 'hr_risk_signals',
            'STRATEGIC_ACTION' => 'hr_strategic_hr_actions',
            'WORKFORCE_DEMAND' => 'hr_workforce_demand',
            'WORKFORCE_GAP' => 'hr_workforce_gaps',
            'OVERTIME_RECORD' => 'hr_overtime_records',
            'ENGAGEMENT_SNAPSHOT' => 'hr_engagement_snapshots',
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function scopeFilter(mixed $user, array $filters): array
    {
        $scope = $this->ctx->scopeOf($user, true);
        $whitelist = $this->semanticWhitelist();

        foreach ($filters as $key => $value) {
            if (in_array($key, ['organization_id', 'department_id', 'position_id', 'location_id', 'worker_type'], true)) {
                continue;
            }
            unset($filters[$key]);
        }

        if ($scope['type'] === 'SELF' && $scope['employee_id']) {
            $filters['employee_id'] = $scope['employee_id'];
        } elseif ($scope['type'] === 'DEPARTMENT' && $scope['department_id'] && ! isset($filters['department_id'])) {
            $filters['department_id'] = $scope['department_id'];
        } elseif ($scope['type'] === 'ORGANIZATION' && $scope['organization_id'] && ! isset($filters['organization_id'])) {
            $filters['organization_id'] = $scope['organization_id'];
        }

        return ['scope' => $scope['type'], 'filters' => $filters, 'whitelisted_tables' => count($whitelist)];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function aggregate(array $spec, array $scope, string $period): array
    {
        if ($spec === [] || empty($spec['table'])) {
            return ['value' => 0.0, 'rows' => 0, 'spec' => $spec];
        }

        $table = (string) $spec['table'];
        $whitelist = $this->semanticWhitelist();
        if (! isset($whitelist[$table]) || ! Schema::hasTable($table)) {
            return ['value' => 0.0, 'rows' => 0, 'error' => 'Tabel di luar lapisan semantik: '.$table];
        }

        $operation = strtoupper((string) ($spec['operation'] ?? 'COUNT'));
        $column = (string) ($spec['column'] ?? 'id');
        if ($operation !== 'COUNT' && ! in_array($column, $whitelist[$table], true)) {
            return ['value' => 0.0, 'rows' => 0, 'error' => 'Kolom di luar lapisan semantik: '.$column];
        }

        $query = DB::table($table);
        foreach ((array) ($spec['filters'] ?? []) + ($scope['filters'] ?? []) as $key => $value) {
            if (! in_array($key, $whitelist[$table], true)) {
                continue;
            }
            if ($key === 'hire_date' || $key === 'termination_date') {
                continue;
            }
            is_array($value) ? $query->whereIn($key, $value) : $query->where($key, $value);
        }

        // Batas periode untuk basis titik-waktu: pegawai yang aktif pada akhir periode.
        if ($table === 'hr_employees' && ! empty($spec['point_in_time'])) {
            $end = $period.'-01' === $period ? $period : \Illuminate\Support\Carbon::parse($period.'-01')->endOfMonth()->toDateString();
            $query->where('hire_date', '<=', $end)
                ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>', $end));
        }

        if (! empty($spec['period_column'])) {
            $query->where($spec['period_column'], 'like', $period.'%');
        }

        $rows = (int) $query->count();
        $value = match ($operation) {
            'SUM' => (float) $query->clone()->sum($column),
            'AVG', 'AVERAGE' => (float) $query->clone()->avg($column),
            'COUNT_DISTINCT' => (float) $query->clone()->distinct()->count($column),
            default => (float) $rows,
        };

        return ['value' => $this->ctx->score($value), 'rows' => $rows, 'table' => $table, 'operation' => $operation, 'column' => $column];
    }
}
