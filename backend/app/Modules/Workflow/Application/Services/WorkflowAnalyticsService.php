<?php

namespace App\Modules\Workflow\Application\Services;

use App\Modules\Workflow\Domain\Enums\ApprovalStatus;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use App\Modules\Workflow\Domain\Enums\EscalationAction;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\SlaStatus;
use App\Modules\Workflow\Domain\Enums\StepStatus;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowDecision;
use App\Modules\Workflow\Models\WorkflowEscalation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowSlaRecord;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Support\Facades\DB;

/**
 * Analitik operasional (§52, §53).
 *
 * Prinsip yang dipegang: **memantau proses, bukan menilai orang**. Karena itu
 * laporan di sini menyajikan volume, durasi, tingkat penolakan/pengembalian,
 * kepatuhan SLA, dan eskalasi — bukan skor atau peringkat individu. Beban kerja
 * per approver ditampilkan sebagai **jumlah pekerjaan tertunda**, bukan sebagai
 * penilaian kinerja.
 */
class WorkflowAnalyticsService
{
    public function __construct(
        private readonly SlaService $sla,
    ) {
    }

    /**
     * Dasbor workflow admin (§52).
     */
    public function dashboard(int $days = 30): array
    {
        $since = now()->subDays($days);

        $instances = WorkflowInstance::query()->where('created_at', '>=', $since);

        $pendingApprovals = WorkflowApproval::query()
            ->where('status', ApprovalStatus::PENDING->value)
            ->whereHas('instance', fn ($q) => $q->open());

        $completed = WorkflowInstance::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->whereIn('status', [InstanceStatus::APPROVED->value, InstanceStatus::REJECTED->value]);

        return [
            'period_days' => $days,
            'definitions_active' => \App\Modules\Workflow\Models\WorkflowDefinition::query()->where('status', 'ACTIVE')->count(),
            'instances_open' => WorkflowInstance::query()->open()->count(),
            'instances_created' => (clone $instances)->count(),
            'instances_completed' => (clone $completed)->count(),
            'approved' => (clone $instances)->where('status', InstanceStatus::APPROVED->value)->count(),
            'rejected' => (clone $instances)->where('status', InstanceStatus::REJECTED->value)->count(),
            'returned' => (clone $instances)->where('status', InstanceStatus::RETURNED->value)->count(),
            'failed' => (clone $instances)->where('status', InstanceStatus::FAILED->value)->count(),
            'expired' => (clone $instances)->where('status', InstanceStatus::EXPIRED->value)->count(),
            'pending_approvals' => (clone $pendingApprovals)->count(),
            'overdue_approvals' => (clone $pendingApprovals)->where('due_at', '<', now())->count(),
            'due_soon_approvals' => (clone $pendingApprovals)->whereBetween('due_at', [now(), now()->addHours(24)])->count(),
            'escalations' => WorkflowEscalation::query()->where('created_at', '>=', $since)->count(),
            'average_approval_hours' => $this->averageApprovalHours($days),
            'rejection_rate' => $this->rate(InstanceStatus::REJECTED->value, $days),
            'return_rate' => $this->rate(InstanceStatus::RETURNED->value, $days),
            'escalation_rate' => $this->escalationRate($days),
            'sla' => $this->sla->summary($days),
        ];
    }

    /**
     * Volume request per hari (untuk grafik).
     *
     * @return list<array{date: string, created: int, completed: int}>
     */
    public function volumeByDay(int $days = 30): array
    {
        $created = WorkflowInstance::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $completed = WorkflowInstance::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays($days))
            ->select(DB::raw('DATE(completed_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $rows = [];

        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();

            $rows[] = [
                'date' => $date,
                'created' => (int) ($created[$date] ?? 0),
                'completed' => (int) ($completed[$date] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Durasi rata-rata dari submit hingga keputusan akhir, per definisi.
     *
     * @return list<array{definition: string, module: string, samples: int, average_hours: float|null}>
     */
    public function averageDurationByDefinition(int $days = 90): array
    {
        $rows = WorkflowInstance::query()
            ->whereNotNull('completed_at')
            ->whereNotNull('submitted_at')
            ->where('completed_at', '>=', now()->subDays($days))
            ->select(
                'module',
                DB::raw('AVG((julianday(completed_at) - julianday(submitted_at)) * 24) as average_hours'),
                DB::raw('COUNT(*) as samples'),
                'definition_id',
            )
            ->groupBy('definition_id', 'module')
            ->get();

        $codes = \App\Modules\Workflow\Models\WorkflowDefinition::query()
            ->whereIn('id', $rows->pluck('definition_id'))
            ->pluck('code', 'id');

        return $rows->map(fn ($row) => [
            'definition' => (string) ($codes[$row->definition_id] ?? '—'),
            'module' => (string) $row->module,
            'samples' => (int) $row->samples,
            'average_hours' => $row->average_hours === null ? null : round((float) $row->average_hours, 2),
        ])->all();
    }

    /**
     * Volume per modul & per unit — fokus pemantauan operasional.
     *
     * @return array{by_module: list<array<string, mixed>>, by_department: list<array<string, mixed>>}
     */
    public function volumeByModuleAndDepartment(int $days = 30): array
    {
        $byModule = WorkflowInstance::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->select('module', DB::raw('COUNT(*) as total'))
            ->groupBy('module')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['module' => (string) $row->module, 'total' => (int) $row->total])
            ->all();

        $byDepartment = WorkflowInstance::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('requester_department')
            ->select('requester_department', DB::raw('COUNT(*) as total'))
            ->groupBy('requester_department')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['department' => (string) $row->requester_department, 'total' => (int) $row->total])
            ->all();

        return ['by_module' => $byModule, 'by_department' => $byDepartment];
    }

    /**
     * Beban kerja per approver: **jumlah pekerjaan tertunda** (bukan skor).
     *
     * @return list<array{approver_id: int, pending: int, overdue: int}>
     */
    public function workloadByApprover(int $limit = 20): array
    {
        return WorkflowApproval::query()
            ->where('status', ApprovalStatus::PENDING->value)
            ->whereHas('instance', fn ($q) => $q->open())
            ->select(
                'approver_id',
                DB::raw('COUNT(*) as pending'),
                DB::raw('SUM(CASE WHEN due_at IS NOT NULL AND due_at < CURRENT_TIMESTAMP THEN 1 ELSE 0 END) as overdue'),
            )
            ->groupBy('approver_id')
            ->orderByDesc('pending')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'approver_id' => (int) $row->approver_id,
                'pending' => (int) $row->pending,
                'overdue' => (int) $row->overdue,
            ])
            ->all();
    }

