<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Skills\Services\SkillsIntelligenceService;
use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrLeadershipPipeline;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionCandidate;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionReadiness;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionRisk;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionScenario;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Talent\Services\TalentIntelligenceService;
use Illuminate\Support\Facades\DB;

/**
 * Succession Planning (§33–§38, §93, §94): posisi kritis → kandidat → kesiapan
 * → pengembangan → rencana suksesi → risiko & cakupan.
 * Readiness selalu disertai bukti, tidak pernah berupa satu angka tanpa alasan.
 */
class SuccessionService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly TalentIntelligenceService $talent,
        private readonly SkillsIntelligenceService $skills,
    ) {}

    public function flagCriticalPosition(int $positionId, array $data = [], mixed $actor = null): HrCriticalPosition
    {
        $incumbent = HrEmployee::query()->where('position_id', $positionId)->where('is_active', true)->first();
        $row = HrCriticalPosition::query()->updateOrCreate(
            ['position_id' => $positionId],
            [
                'criticality' => strtoupper((string) ($data['criticality'] ?? 'CRITICAL')),
                'business_impact' => $data['business_impact'] ?? null,
                'replacement_difficulty' => strtoupper((string) ($data['replacement_difficulty'] ?? 'HIGH')),
                'succession_required' => (bool) ($data['succession_required'] ?? true),
                'min_successors' => (int) ($data['min_successors'] ?? $this->ctx->config('succession.min_successors', 2)),
                'incumbent_employee_id' => $incumbent?->getKey(),
                'retirement_risk' => $this->retirementRisk($incumbent),
                'turnover_risk' => $this->turnoverRisk($incumbent),
                'status' => 'ACTIVE',
                'flagged_by' => $this->ctx->employeeFor($actor)?->getKey(),
                'flagged_at' => now(),
            ]
        );

        $this->ctx->audit('strategic.critical_position.flagged', HrCriticalPosition::class, $row->getKey(), [
            'position_id' => $positionId, 'criticality' => $row->criticality,
        ], null, $actor);

        return $row->refresh();
    }

    public function plan(HrCriticalPosition $criticalPosition, array $data = [], mixed $actor = null): HrSuccessionPlan
    {
        $plan = HrSuccessionPlan::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_succession_plans', 'SUC'),
            'critical_position_id' => $criticalPosition->getKey(),
            'position_id' => $criticalPosition->position_id,
            'organization_id' => HrPosition::query()->whereKey($criticalPosition->position_id)->value('organization_id'),
            'plan_type' => strtoupper((string) ($data['plan_type'] ?? 'REPLACEMENT')),
            'status' => $data['status'] ?? 'ACTIVE',
            'coverage_status' => 'NO_SUCCESSOR',
            'horizon_months' => (int) ($data['horizon_months'] ?? 24),
            'owner_employee_id' => $data['owner_employee_id'] ?? $this->ctx->employeeFor($actor)?->getKey(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->ctx->audit('strategic.succession_plan.created', HrSuccessionPlan::class, $plan->getKey(), [
            'critical_position_id' => $criticalPosition->getKey(), 'type' => $plan->plan_type,
        ], null, $actor);

        return $plan;
    }

    /**
     * Nominasi kandidat + penilaian kesiapan berbasis bukti (§35, §36).
     */
    public function nominate(HrSuccessionPlan $plan, int $employeeId, string $candidateType = 'SECONDARY', mixed $actor = null): HrSuccessionCandidate
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $gap = $this->skills->gapForEmployee($employeeId, 'POSITION', (int) $plan->position_id);
        $readiness = $this->talent->readiness($employeeId, (int) $plan->position_id);

        $candidate = HrSuccessionCandidate::query()->updateOrCreate(
            ['plan_id' => $plan->getKey(), 'employee_id' => $employeeId],
            [
                'candidate_type' => strtoupper($candidateType),
                'readiness' => $readiness['level'],
                'readiness_score' => $readiness['score'],
                'skill_gap_snapshot' => $gap['gaps'],
                'evidence' => $readiness['evidence'],
                'development_plan' => array_map(fn (array $row): array => [
                    'skill' => $row['skill_code'], 'priority' => $row['priority'], 'gap' => $row['gap'],
                ], array_slice($gap['gaps'], 0, 6)),
                'impact_of_loss' => $employee->position_id === $plan->position_id ? 'HIGH' : 'MEDIUM',
                'risk_of_loss' => $this->turnoverRisk($employee),
                'status' => 'ACTIVE',
                'reviewed_at' => now(),
            ]
        );

        $this->assessReadiness($candidate, $readiness);
        $this->refreshCoverage($plan->refresh());

        $this->ctx->audit('strategic.succession_candidate.nominated', HrSuccessionCandidate::class, $candidate->getKey(), [
            'plan_id' => $plan->getKey(), 'employee_id' => $employeeId,
            'readiness' => $candidate->readiness, 'score' => (float) $candidate->readiness_score,
        ], null, $actor, $employeeId);

        return $candidate->refresh();
    }

    /**
     * @param  array<string, mixed>  $readiness
     */
    public function assessReadiness(HrSuccessionCandidate $candidate, array $readiness): HrSuccessionReadiness
    {
        $components = (array) ($readiness['components'] ?? []);

        return HrSuccessionReadiness::query()->updateOrCreate(
            ['candidate_id' => $candidate->getKey()],
            [
                'plan_id' => $candidate->plan_id,
                'employee_id' => $candidate->employee_id,
                'skills_score' => $components['skills'] ?? 0, 'performance_score' => $components['performance'] ?? 0,
                'competency_score' => $components['competency'] ?? 0, 'experience_score' => $components['experience'] ?? 0,
                'aspiration_score' => $components['aspiration'] ?? 0, 'development_score' => $components['development'] ?? 0,
                'certification_score' => $components['certification'] ?? 0,
                'leadership_score' => $this->leadershipScore((int) $candidate->employee_id),
                'readiness_score' => $readiness['score'] ?? 0,
                'readiness_level' => $readiness['level'] ?? 'NOT_READY',
                'evidence' => $readiness['evidence'] ?? [],
                'methodology' => 'Bobot konfigurasi + bukti keterampilan/kinerja/pengembangan',
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * Risiko suksesi per posisi kritis (§37).
     *
     * @return array<int, HrSuccessionRisk>
     */
    public function assessRisks(HrSuccessionPlan $plan, mixed $actor = null): array
    {
        $critical = HrCriticalPosition::query()->find($plan->critical_position_id);
        $candidates = HrSuccessionCandidate::query()->where('plan_id', $plan->getKey())->get();
        $minSuccessors = (int) ($critical->min_successors ?? 2);
        $positions = $this->skills->gapForEmployee((int) ($critical->incumbent_employee_id ?? 0), 'POSITION', (int) $plan->position_id);
        $rows = [];

        $add = function (string $type, string $severity, array $evidence, string $action) use ($plan, $candidates, &$rows): void {
            $rows[] = HrSuccessionRisk::query()->updateOrCreate(
                ['plan_id' => $plan->getKey(), 'risk_type' => $type],
                [
                    'critical_position_id' => $plan->critical_position_id, 'severity' => $severity,
                    'successor_count' => $candidates->count(),
                    'readiness_summary' => $candidates->groupBy('readiness')->map->count()->all(),
                    'evidence' => $evidence, 'recommended_action' => $action,
                    'detected_at' => now(), 'status' => 'OPEN',
                ]
            );
        };

        if ($candidates->count() === 0) {
            $add('NO_SUCCESSOR', 'CRITICAL', ['successors' => 0], 'Buka pencarian kandidat & mulai pengembangan internal');
        } elseif ($candidates->count() < $minSuccessors) {
            $add('ONE_SUCCESSOR', 'HIGH', ['successors' => $candidates->count(), 'minimum' => $minSuccessors], 'Tambah kandidat cadangan');
        }
        if ($candidates->count() > 0 && $candidates->where('readiness', 'READY_NOW')->isEmpty()) {
            $add('WEAK_SUCCESSOR_POOL', 'MEDIUM', ['ready_now' => 0, 'total' => $candidates->count()], 'Percepat rencana pengembangan kandidat');
        }
        if (($positions['summary']['total_gap_levels'] ?? 0) > 3) {
            $add('CRITICAL_SKILL_GAP', 'HIGH', $positions['summary'], 'Tutup gap keterampilan kritis posisi ini');
        }
        if (($critical->retirement_risk ?? 'LOW') === 'HIGH') {
            $add('HIGH_RETIREMENT_RISK', 'HIGH', ['incumbent' => $critical->incumbent_employee_id], 'Siapkan transisi terencana');
        }
        if (($critical->turnover_risk ?? 'LOW') === 'HIGH') {
            $add('HIGH_TURNOVER_RISK', 'HIGH', ['incumbent' => $critical->incumbent_employee_id], 'Lakukan intervensi retensi');
        }

        $this->ctx->audit('strategic.succession_risk.assessed', HrSuccessionPlan::class, $plan->getKey(), [
            'risks' => count($rows), 'severity' => collect($rows)->pluck('severity')->countBy()->all(),
        ], null, $actor);

        return $rows;
    }

    /**
     * Skenario: incumbent keluar → kandidat & gap (§38). Tidak mengubah assignment.
     *
     * @return array<string, mixed>
     */
    public function scenario(HrCriticalPosition $criticalPosition, string $departureType = 'RESIGN', mixed $actor = null): array
    {
        $plan = HrSuccessionPlan::query()->where('critical_position_id', $criticalPosition->getKey())->orderByDesc('id')->first();
        $candidates = $plan ? HrSuccessionCandidate::query()->where('plan_id', $plan->getKey())->get() : collect();
        $rows = [];

        foreach ($candidates as $candidate) {
            $readiness = $this->talent->readiness((int) $candidate->employee_id, (int) $criticalPosition->position_id);
            $gap = $this->skills->gapForEmployee((int) $candidate->employee_id, 'POSITION', (int) $criticalPosition->position_id);
            $rows[] = [
                'employee_id' => (int) $candidate->employee_id,
                'candidate_type' => $candidate->candidate_type,
                'readiness' => $readiness['level'], 'readiness_score' => $readiness['score'],
                'skill_gap' => array_map(fn (array $row): array => ['skill' => $row['skill_code'], 'gap' => $row['gap']], array_slice($gap['gaps'], 0, 8)),
                'development_required' => $readiness['development'],
                'evidence' => $readiness['evidence'],
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['readiness_score'] <=> $a['readiness_score']);

        $scenario = HrSuccessionScenario::query()->create([
            'code' => $this->ctx->nextCode('hr_succession_scenarios', 'SSC'),
            'critical_position_id' => $criticalPosition->getKey(),
            'name' => 'Skenario '.strtoupper($departureType).' — posisi #'.$criticalPosition->position_id,
            'departure_type' => strtoupper($departureType),
            'results' => ['candidates' => $rows, 'coverage' => $this->coverageStatus($criticalPosition, $rows)],
            'best_candidate_employee_id' => $rows[0]['employee_id'] ?? null,
            'gaps' => $rows[0]['skill_gap'] ?? [],
            'development_required' => $rows[0]['development_required'] ?? [],
            'created_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'simulated_at' => now(),
        ]);

        $this->ctx->audit('strategic.succession_scenario.simulated', HrSuccessionScenario::class, $scenario->getKey(), [
            'departure_type' => $scenario->departure_type, 'candidates' => count($rows),
        ], null, $actor);

        return [
            'scenario_code' => $scenario->code,
            'candidates' => $rows,
            'coverage' => $this->coverageStatus($criticalPosition, $rows),
            'note' => 'Simulasi tidak mengubah penugasan aktual.',
        ];
    }

    /**
     * Cakupan suksesi (§93).
     *
     * @return array<string, mixed>
     */
    public function coverage(?int $organizationId = null): array
    {
        $criticalPositions = HrCriticalPosition::query()->where('status', 'ACTIVE')
            ->when($organizationId, fn ($q) => $q->whereIn('position_id', HrPosition::query()
                ->where('organization_id', $organizationId)->select('id')))->get();

        $withSuccessor = 0;
        $readinessCounts = ['READY_NOW' => 0, 'READY_LT_1Y' => 0, 'READY_1_2Y' => 0, 'READY_3Y_PLUS' => 0, 'NOT_READY' => 0];
        $rows = [];

        foreach ($criticalPositions as $critical) {
            $plan = HrSuccessionPlan::query()->where('critical_position_id', $critical->getKey())->orderByDesc('id')->first();
            $candidates = $plan ? HrSuccessionCandidate::query()->where('plan_id', $plan->getKey())->get() : collect();
            if ($candidates->isNotEmpty()) {
                $withSuccessor++;
            }
            foreach ($candidates as $candidate) {
                $readinessCounts[$candidate->readiness] = ($readinessCounts[$candidate->readiness] ?? 0) + 1;
            }
            $rows[] = [
                'critical_position_id' => $critical->getKey(), 'position_id' => $critical->position_id,
                'criticality' => $critical->criticality, 'successors' => $candidates->count(),
                'ready_now' => $candidates->where('readiness', 'READY_NOW')->count(),
                'coverage' => $candidates->isEmpty() ? 'NO_SUCCESSOR' : ($candidates->where('readiness', 'READY_NOW')->isNotEmpty() ? 'READY_NOW' : 'DEVELOPING'),
                'incumbent_employee_id' => $critical->incumbent_employee_id,
            ];
        }

        return [
            'critical_positions' => $criticalPositions->count(),
            'with_successor' => $withSuccessor,
            'without_successor' => $criticalPositions->count() - $withSuccessor,
            'coverage_percent' => $this->ctx->percent($withSuccessor, max($criticalPositions->count(), 1)),
            'readiness_distribution' => $readinessCounts,
            'rows' => $rows,
        ];
    }

    /**
     * Leadership pipeline (§94).
     *
     * @return array<string, mixed>
     */
    public function pipeline(?int $organizationId = null, int $limit = 100): array
    {
        $rows = HrLeadershipPipeline::query()
            ->when($organizationId, fn ($q) => $q->whereIn('employee_id', HrEmployee::query()
                ->where('organization_id', $organizationId)->select('id')))
            ->limit($limit)->get();

        return [
            'total' => $rows->count(),
            'by_stage' => $rows->groupBy('stage')->map->count()->all(),
            'stages_defined' => ['EMERGING_LEADER', 'FIRST_TIME_MANAGER', 'MANAGER', 'SENIOR_MANAGER', 'EXECUTIVE'],
            'rows' => $rows->map(fn (HrLeadershipPipeline $row): array => [
                'employee_id' => (int) $row->employee_id, 'stage' => $row->stage,
                'stage_since' => $row->stage_since?->toDateString(), 'readiness_for_next' => $row->readiness_for_next,
                'development_actions' => $row->development_actions, 'sponsor' => $row->sponsor_employee_id,
            ])->all(),
        ];
    }

    public function addToPipeline(int $employeeId, string $stage, array $data = [], mixed $actor = null): HrLeadershipPipeline
    {
        $row = HrLeadershipPipeline::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'stage' => strtoupper($stage)],
            [
                'stage_since' => $data['stage_since'] ?? now()->toDateString(),
                'source' => $data['source'] ?? 'MANUAL',
                'development_actions' => $data['development_actions'] ?? [],
                'sponsor_employee_id' => $data['sponsor_employee_id'] ?? null,
                'readiness_for_next' => $data['readiness_for_next'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );

        $this->ctx->audit('strategic.leadership_pipeline.updated', HrLeadershipPipeline::class, $row->getKey(), [
            'employee_id' => $employeeId, 'stage' => $row->stage,
        ], null, $actor, $employeeId);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?int $organizationId = null): array
    {
        $coverage = $this->coverage($organizationId);
        $risks = HrSuccessionRisk::query()->when($organizationId, fn ($q) => $q->whereIn('critical_position_id',
            HrCriticalPosition::query()->whereIn('position_id', HrPosition::query()
                ->where('organization_id', $organizationId)->select('id'))->select('id')))->get();

        return [
            'coverage' => $coverage,
            'risks' => [
                'total' => $risks->count(),
                'by_severity' => $risks->groupBy('severity')->map->count()->all(),
                'high_priority' => $risks->whereIn('severity', ['HIGH', 'CRITICAL'])->map(fn (HrSuccessionRisk $risk): array => [
                    'risk_type' => $risk->risk_type, 'severity' => $risk->severity, 'action' => $risk->recommended_action,
                ])->values()->all(),
            ],
            'pipeline' => $this->pipeline($organizationId)['by_stage'] ?? [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function refreshCoverage(HrSuccessionPlan $plan): void
    {
        $candidates = HrSuccessionCandidate::query()->where('plan_id', $plan->getKey())->get();
        $coverage = $candidates->isEmpty() ? 'NO_SUCCESSOR'
            : ($candidates->where('readiness', 'READY_NOW')->isNotEmpty() ? 'READY_NOW' : 'DEVELOPING');
        $plan->forceFill(['coverage_status' => $coverage])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function coverageStatus(HrCriticalPosition $critical, array $rows): array
    {
        $readyNow = collect($rows)->where('readiness', 'READY_NOW')->count();

        return [
            'candidates' => count($rows),
            'ready_now' => $readyNow,
            'minimum_successors' => (int) ($critical->min_successors ?? 2),
            'status' => $rows === [] ? 'NO_SUCCESSOR' : ($readyNow > 0 ? 'COVERED' : 'DEVELOPING'),
        ];
    }

    private function leadershipScore(int $employeeId): float
    {
        $stage = $this->ctx->safe(fn () => DB::table('hr_leadership_pipeline')->where('employee_id', $employeeId)
            ->orderByDesc('id')->value('stage'), null);
        $weights = ['EMERGING_LEADER' => 40, 'FIRST_TIME_MANAGER' => 60, 'MANAGER' => 75, 'SENIOR_MANAGER' => 90, 'EXECUTIVE' => 100];

        return (float) ($weights[(string) $stage] ?? 30);
    }

    private function retirementRisk(?HrEmployee $incumbent): string
    {
        if (! $incumbent) {
            return 'LOW';
        }

        return $this->ctx->safe(function () use ($incumbent): string {
            $birthDate = DB::table('hr_people')->where('id', $incumbent->person_id)->value('date_of_birth');
            if (! $birthDate) {
                return 'LOW';
            }
            $age = (int) \Illuminate\Support\Carbon::parse($birthDate)->age;

            return match (true) {
                $age >= 57 => 'HIGH', $age >= 53 => 'MEDIUM', default => 'LOW',
            };
        }, 'LOW');
    }

    private function turnoverRisk(?HrEmployee $employee): string
    {
        if (! $employee) {
            return 'LOW';
        }

        $signal = $this->ctx->safe(fn () => DB::table('hr_retention_signals')->where('employee_id', $employee->getKey())
            ->orderByDesc('id')->value('signal_level'), null);
        if ($signal) {
            return strtoupper((string) $signal);
        }
        $monthsSincePromotion = $this->ctx->safe(fn (): ?int => ($date = DB::table('hr_assignments')->where('employee_id', $employee->getKey())
            ->orderByDesc('id')->value('start_date')) ? $this->ctx->monthsBetween((string) $date, now()) : null, null);

        return ($monthsSincePromotion !== null && $monthsSincePromotion > 60) ? 'MEDIUM' : 'LOW';
    }
}
