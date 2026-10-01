<?php

namespace App\Modules\Workflow\Application\Services;

use App\Modules\Workflow\Application\Support\WorkflowPolicy;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\ApprovalStatus;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\EscalationAction;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Enums\SlaStatus;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowEscalation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowSlaRecord;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Support\Facades\DB;

/**
 * Eskalasi & pengingat SLA (§40, §69).
 *
 * Tangga yang dijalankan (semuanya idempoten — boleh disapu berulang):
 *
 * ```
 * step aktif → 50% SLA : pengingat
 *            → 80% SLA : "segera jatuh tempo"
 *            → 100% SLA: terlambat + eskalasi tingkat 1
 *            → +50% SLA: eskalasi tingkat 2, dan seterusnya sampai batas
 * ```
 *
 * Eskalasi dapat berupa memberi tahu atasan, mengalihkan tugas, menaikkan
 * kewenangan, atau menandai terlambat — sesuai konfigurasi node/versi. Setiap
 * tindakan meninggalkan baris `workflow_escalations`, jadi "kenapa request ini
 * pindah ke orang lain?" selalu punya jawaban.
 */
class EscalationService
{
    public function __construct(
        private readonly SlaService $sla,
        private readonly WorkflowNotificationService $notifications,
        private readonly WorkflowAuditService $audit,
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly WorkflowStepService $steps,
    ) {
    }

    /**
     * Sapu seluruh SLA yang berjalan: kirim pengingat, tandai terlambat,
     * jalankan eskalasi.
     *
     * @return array{reminded: int, due_soon: int, overdue: int, escalated: int}
     */
    public function sweep(int $limit = 500): array
    {
        $stats = ['reminded' => 0, 'due_soon' => 0, 'overdue' => 0, 'escalated' => 0];

        foreach ($this->sla->recordsToSweep($limit) as $record) {
            /** @var WorkflowSlaRecord $record */
            $record->loadMissing(['step', 'instance.definition', 'instance.version']);

            $step = $record->step;

            if ($step === null || ! $step->status->isOpen()) {
                continue;
            }

            $now = now();

            if ($record->reminder_sent_at === null && $record->remind_at !== null && $now->greaterThanOrEqualTo($record->remind_at)) {
                $this->remind($record, $step);
                $stats['reminded']++;
            }

            if ($record->due_soon_notified_at === null && $record->due_soon_at !== null && $now->greaterThanOrEqualTo($record->due_soon_at)) {
                $this->dueSoon($record, $step);
                $stats['due_soon']++;
            }

            if ($record->due_at !== null && $now->greaterThanOrEqualTo($record->due_at) && $record->overdue_at === null) {
                $this->markOverdue($record, $step);
                $stats['overdue']++;
            }

            if ($record->escalate_at !== null && $now->greaterThanOrEqualTo($record->escalate_at)) {
                $applied = $this->escalate($record, $step);

                if ($applied) {
                    $stats['escalated']++;
                }
            }
        }

        return $stats;
    }

    protected function remind(WorkflowSlaRecord $record, WorkflowStepInstance $step): void
    {
        $record->forceFill(['reminder_sent_at' => now()])->save();

        $this->notifications->dispatch(
            event: NotificationEvent::APPROVAL_REMINDER,
            instance: $record->instance,
            recipients: $this->approverIds($step),
            title: 'Pengingat: '.$record->instance->title,
            body: sprintf('%s menunggu keputusan Anda pada step "%s".', $record->instance->code, $step->name),
            payload: ['node_key' => $step->node_key],
            step: $step,
            keySuffix: 'reminder',
        );
    }

    protected function dueSoon(WorkflowSlaRecord $record, WorkflowStepInstance $step): void
    {
        $record->forceFill([
            'due_soon_notified_at' => now(),
            'status' => SlaStatus::DUE_SOON->value,
        ])->save();

        $step->forceFill(['sla_status' => SlaStatus::DUE_SOON->value])->save();

        $this->notifications->dispatch(
            event: NotificationEvent::APPROVAL_DUE_SOON,
            instance: $record->instance,
            recipients: $this->approverIds($step),
            title: 'Segera jatuh tempo: '.$record->instance->title,
            body: sprintf('%s perlu diputuskan sebelum %s.', $record->instance->code, $record->due_at?->translatedFormat('d M Y H:i')),
            payload: ['node_key' => $step->node_key, 'due_at' => $record->due_at?->toIso8601String()],
            step: $step,
            keySuffix: 'due_soon',
        );
    }