    /**
     * Titik tersangkut: step yang aktif terlalu lama (§53 "Failed Workflows").
     *
     * @return list<array<string, mixed>>
     */
    public function bottlenecks(int $limit = 20): array
    {
        return WorkflowStepInstance::query()
            ->whereIn('status', [
                StepStatus::WAITING_APPROVAL->value,
                StepStatus::WAITING_REVIEW->value,
                StepStatus::WAITING_FOR_APPROVER->value,
            ])
            ->with(['instance:id,code,title,module,priority', 'instance.definition:id,code,name'])
            ->orderByRaw('COALESCE(due_at, activated_at) asc')
            ->limit($limit)
            ->get()
            ->map(fn (WorkflowStepInstance $step) => [
                'instance' => $step->instance?->code,
                'title' => $step->instance?->title,
                'module' => $step->instance?->module,
                'step' => $step->name,
                'status' => $step->status->value,
                'sla_status' => $step->sla_status?->value,
                'activated_at' => $step->activated_at?->toIso8601String(),
                'due_at' => $step->due_at?->toIso8601String(),
                'waiting_hours' => $step->activated_at === null ? null : round(now()->diffInMinutes($step->activated_at) / 60, 1),
            ])
            ->all();
    }

    /** Tingkat kepatuhan SLA per definisi (§53). */
    public function slaComplianceByDefinition(int $days = 90): array
    {
        $records = WorkflowSlaRecord::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status', SlaStatus::COMPLETED->value)
            ->with('instance.definition:id,code')
            ->get();

        $grouped = [];

        foreach ($records as $record) {
            $code = (string) ($record->instance?->definition?->code ?? '—');
            $grouped[$code] ??= ['definition' => $code, 'total' => 0, 'breached' => 0];

            $grouped[$code]['total']++;

            if (($record->metadata['breached'] ?? false) === true) {
                $grouped[$code]['breached']++;
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['compliance_percent'] = $row['total'] === 0
                ? null
                : round((($row['total'] - $row['breached']) / $row['total']) * 100, 1);

            return $row;
        }, $grouped));
    }

    /** Tingkat penolakan & pengembalian (§53). */
    public function decisionBreakdown(int $days = 30): array
    {
        $rows = WorkflowDecision::query()
            ->where('decided_at', '>=', now()->subDays($days))
            ->select('decision', DB::raw('COUNT(*) as total'))
            ->groupBy('decision')
            ->pluck('total', 'decision');

        $total = max(1, (int) $rows->sum());

        $breakdown = [];

        foreach (DecisionType::cases() as $decision) {
            $count = (int) ($rows[$decision->value] ?? 0);

            $breakdown[] = [
                'decision' => $decision->value,
                'label' => $decision->label(),
                'total' => $count,
                'percent' => round(($count / $total) * 100, 1),
            ];
        }

        return $breakdown;
    }

    /** Eskalasi per aksi (§40) — untuk menilai kebijakan SLA. */
    public function escalationBreakdown(int $days = 30): array
    {
        $rows = WorkflowEscalation::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->pluck('total', 'action');

        return array_values(array_map(
            fn (EscalationAction $action) => [
                'action' => $action->value,
                'label' => $action->label(),
                'total' => (int) ($rows[$action->value] ?? 0),
            ],
            EscalationAction::cases(),
        ));
    }

    protected function averageApprovalHours(int $days): ?float
    {
        $row = WorkflowInstance::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays($days))
            ->select(DB::raw('AVG((julianday(completed_at) - julianday(submitted_at)) * 24) as hours'))
            ->first();

        return $row?->hours === null ? null : round((float) $row->hours, 2);
    }

    protected function rate(string $status, int $days): ?float
    {
        $total = WorkflowInstance::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        if ($total === 0) {
            return null;
        }

        $count = WorkflowInstance::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status', $status)
            ->count();

        return round(($count / $total) * 100, 1);
    }

    protected function escalationRate(int $days): ?float
    {
        $steps = WorkflowSlaRecord::query()->where('created_at', '>=', now()->subDays($days))->count();

        if ($steps === 0) {
            return null;
        }

        $escalated = WorkflowSlaRecord::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->whereIn('status', [SlaStatus::ESCALATED->value, SlaStatus::OVERDUE->value])
            ->count();

        return round(($escalated / $steps) * 100, 1);
    }
}
