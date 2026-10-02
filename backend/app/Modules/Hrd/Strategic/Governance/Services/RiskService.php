<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Governance\Models\HrRisk;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskAction;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskEvidence;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskHistory;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskSignal;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrAction;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionItem;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionResult;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionReview;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Succession\Services\SuccessionService;
use App\Modules\Hrd\Strategic\Workforce\Services\WorkforcePlanningService;
use Illuminate\Support\Facades\DB;

/**
 * HR Risk Register, Predictive Alert, dan Strategic Action Plan (§76–§78,
 * §124, §125, §146, §149). Insight tidak berhenti di dashboard: ia menjadi
 * risiko, pemilik, tenggat, workflow, eksekusi, dan pengukuran.
 */
class RiskService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
        private readonly SuccessionService $succession,
        private readonly WorkforcePlanningService $planning,
    ) {}

    /**
     * Pindai sembilan jenis sinyal risiko, masing-masing dengan bukti & periode.
     *
     * @return array<string, mixed>
     */
    public function scan(?int $organizationId = null, mixed $actor = null): array
    {
        $signals = [];
        $signals = array_merge($signals, $this->contractRisk($organizationId));
        $signals = array_merge($signals, $this->successionRisk($organizationId));
        $signals = array_merge($signals, $this->skillRisk($organizationId));
        $signals = array_merge($signals, $this->shortageRisk($organizationId));
        $signals = array_merge($signals, $this->attritionRisk($organizationId));
        $signals = array_merge($signals, $this->overtimeRisk($organizationId));
        $signals = array_merge($signals, $this->payrollRisk($organizationId));
        $signals = array_merge($signals, $this->certificationRisk($organizationId));
        $signals = array_merge($signals, $this->trainingComplianceRisk($organizationId));

        $bySeverity = collect($signals)->groupBy('severity')->map->count()->all();
        $this->ctx->audit('strategic.risk.scanned', HrRisk::class, null, [
            'signals' => count($signals), 'severity' => $bySeverity, 'organization_id' => $organizationId,
        ], null, $actor);

        return ['signals' => $signals, 'count' => count($signals), 'by_severity' => $bySeverity, 'scanned_at' => now()->toIso8601String()];
    }

    /**
     * Insight/risiko → rencana aksi strategis dengan workflow (§78).
     *
     * @return array<string, mixed>
     */
    public function createAction(array $data, mixed $actor = null): array
    {
        $action = HrStrategicHrAction::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_strategic_hr_actions', 'ACT'),
            'title' => $data['title'],
            'objective' => $data['objective'] ?? null,
            'source_type' => strtoupper((string) ($data['source_type'] ?? 'MANUAL')),
            'source_id' => $data['source_id'] ?? null,
            'linked_risk_id' => $data['risk_id'] ?? null,
            'linked_recommendation_id' => $data['recommendation_id'] ?? null,
            'workforce_requirement' => $data['workforce_requirement'] ?? null,
            'skill_requirement' => $data['skill_requirement'] ?? null,
            'talent_strategy' => $data['talent_strategy'] ?? null,
            'owner_employee_id' => $data['owner_employee_id'] ?? $this->ctx->employeeFor($actor)?->getKey(),
            'owner_role' => $data['owner_role'] ?? 'HR_MANAGER',
            'owner_organization_id' => $data['owner_organization_id'] ?? null,
            'priority' => strtoupper((string) ($data['priority'] ?? 'MEDIUM')),
            'status' => 'DRAFT',
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? now()->addMonths(3)->toDateString(),
            'budget_amount' => $data['budget_amount'] ?? null,
            'measurement' => $data['measurement'] ?? [],
            'approval_status' => 'PENDING',
        ]);

        foreach ((array) ($data['items'] ?? []) as $index => $item) {
            HrStrategicHrActionItem::query()->create([
                'action_id' => $action->getKey(), 'sequence' => (int) ($item['sequence'] ?? $index + 1),
                'title' => $item['title'], 'item_type' => strtoupper((string) ($item['type'] ?? 'TASK')),
                'owner_employee_id' => $item['owner_employee_id'] ?? null,
                'due_date' => $item['due_date'] ?? null, 'status' => 'PLANNED',
                'progress_percent' => 0, 'notes' => $item['notes'] ?? null,
            ]);
        }

        // Jalur approval memakai Workflow Engine yang sama dengan Phase 1/2.
        $workflow = $this->ctx->safe(function () use ($action, $actor): ?string {
            $service = app(\App\Modules\Hrd\Application\Services\HrdWorkflowService::class);
            if (! method_exists($service, 'isEngineAvailable') || ! $service->isEngineAvailable()) {
                return null;
            }

            $result = $service->startApproval('hrd3.strategic_action', HrStrategicHrAction::class, (int) $action->getKey(), [
                'title' => $action->title, 'priority' => $action->priority, 'budget' => $action->budget_amount,
            ], $actor);

            return $result['instance_id'] ?? $result['reference'] ?? null;
        }, null);

        $action->forceFill(['workflow_reference' => $workflow])->save();

        $this->ctx->audit('strategic.action.created', HrStrategicHrAction::class, $action->getKey(), [
            'title' => $action->title, 'source' => $action->source_type, 'items' => count((array) ($data['items'] ?? [])),
            'workflow' => $workflow,
        ], null, $actor);
        $this->ctx->event('STRATEGIC_ACTION', 'StrategicActionCreated', [
            'title' => 'Rencana aksi strategis dibuat', 'description' => $action->title,
        ], null, HrStrategicHrAction::class, $action->getKey(), $actor);

        return [
            'action' => $action->code, 'status' => $action->status, 'workflow_reference' => $workflow,
            'items' => HrStrategicHrActionItem::query()->where('action_id', $action->getKey())->count(),
        ];
    }

    public function approveAction(HrStrategicHrAction $action, mixed $actor = null, ?string $decision = 'APPROVED'): HrStrategicHrAction
    {
        $action->forceFill([
            'approval_status' => strtoupper((string) $decision),
            'status' => strtoupper((string) $decision) === 'APPROVED' ? 'IN_PROGRESS' : 'DRAFT',
            'approved_by' => $this->ctx->employeeFor($actor)?->getKey(), 'approved_at' => now(),
        ])->save();

        $this->ctx->audit('strategic.action.decided', HrStrategicHrAction::class, $action->getKey(), [
            'decision' => $decision,
        ], null, $actor);

        return $action->refresh();
    }

    /**
     * Ukur hasil aksi terhadap metrik baseline & target (§146).
     *
     * @return array<string, mixed>
     */
    public function measure(HrStrategicHrAction $action, array $measurements, mixed $actor = null): array
    {
        $rows = [];
        foreach ($measurements as $measurement) {
            $baseline = (float) ($measurement['baseline'] ?? 0);
            $target = (float) ($measurement['target'] ?? 0);
            $actual = (float) ($measurement['actual'] ?? 0);
            $rows[] = HrStrategicHrActionResult::query()->create([
                'action_id' => $action->getKey(), 'metric_code' => strtoupper((string) $measurement['metric_code']),
                'baseline_value' => $baseline, 'target_value' => $target, 'actual_value' => $actual,
                'variance' => $this->ctx->money($actual - $target), 'period' => $measurement['period'] ?? now()->format('Y-m'),
                'evidence' => $measurement, 'measured_at' => now(), 'notes' => $measurement['notes'] ?? null,
            ]);
        }

        $onTrack = collect($rows)->every(fn (HrStrategicHrActionResult $row): bool => (float) $row->variance >= 0);
        $action->forceFill(['status' => $onTrack ? 'DONE' : 'IN_PROGRESS', 'measurement' => array_merge((array) $action->measurement, [
            'latest' => collect($rows)->map(fn (HrStrategicHrActionResult $row): array => [
                'metric' => $row->metric_code, 'actual' => (float) $row->actual_value, 'target' => (float) $row->target_value,
            ])->all(),
            'on_track' => $onTrack,
        ])])->save();

        $this->ctx->audit('strategic.action.measured', HrStrategicHrAction::class, $action->getKey(), [
            'metrics' => count($rows), 'on_track' => $onTrack,
        ], null, $actor);

        return ['action' => $action->code, 'results' => $rows, 'on_track' => $onTrack];
    }

    public function review(HrStrategicHrAction $action, array $data, mixed $actor = null): HrStrategicHrActionReview
    {
        $review = HrStrategicHrActionReview::query()->create([
            'action_id' => $action->getKey(), 'review_date' => $data['review_date'] ?? now()->toDateString(),
            'reviewer_employee_id' => $this->ctx->employeeFor($actor)?->getKey(),
            'progress_note' => $data['progress_note'] ?? null, 'blockers' => $data['blockers'] ?? [],
            'decisions' => $data['decisions'] ?? [], 'next_review_date' => $data['next_review_date'] ?? now()->addMonth()->toDateString(),
            'status' => $data['status'] ?? 'ON_TRACK',
        ]);

        $this->ctx->audit('strategic.action.reviewed', HrStrategicHrAction::class, $action->getKey(), [
            'status' => $review->status, 'next_review' => $review->next_review_date?->toDateString(),
        ], null, $actor);

        return $review;
    }

    /**
     * Risk register ringkas (§77).
     *
     * @return array<string, mixed>
     */
    public function register(?int $organizationId = null, int $limit = 50): array
    {
        $risks = HrRisk::query()
            ->when($organizationId, fn ($q, $org) => $q->where('scope_organization_id', $org))
            ->orderByDesc('risk_score')->limit($limit)->get();

        return [
            'total' => $risks->count(),
            'by_type' => $risks->groupBy('risk_type')->map->count()->all(),
            'by_severity' => $risks->groupBy('severity')->map->count()->all(),
            'rows' => $risks->map(fn (HrRisk $risk): array => [
                'code' => $risk->code, 'title' => $risk->title, 'type' => $risk->risk_type,
                'severity' => $risk->severity, 'risk_score' => (float) $risk->risk_score, 'status' => $risk->status,
                'owner_employee_id' => $risk->owner_employee_id,
                'signals' => HrRiskSignal::query()->where('risk_id', $risk->getKey())->count(),
                'actions' => HrRiskAction::query()->where('risk_id', $risk->getKey())->count(),
            ])->all(),
        ];
    }

    /**
     * Strategic Alert Center (§124) + HR Command Center (§125).
     *
     * @return array<string, mixed>
     */
    public function commandCenter(?int $organizationId = null, mixed $user = null): array
    {
        $signals = $this->scan($organizationId, $user)['signals'];
        $coverage = $this->succession->coverage($organizationId);
        $positions = $this->planning->optimisePositions($organizationId);
        $retention = $this->ctx->safe(fn () => DB::table('hr_retention_signals')->where('signal_level', 'HIGH')->count(), 0);
        $actions = HrStrategicHrAction::query()->orderByDesc('id')->limit(10)->get();

        return [
            'workforce' => [
                'headcount' => (int) HrEmployee::query()->where('is_active', true)->count(),
                'open_positions' => (int) $positions['summary']['vacant'],
                'critical_positions' => (int) $coverage['critical_positions'],
                'succession_coverage_percent' => (float) $coverage['coverage_percent'],
            ],
            'talent' => ['critical_without_successor' => (int) $coverage['without_successor'], 'ready_now' => (int) ($coverage['readiness_distribution']['READY_NOW'] ?? 0)],
            'risk' => ['signals' => count($signals), 'by_severity' => collect($signals)->groupBy('severity')->map->count()->all(),
                'high_retention_signals' => (int) $retention],
            'actions' => $actions->map(fn (HrStrategicHrAction $action): array => [
                'code' => $action->code, 'title' => $action->title, 'status' => $action->status,
                'priority' => $action->priority, 'due_date' => $action->due_date?->toDateString(),
            ])->all(),
            'alerts' => array_slice(array_values(array_filter($signals, fn (array $row): bool => in_array($row['severity'], ['HIGH', 'CRITICAL'], true))), 0, 10),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contractRisk(?int $organizationId): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_contracts')
            ? DB::table('hr_contracts')->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
                ->limit(20)->get(['id', 'employee_id', 'end_date'])->map(fn ($row): array => (array) $row)->all() : [], []);

        return array_map(fn (array $row): array => $this->recordSignal([
            'type' => 'CONTRACT_RISK', 'severity' => 'HIGH', 'employee_id' => (int) ($row['employee_id'] ?? 0) ?: null,
            'evidence' => ['contract_id' => $row['id'] ?? null, 'end_date' => $row['end_date'] ?? null],
            'value' => 1, 'threshold' => 1,
            'action' => 'Proses perpanjangan/pengakhiran kontrak sebelum tanggal berakhir.',
        ], 'contract:'.($row['id'] ?? ''), $organizationId), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function successionRisk(?int $organizationId): array
    {
        $coverage = $this->succession->coverage($organizationId);
        $rows = [];
        foreach ($coverage['rows'] as $row) {
            if ($row['coverage'] !== 'NO_SUCCESSOR') {
                continue;
            }
            $rows[] = $this->recordSignal([
                'type' => 'SUCCESSION_RISK', 'severity' => 'CRITICAL', 'value' => 0, 'threshold' => 1,
                'evidence' => $row, 'action' => 'Bentuk rencana suksesi dan kandidat cadangan.',
            ], 'succession:'.$row['critical_position_id'], $organizationId);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function skillRisk(?int $organizationId): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_skill_forecasts')
            ? DB::table('hr_skill_forecasts')->where('gap', '>', 0)->orderByDesc('gap')->limit(5)->get()->map(fn ($row): array => (array) $row)->all() : [], []);

        return array_map(fn (array $row): array => $this->recordSignal([
            'type' => 'SKILL_RISK', 'severity' => ((float) ($row['gap'] ?? 0) > 5) ? 'HIGH' : 'MEDIUM',
            'value' => (float) ($row['gap'] ?? 0), 'threshold' => 0,
            'evidence' => ['skill' => $row['skill_code'] ?? null, 'supply' => $row['current_supply'] ?? null, 'demand' => $row['future_demand'] ?? null],
            'action' => 'Tutup gap keterampilan lewat pelatihan, rekrutmen, atau kontraktor.',
        ], 'skill:'.($row['skill_code'] ?? ''), $organizationId), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shortageRisk(?int $organizationId): array
    {
        $positions = $this->planning->optimisePositions($organizationId);
        $rows = [];
        foreach (array_slice($positions['vacant'], 0, 5) as $vacant) {
            $rows[] = $this->recordSignal([
                'type' => 'WORKFORCE_SHORTAGE', 'severity' => 'HIGH', 'value' => (float) ($vacant['authorized'] ?? 0), 'threshold' => 1,
                'evidence' => $vacant, 'action' => 'Prioritaskan pengisian posisi kosong sesuai rencana tenaga kerja.',
            ], 'shortage:'.($vacant['position_id'] ?? ''), $organizationId);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attritionRisk(?int $organizationId): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_retention_signals')
            ? DB::table('hr_retention_signals')->where('signal_level', 'HIGH')->limit(5)->get(['employee_id', 'retention_score', 'factors'])->map(fn ($row): array => (array) $row)->all() : [], []);

        return array_map(fn (array $row): array => $this->recordSignal([
            'type' => 'ATTRITION_SIGNAL', 'severity' => 'MEDIUM', 'employee_id' => (int) ($row['employee_id'] ?? 0) ?: null,
            'value' => (float) ($row['retention_score'] ?? 0), 'threshold' => 50,
            'evidence' => ['factors' => json_decode((string) ($row['factors'] ?? '[]'), true)],
            'action' => 'Lakukan percakapan retensi & tinjau jalur pengembangan.',
        ], 'attrition:'.($row['employee_id'] ?? ''), $organizationId), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overtimeRisk(?int $organizationId): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_overtime_records')
            ? DB::table('hr_overtime_records')->where('date', '>=', now()->subMonth()->toDateString())
                ->selectRaw('employee_id, sum(verified_minutes) as minutes')->groupBy('employee_id')
                ->havingRaw('sum(verified_minutes) > 1200')->limit(10)->get()->map(fn ($row): array => (array) $row)->all() : [], []);

        return array_map(fn (array $row): array => $this->recordSignal([
            'type' => 'OVERTIME_ANOMALY', 'severity' => 'MEDIUM', 'employee_id' => (int) ($row['employee_id'] ?? 0) ?: null,
            'value' => (float) ($row['minutes'] ?? 0), 'threshold' => 1200,
            'evidence' => ['verified_minutes' => $row['minutes'] ?? 0],
            'action' => 'Tinjau beban kerja dan pembagian tugas.',
        ], 'overtime:'.($row['employee_id'] ?? ''), $organizationId), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payrollRisk(?int $organizationId): array
    {
        return $this->ctx->safe(function () use ($organizationId): array {
            if (! $this->ctx->hasTable('hr_payroll_items') || ! $this->ctx->hasTable('hr_payroll_runs')) {
                return [];
            }
            $latestRun = DB::table('hr_payroll_runs')->orderByDesc('id')->first();
            if (! $latestRun) {
                return [];
            }
            $current = (float) DB::table('hr_payroll_items')->where('payroll_run_id', $latestRun->id)->sum('total_amount');
            $previousRunId = DB::table('hr_payroll_runs')->where('id', '<', $latestRun->id)->orderByDesc('id')->value('id');
            $previous = $previousRunId ? (float) DB::table('hr_payroll_items')->where('payroll_run_id', $previousRunId)->sum('total_amount') : 0.0;
            $variance = $previous > 0 ? (($current - $previous) / $previous) * 100 : 0.0;

            if (abs($variance) < 10) {
                return [];
            }

            return [$this->recordSignal([
                'type' => 'PAYROLL_ANOMALY', 'severity' => abs($variance) > 25 ? 'HIGH' : 'MEDIUM',
                'value' => round($variance, 2), 'threshold' => 10,
                'evidence' => ['current_total' => $current, 'previous_total' => $previous, 'variance_percent' => round($variance, 2)],
                'action' => 'Rekonsiliasi payroll periode ini (payroll Phase 2 menyediakan laporan varians).',
            ], 'payroll-variance:'.$latestRun->id, $organizationId)];
        }, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function certificationRisk(?int $organizationId): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_employee_certifications')
            ? DB::table('hr_employee_certifications')->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(90)->toDateString()])
                ->limit(10)->get(['id', 'employee_id', 'name', 'expiry_date'])->map(fn ($row): array => (array) $row)->all() : [], []);

        return array_map(fn (array $row): array => $this->recordSignal([
            'type' => 'CERTIFICATION_RISK', 'severity' => 'MEDIUM', 'employee_id' => (int) ($row['employee_id'] ?? 0) ?: null,
            'value' => 1, 'threshold' => 1,
            'evidence' => ['certification' => $row['name'] ?? null, 'expiry_date' => $row['expiry_date'] ?? null],
            'action' => 'Jadwalkan perpanjangan sertifikasi sebelum kedaluwarsa.',
        ], 'certification:'.($row['id'] ?? ''), $organizationId), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trainingComplianceRisk(?int $organizationId): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_training_requirements')
            ? DB::table('hr_training_requirements as r')
                ->join('hr_training_assignments as a', 'a.training_id', '=', 'r.training_id')
                ->whereIn('a.status', ['ASSIGNED', 'PENDING', 'OVERDUE'])
                ->where('r.is_mandatory', true)
                ->selectRaw('a.employee_id as employee_id, count(*) as overdue')
                ->groupBy('a.employee_id')->havingRaw('count(*) > 0')->limit(10)->get()->map(fn ($row): array => (array) $row)->all() : [], []);

        return array_map(fn (array $row): array => $this->recordSignal([
            'type' => 'TRAINING_COMPLIANCE_RISK', 'severity' => 'MEDIUM', 'employee_id' => (int) ($row['employee_id'] ?? 0) ?: null,
            'value' => (float) ($row['overdue'] ?? 0), 'threshold' => 0,
            'evidence' => ['mandatory_trainings_pending' => $row['overdue'] ?? 0],
            'action' => 'Ingatkan pegawai & atasan; jadwalkan ulang pelatihan wajib.',
        ], 'training-compliance:'.($row['employee_id'] ?? ''), $organizationId), $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recordSignal(array $data, string $dedupeKey, ?int $organizationId): array
    {
        $type = strtoupper((string) $data['type']);
        $risk = HrRisk::query()->firstOrCreate(
            ['code' => 'RSK-'.$type],
            [
                'title' => $this->riskTitle($type), 'risk_type' => $this->riskType($type),
                'category' => 'PREDICTIVE', 'description' => $this->riskTitle($type),
                'scope_organization_id' => $organizationId,
                'severity' => strtoupper((string) $data['severity']), 'likelihood' => 'MEDIUM',
                'impact_score' => $this->severityScore((string) $data['severity']),
                'risk_score' => $this->severityScore((string) $data['severity']) * 0.8,
                'period' => now()->format('Y-m'), 'status' => 'OPEN', 'source' => 'ALERT', 'detected_at' => now(),
            ]
        );

        $signal = HrRiskSignal::query()->updateOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'risk_id' => $risk->getKey(), 'code' => 'SIG-'.strtoupper(substr(md5($dedupeKey), 0, 8)),
                'signal_type' => $type, 'severity' => strtoupper((string) $data['severity']),
                'signal_value' => (float) ($data['value'] ?? 0), 'threshold' => (float) ($data['threshold'] ?? 0),
                'evidence' => $data['evidence'] ?? [], 'period' => now()->format('Y-m'),
                'employee_id' => $data['employee_id'] ?? null, 'organization_id' => $organizationId,
                'recommended_action' => $data['action'] ?? null, 'detected_at' => now(), 'status' => 'OPEN',
            ]
        );

        HrRiskEvidence::query()->create([
            'risk_id' => $risk->getKey(), 'signal_id' => $signal->getKey(), 'evidence_type' => 'METRIC',
            'reference' => $dedupeKey, 'value' => (float) ($data['value'] ?? 0),
            'period' => now()->format('Y-m'), 'payload' => $data['evidence'] ?? [], 'collected_at' => now(),
        ]);

        $this->ctx->safe(function () use ($type, $signal): void {
            $service = app(\App\Modules\Hrd\Core\Services\AlertService::class);
            if (method_exists($service, 'raise')) {
                $service->raise('hrd3.risk.'.strtolower($type), [
                    'severity' => $signal->severity,
                    'title' => (string) $signal->signal_type,
                    'message' => (string) $signal->recommended_action,
                    'employee_id' => $signal->employee_id,
                    'dedupe_key' => $signal->dedupe_key,
                    'payload' => (array) $signal->evidence,
                ]);
            }
        });

        return [
            'signal' => $type, 'severity' => $signal->severity, 'employee_id' => $signal->employee_id,
            'value' => (float) $signal->signal_value, 'period' => $signal->period,
            'evidence' => $signal->evidence, 'recommended_action' => $signal->recommended_action,
            'risk_code' => $risk->code,
        ];
    }

    private function riskTitle(string $type): string
    {
        return match ($type) {
            'CONTRACT_RISK' => 'Kontrak pegawai akan berakhir',
            'SUCCESSION_RISK' => 'Posisi kritis tanpa kandidat suksesi',
            'SKILL_RISK' => 'Gap keterampilan terhadap permintaan',
            'WORKFORCE_SHORTAGE' => 'Kekurangan tenaga kerja pada posisi',
            'ATTRITION_SIGNAL' => 'Sinyal attrition pegawai',
            'OVERTIME_ANOMALY' => 'Lembur di luar kebiasaan',
            'PAYROLL_ANOMALY' => 'Varians biaya payroll di luar ambang',
            'CERTIFICATION_RISK' => 'Sertifikasi mendekati kedaluwarsa',
            'TRAINING_COMPLIANCE_RISK' => 'Pelatihan wajib belum selesai',
            default => 'Risiko HR',
        };
    }

    private function riskType(string $type): string
    {
        return match ($type) {
            'SUCCESSION_RISK', 'SKILL_RISK' => 'TALENT',
            'ATTRITION_SIGNAL' => 'RETENTION',
            'WORKFORCE_SHORTAGE' => 'WORKFORCE',
            'PAYROLL_ANOMALY' => 'COST',
            'OVERTIME_ANOMALY' => 'ATTENDANCE',
            'CERTIFICATION_RISK', 'TRAINING_COMPLIANCE_RISK' => 'COMPLIANCE',
            'CONTRACT_RISK' => 'COMPLIANCE',
            default => 'OPERATIONAL',
        };
    }

    private function severityScore(string $severity): float
    {
        return match (strtoupper($severity)) {
            'CRITICAL' => 9.0, 'HIGH' => 7.0, 'MEDIUM' => 5.0, default => 3.0,
        };
    }
}
