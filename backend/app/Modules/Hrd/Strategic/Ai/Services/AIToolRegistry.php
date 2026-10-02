<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiTool;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiToolPermission;
use App\Modules\Hrd\Strategic\Analytics\Services\MetricEngine;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;

/**
 * Registry alat AI (§67, §68, §72).
 *
 * Agent hanya boleh memanggil alat yang terdaftar, berizin, dan berada dalam
 * scope pemanggil. Kolom sensitif di-mask SEBELUM keluar dari layanan ini, dan
 * aksi tulis selalu berupa draf yang menunggu konfirmasi manusia.
 */
class AIToolRegistry
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
        private readonly MetricEngine $metrics,
    ) {}

    public function register(array $data): HrAiTool
    {
        return HrAiTool::query()->updateOrCreate(
            ['code' => strtolower((string) $data['code'])],
            [
                'name' => $data['name'], 'tool_group' => strtoupper((string) ($data['group'] ?? 'READ')),
                'description' => $data['description'] ?? null,
                'input_schema' => $data['input_schema'] ?? [], 'output_schema' => $data['output_schema'] ?? [],
                'handler' => $data['handler'] ?? $data['code'],
                'required_permission' => $data['permission'] ?? null,
                'sensitivity' => strtoupper((string) ($data['sensitivity'] ?? 'INTERNAL')),
                'is_write' => (bool) ($data['is_write'] ?? false),
                'requires_confirmation' => (bool) ($data['requires_confirmation'] ?? false),
                'is_active' => true,
            ]
        );
    }

    public function authorize(string $toolCode, mixed $user, ?string $agentCode = null): array
    {
        $tool = HrAiTool::query()->where('code', strtolower($toolCode))->where('is_active', true)->first();
        if (! $tool) {
            return ['allowed' => false, 'reason' => 'TOOL_TIDAK_TERDAFTAR', 'tool' => null, 'mask' => []];
        }

        $permission = (string) ($tool->required_permission ?? '');
        $hasPermission = $permission === '' || $this->ctx->can($user, $permission);
        if (! $hasPermission) {
            return ['allowed' => false, 'reason' => 'PERMISSION_DITOLAK:'.$permission, 'tool' => $tool, 'mask' => []];
        }

        $rule = HrAiToolPermission::query()->where('tool_code', $tool->code)
            ->when($agentCode, fn ($q) => $q->where(fn ($qq) => $qq->where('principal_reference', $agentCode)
                ->orWhere('principal_type', 'ROLE')))
            ->first();
        if ($rule && ! $rule->allowed) {
            return ['allowed' => false, 'reason' => 'ATURAN_ALAT_MENOLAK', 'tool' => $tool, 'mask' => (array) $rule->field_masking];
        }

        // Masking sadar-izin: kolom gaji tetap terlihat oleh pemegang izin payroll,
        // tetapi tertutup bagi yang tidak berhak.
        $mask = [];
        foreach ((array) config('hrd_phase3.ai.blocked_fields', []) as $field) {
            $required = (string) config('hrd_phase3.ai.field_permissions.'.$field, '');
            if ($required === '' || ! $this->ctx->can($user, $required)) {
                $mask[] = $field;
            }
        }
        if ($rule && is_array($rule->field_masking) && $rule->field_masking !== []) {
            foreach ($rule->field_masking as $field) {
                $required = (string) config('hrd_phase3.ai.field_permissions.'.$field, '');
                if ($required === '' || ! $this->ctx->can($user, $required)) {
                    $mask[] = $field;
                }
            }
            $mask = array_values(array_unique($mask));
        }

        return ['allowed' => true, 'reason' => null, 'tool' => $tool, 'mask' => $mask, 'scope' => $rule->scope ?? 'SELF'];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $toolCode, array $arguments, mixed $user, ?string $agentCode = null, int $runId = 0): array
    {
        $authorization = $this->authorize($toolCode, $user, $agentCode);
        if (! $authorization['allowed']) {
            $this->recordCall($runId, $toolCode, $arguments, [], 'DENIED', $authorization['reason']);

            return ['tool' => $toolCode, 'status' => 'DENIED', 'reason' => $authorization['reason'], 'data' => null];
        }

        $handlerName = (string) ($authorization['tool']->handler ?? $toolCode);
        $data = $this->handle($handlerName, $arguments, $user, $authorization);
        $maskedFields = $this->ctx->safe(fn (): array => array_intersect(array_keys($data ?? []), $authorization['mask']), []);
        $data = $this->mask((array) $data, $authorization['mask']);

        $this->recordCall($runId, $toolCode, $arguments, $data, 'ALLOWED', null);

        return [
            'tool' => $toolCode, 'status' => 'ALLOWED', 'data' => $data,
            'masked_fields' => array_values($maskedFields),
            'sources' => $authorization['tool']->output_schema['sources'] ?? [],
            'requires_confirmation' => (bool) $authorization['tool']->requires_confirmation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tools(): array
    {
        return [
            'registered' => HrAiTool::query()->orderBy('code')->get()
                ->map(fn (HrAiTool $tool): array => [
                    'code' => $tool->code, 'group' => $tool->tool_group,
                    'permission' => $tool->required_permission, 'sensitivity' => $tool->sensitivity,
                    'is_write' => (bool) $tool->is_write, 'requires_confirmation' => (bool) $tool->requires_confirmation,
                ])->all(),
            'permissions' => HrAiToolPermission::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(string $handler, array $arguments, mixed $user, array $authorization): array
    {
        $self = $this->ctx->employeeFor($user);
        $employeeId = (int) ($arguments['employee_id'] ?? $self?->getKey() ?? 0);
        $scope = $this->ctx->scopeOf($user, true);
        $mask = $authorization['mask'];

        return match ($handler) {
            'get_employee' => $this->employeePayload($employeeId, $mask),
            'get_team' => [
                'manager' => $self?->employee_number,
                'reports' => HrEmployee::query()->where('manager_employee_id', $self?->getKey())
                    ->where('is_active', true)->limit(50)->get(['id', 'employee_number', 'position_id', 'department_id'])
                    ->map(fn (HrEmployee $row): array => [
                        'employee_id' => $row->getKey(), 'employee_number' => $row->employee_number,
                        'position_id' => $row->position_id, 'department_id' => $row->department_id,
                    ])->all(),
            ],
            'get_leave_balance' => [
                'employee_id' => $employeeId,
                'balances' => $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_leave_balances')
                    ? DB::table('hr_leave_balances')->where('employee_id', $employeeId)->get()
                        ->map(fn ($row): array => ['type' => $row->leave_type_id ?? null, 'year' => $row->period_code ?? null,
                            'entitled' => (float) ($row->entitlement_days ?? 0), 'used' => (float) ($row->used_days ?? 0),
                            'pending' => (float) ($row->pending_days ?? 0), 'remaining' => (float) ($row->available_days ?? 0)])->all() : [], []),
                'source' => 'hr_leave_balances (Phase 2)',
            ],
            'get_attendance' => [
                'employee_id' => $employeeId, 'period' => $arguments['period'] ?? now()->format('Y-m'),
                'summary' => $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_attendance_records')
                    ? (array) DB::table('hr_attendance_records')->where('employee_id', $employeeId)
                        ->where('date', 'like', ($arguments['period'] ?? now()->format('Y-m')).'%')
                        ->selectRaw('count(*) as days, sum(case when status = "PRESENT" then 1 else 0 end) as present,
                            sum(case when status = "LATE" then 1 else 0 end) as late, sum(worked_minutes) as minutes')->first()
                    : [], ['note' => 'Modul presensi belum tersedia']),
            ],
            'get_payroll' => [
                'employee_id' => $employeeId, 'period' => $arguments['period'] ?? now()->format('Y-m'),
                'payroll' => $this->ctx->safe(fn (): ?array => ($row = DB::table('hr_payroll_items')->where('employee_id', $employeeId)
                    ->orderByDesc('id')->first()) ? (array) $row : null, null),
                'source' => 'hr_payroll_items (Phase 2)',
            ],
            'get_skills' => [
                'employee_id' => $employeeId,
                'skills' => HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->limit(50)->get()
                    ->map(fn (HrEmployeeSkill $row): array => [
                        'code' => $row->skill?->code, 'name' => $row->skill?->name,
                        'level' => $row->proficiency_level_code, 'verified' => (bool) $row->verified,
                    ])->all(),
            ],
            'get_training' => [
                'employee_id' => $employeeId,
                'completions' => $this->facts->trainingCompletions($employeeId),
                'participation_percent' => $this->facts->learningParticipation($employeeId),
            ],
            'get_position' => [
                'positions' => $this->ctx->safe(fn (): array => DB::table('hr_positions')
                    ->when(! empty($arguments['position_id']), fn ($q) => $q->where('id', $arguments['position_id']))
                    ->limit((int) ($arguments['limit'] ?? 20))->get(['id', 'code', 'title', 'department_id', 'authorized_headcount', 'filled_headcount', 'is_critical'])
                    ->map(fn ($row): array => (array) $row)->all(), []),
            ],
            'get_workforce_plan' => $this->ctx->safe(function () use ($arguments) {
                $plan = DB::table('hr_workforce_plans')->orderByDesc('id')->first();
                if (! $plan) {
                    return ['available' => false, 'note' => 'Belum ada rencana tenaga kerja.'];
                }
                $service = app(\App\Modules\Hrd\Strategic\Workforce\Services\WorkforcePlanningService::class);
                $model = \App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlan::query()->find($plan->id);
                $dashboard = $model ? $service->dashboard($model) : [];

                return ['available' => true, 'plan' => $plan->code, 'dashboard' => $dashboard,
                    'demand_required' => (int) DB::table('hr_workforce_demand')->where('plan_id', $plan->id)->sum('required_headcount')];
            }, ['available' => false]),
            'get_talent' => $this->ctx->safe(function () {
                $service = app(\App\Modules\Hrd\Strategic\Talent\Services\TalentIntelligenceService::class);

                return $service->segments($arguments['organization_id'] ?? null, (int) ($arguments['limit'] ?? 50));
            }, ['employees' => 0, 'by_segment' => []]),
            'get_succession' => $this->ctx->safe(function () use ($arguments) {
                $service = app(\App\Modules\Hrd\Strategic\Succession\Services\SuccessionService::class);

                return $service->coverage($arguments['organization_id'] ?? null);
            }, ['critical_positions' => 0, 'coverage_percent' => 0]),
            'get_metrics' => $this->metricPayload((array) ($arguments['metrics'] ?? ['HEADCOUNT']), $user),
            'get_workforce_analysis' => $this->ctx->safe(function () use ($arguments) {
                $service = app(\App\Modules\Hrd\Strategic\Academic\Services\AcademicWorkforceService::class);

                return $service->studentToWorkforceDemand($arguments);
            }, ['note' => 'Analisis tenaga kerja tidak tersedia']),
            'create_hr_request' => [
                'draft' => true, 'requires_confirmation' => true,
                'request' => [
                    'category' => $arguments['category'] ?? 'GENERAL',
                    'subject' => $arguments['subject'] ?? 'Permintaan dari AI Copilot',
                    'description' => $arguments['description'] ?? null,
                    'employee_id' => $employeeId,
                ],
                'note' => 'Draf dibuat; eksekusi menunggu konfirmasi dan workflow.',
            ],
            'create_training_request' => [
                'draft' => true, 'requires_confirmation' => true,
                'request' => ['employee_id' => $employeeId, 'training' => $arguments['training'] ?? null,
                    'reason' => $arguments['reason'] ?? null],
                'note' => 'Draf permintaan pelatihan; eksekusi lewat permintaan pelatihan Phase 2.',
            ],
            default => ['error' => 'Handler belum diimplementasikan: '.$handler],
        } + ['scope' => $scope['type']];
    }

    /**
     * @param  array<int, string>  $mask
     * @return array<string, mixed>
     */
    private function employeePayload(int $employeeId, array $mask): array
    {
        $employee = HrEmployee::query()->with(['position', 'department', 'organization'])->find($employeeId);
        if (! $employee) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'employee_id' => $employee->getKey(), 'employee_number' => $employee->employee_number,
            'position' => $employee->position?->title, 'department' => $employee->department?->name,
            'organization_id' => $employee->organization_id,
            'lifecycle_state' => $employee->lifecycle_state, 'worker_type' => $employee->worker_type,
            'hire_date' => $employee->hire_date?->toDateString(),
            'manager_employee_id' => $employee->manager_employee_id,
            'salary_amount' => $this->facts->monthlyPay($employeeId),
            'nik' => null,
        ];
    }

    /**
     * @param  array<int, string>  $metricCodes
     * @return array<string, mixed>
     */
    private function metricPayload(array $metricCodes, mixed $user): array
    {
        $rows = [];
        foreach ($metricCodes as $metricCode) {
            $rows[$metricCode] = $this->metrics->evaluate((string) $metricCode, [], null, $user);
        }

        return ['metrics' => $rows, 'source' => 'hr_metric_definitions (kanonik)'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function mask(array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[REDACTED]';
            }
            foreach ($payload as $key => $value) {
                if (is_array($value)) {
                    $payload[$key] = $this->mask($value, $fields);
                }
            }
        }

        return $payload;
    }

    private function recordCall(int $runId, string $toolCode, array $arguments, mixed $result, string $status, ?string $reason): void
    {
        if ($runId <= 0) {
            return;
        }

        $this->ctx->safe(fn () => \App\Modules\Hrd\Strategic\Ai\Models\HrAiToolCall::query()->create([
            'run_id' => $runId, 'tool_code' => strtolower($toolCode),
            'arguments' => $arguments, 'redacted_arguments' => $this->mask($arguments, (array) config('hrd_phase3.ai.blocked_fields', [])),
            'result' => is_array($result) ? $result : ['value' => $result],
            'status' => $status, 'denial_reason' => $reason,
            'permission_checked' => $status === 'ALLOWED' ? 'GRANTED' : 'DENIED',
            'called_at' => now(),
        ]));
    }
}