    protected function markOverdue(WorkflowSlaRecord $record, WorkflowStepInstance $step): void
    {
        $record->forceFill([
            'overdue_at' => now(),
            'status' => SlaStatus::OVERDUE->value,
        ])->save();

        $step->forceFill(['sla_status' => SlaStatus::OVERDUE->value])->save();

        $this->audit->record(
            AuditAction::SLA_BREACHED,
            $record->instance,
            $record,
            after: [
                'node_key' => $step->node_key,
                'due_at' => $record->due_at?->toIso8601String(),
                'sla_minutes' => $record->sla_minutes,
            ],
            extra: ['step_instance_id' => $step->id],
        );

        $this->notifications->dispatch(
            event: NotificationEvent::APPROVAL_OVERDUE,
            instance: $record->instance,
            recipients: $this->approverIds($step),
            title: 'Terlambat: '.$record->instance->title,
            body: sprintf('%s melewati batas waktu %s pada step "%s".', $record->instance->code, $record->due_at?->translatedFormat('d M Y H:i'), $step->name),
            payload: ['node_key' => $step->node_key],
            step: $step,
            keySuffix: 'overdue',
        );
    }

    /**
     * Jalankan satu tingkat eskalasi.
     *
     * @return bool true bila tindakan diterapkan
     */
    public function escalate(WorkflowSlaRecord $record, WorkflowStepInstance $step): bool
    {
        $instance = $record->instance;
        $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);
        $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];
        $config = array_merge((array) $policy->escalation, (array) ($node['escalation'] ?? []));

        $maxLevels = max(1, (int) ($config['max_levels'] ?? 3));

        if ((int) $record->escalation_count >= min($maxLevels, (int) $record->max_escalations)) {
            return false;
        }

        $action = EscalationAction::tryFrom(strtoupper((string) ($config['action'] ?? 'NOTIFY'))) ?? EscalationAction::NOTIFY;
        $level = (int) $record->escalation_count + 1;
        $targets = $this->escalationTargets($record, $step, $config);

        $escalation = WorkflowEscalation::query()->create([
            'instance_id' => $instance->id,
            'step_instance_id' => $step->id,
            'sla_record_id' => $record->id,
            'level' => $level,
            'action' => $action->value,
            'reason' => sprintf('SLA %s menit terlewati pada level %d.', $record->sla_minutes, $level),
            'to_user_id' => $targets[0] ?? null,
            'status' => 'PENDING',
            'payload' => ['targets' => $targets, 'due_at' => $record->due_at?->toIso8601String()],
            'created_at' => now(),
        ]);

        $applied = true;

        switch ($action) {
            case EscalationAction::REASSIGN:
            case EscalationAction::ESCALATE:
                $applied = $this->reassignToEscalation($escalation, $record, $step, $targets, $policy);

                break;

            case EscalationAction::MARK_OVERDUE:
                $step->forceFill(['sla_status' => SlaStatus::OVERDUE->value])->save();

                break;

            case EscalationAction::NOTIFY:
            default:
                $this->notifications->dispatch(
                    event: NotificationEvent::APPROVAL_ESCALATED,
                    instance: $instance,
                    recipients: $targets,
                    title: 'Eskalasi: '.$instance->title,
                    body: sprintf('%s terlambat pada step "%s" (level %d). Mohon ditindaklanjuti.', $instance->code, $step->name, $level),
                    payload: ['node_key' => $step->node_key, 'level' => $level],
                    step: $step,
                    keySuffix: 'escalation:'.$level,
                );

                break;
        }

        $nextEscalateAt = $record->due_at?->copy()->addMinutes((int) floor($record->sla_minutes * ((int) ($config['repeat_percent'] ?? 50)) / 100));

        $record->forceFill([
            'escalation_count' => $level,
            'escalated_at' => now(),
            'escalate_at' => $nextEscalateAt,
            'status' => SlaStatus::ESCALATED->value,
            'escalated_to' => array_values(array_unique(array_merge((array) $record->escalated_to, $targets))),
        ])->save();

        $step->forceFill(['sla_status' => SlaStatus::ESCALATED->value])->save();

        $escalation->forceFill([
            'status' => $applied ? 'APPLIED' : 'FAILED',
            'applied_at' => now(),
        ])->save();

        $this->audit->record(
            AuditAction::ESCALATION_APPLIED,
            $instance,
            $escalation,
            after: [
                'level' => $level,
                'action' => $action->value,
                'targets' => $targets,
                'applied' => $applied,
            ],
            extra: ['step_instance_id' => $step->id, 'target_type' => WorkflowEscalation::class, 'target_id' => (string) $escalation->id],
        );

        return $applied;
    }

    /**
     * Pindahkan tugas step kepada penerima eskalasi tanpa menghapus penugasan
     * lama (§61): penugasan sebelumnya ditandai, penugasan baru menunjuk orang
     * baru, dan identitas pemegang awal tetap tercatat pada rujukan eskalasi.
     *
     * @param  list<int>  $targets
     */
    protected function reassignToEscalation(
        WorkflowEscalation $escalation,
        WorkflowSlaRecord $record,
        WorkflowStepInstance $step,
        array $targets,
        WorkflowPolicy $policy,
    ): bool {
        if ($targets === []) {
            return false;
        }

        return DB::transaction(function () use ($escalation, $step, $targets) {
            $existing = WorkflowApproval::query()
                ->where('step_instance_id', $step->id)
                ->where('status', ApprovalStatus::PENDING->value)
                ->get();

            foreach ($existing as $approval) {
                $approval->forceFill([
                    'status' => ApprovalStatus::CANCELLED->value,
                    'responded_at' => now(),
                    'escalation_id' => $escalation->id,
                ])->save();
            }

            $created = 0;

            foreach ($targets as $userId) {
                if ($existing->contains(fn (WorkflowApproval $a) => (int) $a->approver_id === $userId)) {
                    continue;
                }

                $instance = $step->instance;

                $this->steps->assignManually(
                    $instance,
                    $step,
                    [$userId],
                    \App\Models\User::query()->findOrFail($userId),
                    'Eskalasi SLA level '.$escalation->level,
                );

                $created++;
            }

            // Penugasan eskalasi ditandai agar laporan dapat memisahkannya.
            WorkflowApproval::query()
                ->where('step_instance_id', $step->id)
                ->where('status', ApprovalStatus::PENDING->value)
                ->update([
                    'source' => ApproverSource::ESCALATION->value,
                    'escalation_id' => $escalation->id,
                    'resolver' => 'escalation',
                ]);

            return $created > 0;
        });
    }

    /**
     * Siapa yang menerima eskalasi: spesifikasi `target` pada konfigurasi,
     * atau atasan approver yang masih menunggu sebagai default yang wajar.
     *
     * @param  array<string, mixed>  $config
     * @return list<int>
     */
    protected function escalationTargets(WorkflowSlaRecord $record, WorkflowStepInstance $step, array $config = []): array
    {
        $specs = (array) ($config['target'] ?? []);

        if ($specs !== []) {
            $context = app(WorkflowContextService::class)->forInstance($record->instance);
            $resolved = app(ApproverResolverService::class)->resolveSpecs($specs, $context);

            if (! $resolved->isEmpty()) {
                return $resolved->userIds();
            }
        }

        $pending = WorkflowApproval::query()
            ->where('step_instance_id', $step->id)
            ->where('status', ApprovalStatus::PENDING->value)
            ->pluck('approver_id');

        $targets = [];

        foreach ($pending as $approverId) {
            $managerId = $this->directory->managerOf((int) $approverId);

            if ($managerId !== null) {
                $targets[] = $managerId;
            }
        }

        $targets = array_values(array_unique($targets));

        if ($targets === []) {
            // Jalan terakhir: pengelola workflow (bukan "dilewati diam-diam").
            $targets = \App\Models\User::query()
                ->where('is_active', true)
                ->permission('workflow.approval.reassign')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $targets;
    }

    /** @return list<int> */
    protected function approverIds(WorkflowStepInstance $step): array
    {
        return $step->approvals()
            ->where('status', ApprovalStatus::PENDING->value)
            ->pluck('approver_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Ringkasan eskalasi untuk dasbor. */
    public function summary(int $days = 30): array
    {
        $rows = WorkflowEscalation::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->select('level', DB::raw('COUNT(*) as total'))
            ->groupBy('level')
            ->orderBy('level')
            ->get();

        return [
            'total' => (int) $rows->sum('total'),
            'by_level' => $rows->map(fn ($row) => ['level' => (int) $row->level, 'total' => (int) $row->total])->all(),
        ];
    }
}
