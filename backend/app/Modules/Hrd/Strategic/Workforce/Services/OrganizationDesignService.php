<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Workforce\Models\HrOrganizationDesignScenario;
use Illuminate\Support\Facades\DB;

/**
 * Organization Design: simulasi struktur (merge/split/new department, pindah
 * posisi, ganti atasan, span of control, pengurangan layer, kampus baru),
 * analisis span of control, dan peta jaringan organisasi.
 *
 * Semua usulan disimpan sebagai proposal — organisasi aktual hanya berubah
 * lewat mesin transaksi + workflow Phase 1.
 */
class OrganizationDesignService
{
    public function __construct(private readonly Phase3Context $ctx) {}

    public function propose(array $data, mixed $actor = null): HrOrganizationDesignScenario
    {
        $scenario = HrOrganizationDesignScenario::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_organization_design_scenarios', 'ODS'),
            'name' => $data['name'] ?? 'Usulan desain organisasi',
            'description' => $data['description'] ?? null,
            'base_organization_id' => $data['base_organization_id'] ?? null,
            'proposal' => $data['changes'] ?? [],
            'status' => 'DRAFT',
            'proposed_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->ctx->audit('strategic.organization_design.proposed', HrOrganizationDesignScenario::class, $scenario->getKey(), [
            'changes' => count((array) $scenario->proposal),
        ], null, $actor);

        return $scenario;
    }

    /**
     * @return array<string, mixed>
     */
    public function simulate(HrOrganizationDesignScenario $scenario, mixed $actor = null): array
    {
        $organizationId = $scenario->base_organization_id;
        $before = $this->structureMetrics($organizationId);
        $changes = (array) $scenario->proposal;

        $headcountMoved = 0;
        $costDelta = 0.0;
        $details = [];

        foreach ($changes as $change) {
            $type = strtoupper((string) ($change['type'] ?? 'CUSTOM'));
            $affected = (array) ($change['employee_ids'] ?? []);
            $count = count($affected) ?: (int) ($change['headcount'] ?? 0);
            $headcountMoved += $count;

            $avgCost = $this->averageCostOf($affected);
            $costImpact = match ($type) {
                'MERGE_DEPARTMENT' => -1 * $avgCost * 0.05 * max($count, 1),
                'SPLIT_DEPARTMENT' => $avgCost * 0.03 * max($count, 1),
                'NEW_DEPARTMENT' => $avgCost * max($count, 1) * 0.1,
                'NEW_CAMPUS' => $avgCost * max($count, 1) * 0.15,
                'LAYER_REDUCTION' => -1 * $avgCost * max($count, 1),
                'MANAGER_CHANGE' => 0.0,
                'POSITION_MOVE' => 0.0,
                default => 0.0,
            };
            $costDelta += $costImpact;
            $details[] = [
                'type' => $type, 'headcount' => $count, 'cost_impact' => $this->ctx->money($costImpact),
                'note' => $change['note'] ?? null,
            ];
        }

        $after = [
            'layers' => max(1, $before['layers'] + $this->layerDelta($changes)),
            'managers' => max(1, $before['managers'] + $this->managerDelta($changes)),
            'span_average' => $before['span_average'],
        ];
        $after['span_average'] = $after['managers'] > 0
            ? round($before['headcount'] / $after['managers'], 2)
            : 0.0;

        $results = [
            'before' => $before,
            'after' => $after,
            'impact' => [
                'headcount_moved' => $headcountMoved,
                'cost_delta_monthly' => $this->ctx->money($costDelta),
                'layer_delta' => $after['layers'] - $before['layers'],
                'span_delta' => round($after['span_average'] - $before['span_average'], 2),
            ],
            'changes' => $details,
            'recommendation' => $this->recommendation($before, $after, $costDelta),
            'integrity' => [
                'proposal_only' => true,
                'note' => 'Struktur aktual tidak diubah; eksekusi lewat workflow Phase 1.',
            ],
        ];

        $scenario->forceFill([
            'status' => 'SIMULATED',
            'impact_headcount' => $headcountMoved,
            'impact_cost' => $this->ctx->money($costDelta),
            'impact_layers' => $after['layers'] - $before['layers'],
            'impact_span_of_control' => round($after['span_average'] - $before['span_average'], 2),
            'proposal' => array_merge(['changes' => $changes], $results),
            'simulated_at' => now(),
        ])->save();

        $this->ctx->audit('strategic.organization_design.simulated', HrOrganizationDesignScenario::class, $scenario->getKey(), $results['impact'], null, $actor);

        return $results;
    }

    /**
     * Span of control per manajer dengan penanda, bukan keputusan otomatis.
     *
     * @return array<string, mixed>
     */
    public function spanOfControl(?int $organizationId = null, mixed $user = null): array
    {
        $employees = $this->ctx->scopedEmployees($user, $organizationId)
            ->get(['id', 'employee_number', 'position_id', 'department_id', 'manager_employee_id', 'is_active']);

        $byManager = $employees->whereNotNull('manager_employee_id')
            ->groupBy('manager_employee_id');

        $rows = [];
        foreach ($byManager as $managerId => $reports) {
            $manager = $employees->firstWhere('id', (int) $managerId);
            $size = $reports->count();
            $flags = [];
            if ($size === 1) {
                $flags[] = 'SINGLE_EMPLOYEE_MANAGER';
            }
            if ($size < 2) {
                $flags[] = 'VERY_SMALL_TEAM';
            }
            if ($size > 12) {
                $flags[] = 'VERY_LARGE_TEAM';
            }

            $rows[] = [
                'manager_employee_id' => (int) $managerId,
                'manager_number' => $manager?->employee_number,
                'direct_reports' => $size,
                'department_id' => $manager?->department_id,
                'flags' => $flags,
            ];
        }

        $sizes = array_column($rows, 'direct_reports');

        $layers = $this->layers($organizationId, $user);
        foreach ($rows as $index => $row) {
            if (($layers['depth'] ?? 0) > 4) {
                $rows[$index]['flags'][] = 'EXCESSIVE_MANAGEMENT_LAYER';
            }
        }

        return [
            'managers' => count($rows),
            'average_team_size' => $sizes ? $this->ctx->score(array_sum($sizes) / count($sizes)) : 0.0,
            'min_team_size' => $sizes ? min($sizes) : 0,
            'max_team_size' => $sizes ? max($sizes) : 0,
            'median_team_size' => $this->ctx->median($sizes),
            'depth' => $layers['depth'] ?? 0,
            'flag_summary' => collect($rows)->flatMap(fn (array $r): array => $r['flags'])->countBy()->all(),
            'note' => 'Span of control adalah sinyal analitik; keputusan struktur tetap di manusia.',
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function layers(?int $organizationId = null, mixed $user = null): array
    {
        $employees = $this->ctx->scopedEmployees($user, $organizationId)->get(['id', 'manager_employee_id']);
        $managers = [];
        foreach ($employees as $employee) {
            $managers[$employee->getKey()] = $employee->manager_employee_id;
        }

        $depth = 0;
        foreach (array_keys($managers) as $id) {
            $node = $id;
            $steps = 0;
            $seen = [];
            while (($managers[$node] ?? null) && $steps < 50 && ! isset($seen[$node])) {
                $seen[$node] = true;
                $node = (int) $managers[$node];
                $steps++;
            }
            $depth = max($depth, $steps);
        }

        return [
            'employees' => count($managers),
            'depth' => $depth,
            'managers' => collect($managers)->filter()->unique()->count(),
            'flag' => $depth > 4 ? 'EXCESSIVE_MANAGEMENT_LAYER' : null,
        ];
    }

    /**
     * Peta jaringan organisasi: hierarki manajer → departemen → tim → pegawai,
     * plus relasi lintas fungsi (dotted line, mentor, proyek).
     *
     * @return array<string, mixed>
     */
    public function network(?int $organizationId = null, int $limit = 80, mixed $user = null): array
    {
        $employees = $this->ctx->scopedEmployees($user, $organizationId)
            ->where('is_active', true)
            ->with(['position', 'department'])
            ->limit($limit)->get();

        $nodes = $employees->map(fn (HrEmployee $employee): array => [
            'employee_id' => $employee->getKey(),
            'employee_number' => $employee->employee_number,
            'position' => $employee->position?->title,
            'department_id' => $employee->department_id,
            'manager_employee_id' => $employee->manager_employee_id,
            'report_count' => $employees->where('manager_employee_id', $employee->getKey())->count(),
        ])->all();

        $crossFunctional = $this->ctx->safe(function () use ($employees): array {
            if (! $this->ctx->hasTable('hr_org_relationships')) {
                return [];
            }

            return DB::table('hr_org_relationships')
                ->whereIn('from_employee_id', $employees->pluck('id')->all())
                ->orWhereIn('to_employee_id', $employees->pluck('id')->all())
                ->get(['from_employee_id', 'to_employee_id', 'relationship_type'])
                ->map(fn ($row): array => [
                    'from' => (int) $row->from_employee_id, 'to' => (int) $row->to_employee_id,
                    'type' => $row->relationship_type,
                ])->all();
        }, []);

        $projects = $this->ctx->safe(function () use ($employees): array {
            if (! $this->ctx->hasTable('hr_project_assignments')) {
                return [];
            }

            return DB::table('hr_project_assignments')
                ->whereIn('employee_id', $employees->pluck('id')->all())
                ->whereIn('status', ['PLANNED', 'ACTIVE'])
                ->get(['employee_id', 'project_name', 'role_title', 'allocation_percent'])
                ->map(fn ($row): array => [
                    'employee_id' => (int) $row->employee_id, 'project' => $row->project_name,
                    'role' => $row->role_title, 'allocation' => (float) $row->allocation_percent,
                ])->all();
        }, []);

        $mentoring = $this->ctx->safe(function () use ($employees): array {
            if (! $this->ctx->hasTable('hr_mentor_assignments')) {
                return [];
            }

            return DB::table('hr_mentor_assignments')
                ->whereIn('mentee_employee_id', $employees->pluck('id')->all())
                ->get(['mentor_employee_id', 'mentee_employee_id', 'status'])
                ->map(fn ($row): array => [
                    'mentor' => (int) $row->mentor_employee_id, 'mentee' => (int) $row->mentee_employee_id,
                    'type' => 'MENTORING', 'status' => $row->status,
                ])->all();
        }, []);

        return [
            'nodes' => $nodes,
            'hierarchy_edges' => collect($nodes)->filter(fn (array $n): bool => $n['manager_employee_id'] !== null)
                ->map(fn (array $n): array => ['from' => $n['manager_employee_id'], 'to' => $n['employee_id'], 'type' => 'REPORTS_TO'])
                ->values()->all(),
            'cross_functional_edges' => $crossFunctional,
            'project_assignments' => $projects,
            'mentoring' => $mentoring,
            'summary' => [
                'nodes' => count($nodes), 'cross_functional' => count($crossFunctional),
                'projects' => count($projects), 'mentoring' => count($mentoring),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function structureMetrics(?int $organizationId): array
    {
        $employees = HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId));

        $headcount = (int) $employees->count();
        $managers = (int) HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereIn('id', HrEmployee::query()->whereNotNull('manager_employee_id')->select('manager_employee_id'))
            ->count();
        $layers = $this->layers($organizationId, null)['depth'];

        return [
            'headcount' => $headcount,
            'managers' => $managers,
            'layers' => max($layers, 1),
            'span_average' => $managers > 0 ? round($headcount / $managers, 2) : 0.0,
            'departments' => (int) HrEmployee::query()->where('is_active', true)
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->distinct()->count('department_id'),
            'positions' => (int) HrPosition::query()->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->count(),
        ];
    }

    /**
     * @param  array<int, int>  $employeeIds
     */
    private function averageCostOf(array $employeeIds): float
    {
        $avg = $this->ctx->safe(function () use ($employeeIds): float {
            if ($employeeIds === [] || ! $this->ctx->hasTable('hr_payroll_items')) {
                return 0.0;
            }
            $latest = DB::table('hr_payroll_items')->whereIn('employee_id', $employeeIds)->orderByDesc('id')->value('payroll_run_id');
            if (! $latest) {
                return 0.0;
            }

            return (float) DB::table('hr_payroll_items')->where('payroll_run_id', $latest)->whereIn('employee_id', $employeeIds)->avg('net_amount');
        }, 0.0);

        return $avg > 0 ? $this->ctx->money($avg) : (float) $this->ctx->config('cost.fallback_monthly_per_employee', 5000000);
    }

    private function layerDelta(array $changes): int
    {
        $delta = 0;
        foreach ($changes as $change) {
            $delta += match (strtoupper((string) ($change['type'] ?? ''))) {
                'NEW_DEPARTMENT', 'NEW_CAMPUS' => 1,
                'LAYER_REDUCTION' => -1,
                'MERGE_DEPARTMENT' => -1,
                default => 0,
            };
        }

        return $delta;
    }

    private function managerDelta(array $changes): int
    {
        $delta = 0;
        foreach ($changes as $change) {
            $delta += match (strtoupper((string) ($change['type'] ?? ''))) {
                'NEW_DEPARTMENT' => (int) ($change['managers'] ?? 1),
                'MERGE_DEPARTMENT' => -1,
                'LAYER_REDUCTION' => -1 * (int) ($change['managers'] ?? 1),
                default => 0,
            };
        }

        return $delta;
    }

    /**
     * @return array<int, string>
     */
    private function recommendation(array $before, array $after, float $costDelta): array
    {
        $notes = [];
        if ($after['span_average'] > $before['span_average'] && $after['span_average'] > 12) {
            $notes[] = 'Span rata-rata melewati 12 — pertimbangkan sub-unit atau tambahan manajer.';
        }
        if ($after['layers'] < $before['layers']) {
            $notes[] = 'Pengurangan layer mempercepat keputusan, tetapi pastikan kontrol dan pendampingan tetap ada.';
        }
        if ($costDelta < 0) {
            $notes[] = 'Usulan menurunkan biaya bulanan; verifikasi dampak beban kerja sebelum eksekusi.';
        }
        if ($costDelta > 0) {
            $notes[] = 'Usulan menaikkan biaya bulanan; bandingkan dengan skenario anggaran.';
        }

        return $notes === [] ? ['Tidak ada dampak struktural signifikan.'] : $notes;
    }
}
