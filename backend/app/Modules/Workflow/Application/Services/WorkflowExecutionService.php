<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Application\Support\WorkflowPolicy;
use App\Modules\Workflow\Domain\Enums\ApprovalMode;
use App\Modules\Workflow\Domain\Enums\ApprovalStatus;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Enums\NodeType;
use App\Modules\Workflow\Domain\Enums\StepResult;
use App\Modules\Workflow\Domain\Enums\StepStatus;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Domain\Support\WorkflowGraph;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowDecision;
use App\Modules\Workflow\Models\WorkflowEvent;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;

/**
 * Mesin eksekusi graf workflow (§46).
 *
 * Cara kerjanya sengaja **berbasis keadaan yang tersimpan**, bukan tumpukan
 * panggilan: setiap perpindahan ditulis ke `workflow_instances.execution_state`
 * (`completed`, `skipped`, `active`, `inactive_edges`). Karena itu:
 *
 *  - request yang berhenti menunggu approver dapat dilanjutkan proses lain;
 *  - percabangan kondisi aman (cabang yang tidak ditempuh dinonaktifkan, bukan
 *    dibiarkan menggantung);
 *  - penggabungan cabang paralel jelas: node berikutnya aktif hanya setelah
 *    **semua** cabang yang hidup selesai;
 *  - tidak ada loop tak terbatas: jumlah perpindahan dibatasi kebijakan.
 *
 * Mesin ini tidak mengetahui satu pun istilah bisnis (tidak ada "PO", "cuti",
 * atau "tagihan" di dalamnya) — hanya node, kondisi, approver, dan aksi.
 */
class WorkflowExecutionService
{
    public function __construct(
        private readonly WorkflowStepService $steps,
        private readonly SlaService $sla,
        private readonly ConditionEvaluatorService $conditions,
        private readonly WorkflowActionService $actions,
        private readonly WorkflowNotificationService $notifications,
        private readonly WorkflowAuditService $audit,
        private readonly WorkflowContextService $contexts,
    ) {
    }

    /** Mulai alur dari node START. */
    public function start(WorkflowInstance $instance, ?WorkflowContext $context = null): void
    {
        $this->run($instance, $context);
    }

    /**
     * Jalankan graf hingga berhenti: menunggu manusia, selesai, atau gagal.
     */
    public function run(WorkflowInstance $instance, ?WorkflowContext $context = null): void
    {
        $instance->refresh();

        if ($instance->status->isTerminal() || $instance->status === InstanceStatus::RETURNED) {
            return;
        }

        $instance->loadMissing(['definition', 'version']);
        $graph = $instance->version?->graph();

        if ($graph === null || $graph->nodes() === []) {
            $this->fail($instance, 'Versi workflow tidak memiliki graf yang dapat dijalankan.');

            return;
        }

        $context = $context ?? $this->contexts->forInstance($instance);
        $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);
        $state = $this->state($instance);
        $round = max(1, (int) $instance->submission_number);

        $hops = 0;

        while ($hops < $policy->maxExecutionHops) {
            $hops++;
            $progressed = false;
            $waiting = false;
            $stop = false;

            $this->pruneUnreachable($graph, $state);

            foreach ($graph->nodes() as $node) {
                $key = (string) $node['key'];

                if ($this->isDone($state, $key) || in_array($key, $state['active'], true)) {
                    continue;
                }

                if (! $this->isReady($graph, $state, $key)) {
                    continue;
                }

                $outcome = $this->processNode($instance, $node, $context, $policy, $state, $round, $graph);
                $progressed = true;

                if ($outcome === 'wait') {
                    $waiting = true;

                    continue;
                }

                if ($outcome === 'finished' || $outcome === 'halted') {
                    $waiting = true;
                    $stop = true;

                    break;
                }
            }

            $state['hops'] = ($state['hops'] ?? 0) + 1;
            $this->persistState($instance, $state);

            if ($stop || $waiting || ! $progressed) {
                break;
            }
        }

        if ($hops >= $policy->maxExecutionHops) {
            $this->fail($instance, 'Batas perpindahan node tercapai — periksa kemungkinan loop pada definisi workflow.');
        }
    }

    /**
     * Tindak lanjut sebuah keputusan approver (§32 → §46).
     */
    public function handleDecision(
        WorkflowInstance $instance,
        WorkflowStepInstance $step,
        WorkflowDecision $decision,
        WorkflowContext $context,
        ?WorkflowPolicy $policy = null,
    ): void {
        $instance->loadMissing(['definition', 'version']);
        $policy ??= WorkflowPolicy::resolve($instance->definition, $instance->version);

        if ($decision->decision->returnsToRequester()) {
            $this->returnToRequester($instance, $step, $decision, $context, $policy);

            return;
        }

        if ($decision->decision === DecisionType::REJECT) {
            $threshold = ($step->quorum_policy ?? \App\Modules\Workflow\Domain\Enums\QuorumPolicy::ALL)
                ->rejectionThreshold((int) $step->total_approvers, (int) ($step->quorum_value ?? 0));

            if ((int) $step->rejections_count >= $threshold) {
                $this->rejectInstance($instance, $step, $decision, $context, $policy);
            }

            return;
        }

        if (! $decision->decision->isApproval()) {
            return;
        }

        $mode = $step->approval_mode ?? ApprovalMode::PARALLEL;
        $metadata = (array) $step->metadata;
        $required = (int) ($metadata['required_approvals'] ?? $this->steps->requiredApprovals($step, (int) $step->total_approvers));

        if ($mode === ApprovalMode::PARALLEL) {
            if ((int) $step->approvals_count >= $required) {
                $this->completeStep($instance, $step, $context, $policy);
            }

            return;
        }

        // SEQUENTIAL/HYBRID: tingkat/antrean berikutnya hanya dibuka ketika
        // kuorum tingkat berjalan sudah terpenuhi.
        if ((int) $step->approvals_count >= $required) {
            $advanced = $this->steps->advanceQueue($instance, $step->refresh(), $context, $policy);

            if ($advanced) {
                $this->notifyCurrentApprovers($instance, $step->refresh(), $context);
                $this->event($instance, $step, 'step.tier_advanced', ['node_key' => $step->node_key]);

                return;
            }

            $this->completeStep($instance, $step, $context, $policy);
        }
    }

    /** Step dilewati atas izin berwenang (§62). */
    public function skipStep(WorkflowInstance $instance, WorkflowStepInstance $step, WorkflowDecision $decision, WorkflowContext $context, string $reason): void
    {
        $instance->loadMissing(['definition', 'version']);
        $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);

        if ($step->slaRecord()->exists()) {
            $this->sla->cancel($step);
        }

        $state = $this->state($instance);
        $this->markSkipped($state, (string) $step->node_key);

        $step->forceFill([
            'status' => StepStatus::SKIPPED->value,
            'result' => StepResult::SKIPPED->value,
            'completed_at' => now(),
        ])->save();

        $this->audit->record(
            AuditAction::STEP_SKIPPED,
            $instance,
            $step,
            after: ['node_key' => $step->node_key, 'reason' => $reason],
            reason: $reason,
            actor: auth()->user() instanceof User ? auth()->user() : null,
            extra: ['step_instance_id' => $step->id],
        );

        $this->persistState($instance, $state);
        $this->run($instance, $context);
    }

    // -----------------------------------------------------------------
    // Pemrosesan node
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     * @return string waiting|continue|finished|halted
     */
    protected function processNode(
        WorkflowInstance $instance,
        array $node,
        WorkflowContext $context,
        WorkflowPolicy $policy,
        array &$state,
        int $round,
        WorkflowGraph $graph,
    ): string {
        $key = (string) $node['key'];
        $type = NodeType::tryFrom((string) $node['type']) ?? NodeType::AUTOMATIC;

        return match ($type) {
            NodeType::START => $this->processStart($instance, $node, $context, $policy, $state),
            NodeType::CONDITION => $this->processCondition($instance, $node, $context, $policy, $state, $graph),
            NodeType::PARALLEL => $this->processParallel($instance, $node, $context, $policy, $state),
            NodeType::NOTIFICATION, NodeType::AUTOMATIC => $this->processAutomatic($instance, $node, $context, $policy, $state),
            NodeType::APPROVAL, NodeType::REVIEW => $this->processApproval($instance, $node, $context, $policy, $state, $round),
            NodeType::END => $this->processEnd($instance, $node, $context, $policy, $state),
        };
    }

    protected function processStart(WorkflowInstance $instance, array $node, WorkflowContext $context, WorkflowPolicy $policy, array &$state): string
    {
        $this->markCompleted($state, (string) $node['key']);

        $instance->forceFill([
            'status' => InstanceStatus::IN_PROGRESS->value,
            'started_at' => $instance->started_at ?? now(),
        ])->save();

        $this->actions->run('enter', $instance, $context, null, null, $node);
        $this->event($instance, null, 'instance.started', ['node_key' => $node['key']]);

        return 'continue';
    }

    protected function processCondition(
        WorkflowInstance $instance,
        array $node,
        WorkflowContext $context,
        WorkflowPolicy $policy,
        array &$state,
        WorkflowGraph $graph,
    ): string {
        $key = (string) $node['key'];

        $branch = $this->conditions->resolveBranch(
            $graph,
            $key,
            $context->withMeta([
                'definition_code' => (string) $instance->definition?->code,
                'module' => (string) $instance->module,
                'node_key' => $key,
            ]),
            $instance->version,
        );

        if ($branch['target'] === null) {
            $this->fail(
                $instance,
                "Tidak ada cabang kondisi yang cocok pada node \"{$node['name']}\" — tambahkan cabang default pada definisi.",
            );

            return 'halted';
        }

        foreach ($branch['inactive'] as $edge) {
            $state['inactive_edges'][] = $edge['from_key'].'|'.$edge['to_key'];
        }

        $state['inactive_edges'] = array_values(array_unique($state['inactive_edges']));

        $this->markCompleted($state, $key);

        $this->event($instance, null, 'step.condition_evaluated', [
            'node_key' => $key,
            'matched' => $branch['matched'],
            'target' => $branch['target'],
            'deactivated_edges' => $branch['inactive'],
        ]);

        return 'continue';
    }

    protected function processParallel(WorkflowInstance $instance, array $node, WorkflowContext $context, WorkflowPolicy $policy, array &$state): string
    {
        $this->markCompleted($state, (string) $node['key']);

        $this->actions->run('enter', $instance, $context, null, null, $node);
        $this->event($instance, null, 'step.parallel_fanout', [
            'node_key' => $node['key'],
            'branches' => $instance->version?->graph()->successors((string) $node['key']) ?? [],
        ]);

        return 'continue';
    }

    protected function processAutomatic(WorkflowInstance $instance, array $node, WorkflowContext $context, WorkflowPolicy $policy, array &$state): string
    {
        $result = $this->actions->run('enter', $instance, $context, null, null, $node);

        $this->markCompleted($state, (string) $node['key']);

        $this->event($instance, null, 'step.automatic_executed', [
            'node_key' => $node['key'],
            'executed' => array_column($result['executed'], 'handler'),
            'failed' => array_column($result['failed'], 'handler'),
        ]);

        if ($result['failed'] !== [] && $policy->failsFastOnActionFailure()) {
            return 'halted';
        }

        return 'continue';
    }

    protected function processApproval(
        WorkflowInstance $instance,
        array $node,
        WorkflowContext $context,
        WorkflowPolicy $policy,
        array &$state,
        int $round,
    ): string {
        $key = (string) $node['key'];

        $existing = WorkflowStepInstance::query()
            ->where('instance_id', $instance->id)
            ->where('node_key', $key)
            ->where('round', $round)
            ->first();

        if ($existing !== null && $existing->status->isOpen()) {
            $state['active'][] = $key;

            return 'wait';
        }

        $result = $this->steps->activate($instance, $node, $context, $round);

        if ($result['status'] === 'failed') {
            return 'halted';
        }

        $state['active'][] = $key;

        return 'wait';
    }

    protected function processEnd(WorkflowInstance $instance, array $node, WorkflowContext $context, WorkflowPolicy $policy, array &$state): string
    {
        $this->markCompleted($state, (string) $node['key']);

        $this->actions->run('enter', $instance, $context, null, null, $node);

        $instance->forceFill([
            'status' => InstanceStatus::APPROVED->value,
            'completed_at' => now(),
            'current_node_keys' => [],
            'failure_reason' => null,
        ])->save();

        $this->actions->run('approved', $instance, $context, null, null, $node);
        $this->actions->run('completed', $instance, $context, null, null, $node);

        $this->audit->record(
            AuditAction::INSTANCE_COMPLETED,
            $instance,
            $instance,
            after: [
                'status' => InstanceStatus::APPROVED->value,
                'submission_number' => $instance->submission_number,
                'steps_completed' => count($state['completed']),
            ],
        );

        $this->event($instance, null, 'instance.completed', ['node_key' => $node['key']]);

        $this->notifications->dispatch(
            event: NotificationEvent::WORKFLOW_COMPLETED,
            instance: $instance,
            recipients: $this->notifications->defaultRecipients($instance, false),
            title: 'Request selesai: '.$instance->title,
            body: sprintf('%s telah disetujui penuh pada %s.', $instance->code, now()->translatedFormat('d M Y H:i')),
            payload: ['status' => InstanceStatus::APPROVED->value],
        );

        return 'finished';
    }

    // -----------------------------------------------------------------
    // Penyelesaian step & instance
    // -----------------------------------------------------------------

    public function completeStep(WorkflowInstance $instance, WorkflowStepInstance $step, WorkflowContext $context, WorkflowPolicy $policy): void
    {
        $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];

        if ($step->slaRecord()->exists()) {
            $this->sla->complete($step->refresh());
        }

        $step->forceFill([
            'status' => StepStatus::COMPLETED->value,
            'result' => $step->node_type === NodeType::REVIEW ? StepResult::COMPLETED->value : StepResult::APPROVED->value,
            'completed_at' => now(),
        ])->save();

        // Penugasan yang belum menjawab ditutup rapi (mis. kuorum ANY sudah terpenuhi).
        WorkflowApproval::query()
            ->where('step_instance_id', $step->id)
            ->where('status', ApprovalStatus::PENDING->value)
            ->update(['status' => ApprovalStatus::CANCELLED->value, 'responded_at' => now()]);

        $state = $this->state($instance);
        $this->markCompleted($state, (string) $step->node_key);
        $this->persistState($instance, $state);

        $instance->forceFill([
            'completed_steps' => min((int) $instance->total_steps, count($state['completed'])),
        ])->save();

        $this->actions->run('completed', $instance, $context, $step, null, $node);

        $this->audit->record(
            AuditAction::STEP_COMPLETED,
            $instance,
            $step,
            after: ['node_key' => $step->node_key, 'result' => $step->result?->value],
            extra: ['step_instance_id' => $step->id],
        );

        $this->run($instance, $context);
    }

    protected function returnToRequester(WorkflowInstance $instance, WorkflowStepInstance $step, WorkflowDecision $decision, WorkflowContext $context, WorkflowPolicy $policy): void
    {
        $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];

        if ($step->slaRecord()->exists()) {
            $this->sla->cancel($step);
        }

        $step->forceFill([
            'status' => StepStatus::RETURNED->value,
            'result' => $decision->decision === DecisionType::REQUEST_CHANGES ? StepResult::CHANGES_REQUESTED->value : StepResult::RETURNED->value,
            'completed_at' => now(),
        ])->save();

        $this->cancelOtherOpenSteps($instance, $step);

        $state = $this->state($instance);
        $this->removeActive($state, (string) $step->node_key);
        $this->persistState($instance, $state);

        $instance->forceFill([
            'status' => InstanceStatus::RETURNED->value,
            'returned_at' => now(),
            'current_node_keys' => [],
        ])->save();

        $this->actions->run('returned', $instance, $context, $step, $decision->actor, $node);

        $this->audit->record(
            AuditAction::INSTANCE_RETURNED,
            $instance,
            $step,
            after: [
                'decision' => $decision->decision->value,
                'comment' => $decision->comment,
                'strategy' => $policy->returnStrategy->value,
            ],
            reason: $decision->comment,
            actor: $decision->actor,
            extra: ['step_instance_id' => $step->id],
        );

        $this->event($instance, $step, 'instance.returned', [
            'decision' => $decision->decision->value,
            'comment' => $decision->comment,
            'strategy' => $policy->returnStrategy->value,
        ]);

        $this->notifications->dispatch(
            event: NotificationEvent::RETURNED,
            instance: $instance,
            recipients: array_values(array_unique(array_filter([
                (int) $instance->requester_id,
                (int) $decision->actor_id,
            ]))),
            title: 'Request dikembalikan untuk revisi: '.$instance->title,
            body: sprintf('%s dikembalikan pada step "%s". Catatan: %s', $instance->code, $step->name, $decision->comment ?? '-'),
            payload: ['node_key' => $step->node_key, 'decision' => $decision->decision->value],
            step: $step,
            keySuffix: 'returned:'.$decision->id,
        );
    }

    protected function rejectInstance(WorkflowInstance $instance, WorkflowStepInstance $step, WorkflowDecision $decision, WorkflowContext $context, WorkflowPolicy $policy): void
    {
        $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];

        if ($step->slaRecord()->exists()) {
            $this->sla->cancel($step);
        }

        $step->forceFill([
            'status' => StepStatus::REJECTED->value,
            'result' => StepResult::REJECTED->value,
            'completed_at' => now(),
        ])->save();

        $this->cancelOtherOpenSteps($instance, $step);

        $state = $this->state($instance);
        $this->removeActive($state, (string) $step->node_key);
        $this->persistState($instance, $state);

        $instance->forceFill([
            'status' => InstanceStatus::REJECTED->value,
            'completed_at' => now(),
            'current_node_keys' => [],
        ])->save();

        $this->actions->run('rejected', $instance, $context, $step, $decision->actor, $node);

        $this->audit->record(
            AuditAction::INSTANCE_REJECTED,
            $instance,
            $step,
            after: ['decision' => $decision->decision->value, 'comment' => $decision->comment],
            reason: $decision->comment,
            actor: $decision->actor,
            extra: ['step_instance_id' => $step->id],
        );

        $this->event($instance, $step, 'instance.rejected', [
            'node_key' => $step->node_key,
            'actor_id' => $decision->actor_id,
            'comment' => $decision->comment,
        ]);

        $this->notifications->dispatch(
            event: NotificationEvent::REJECTED,
            instance: $instance,
            recipients: $this->notifications->defaultRecipients($instance, false),
            title: 'Request ditolak: '.$instance->title,
            body: sprintf('%s ditolak pada step "%s". Alasan: %s', $instance->code, $step->name, $decision->comment ?? '-'),
            payload: ['node_key' => $step->node_key],
            step: $step,
            keySuffix: 'rejected:'.$decision->id,
        );
    }

    /** Batalkan seluruh step yang masih terbuka (dipakai cancel & terminal). */
    public function cancelOpenSteps(WorkflowInstance $instance, ?string $reason = null, ?WorkflowStepInstance $except = null): int
    {
        $steps = $instance->steps()
            ->whereIn('status', [
                StepStatus::PENDING->value,
                StepStatus::ACTIVE->value,
                StepStatus::WAITING_APPROVAL->value,
                StepStatus::WAITING_REVIEW->value,
                StepStatus::WAITING_FOR_APPROVER->value,
            ])
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except->id))
            ->get();

        foreach ($steps as $step) {
            if ($step->slaRecord()->exists()) {
                $this->sla->cancel($step);
            }

            $step->forceFill([
                'status' => StepStatus::CANCELLED->value,
                'result' => StepResult::CANCELLED->value,
                'completed_at' => now(),
            ])->save();

            WorkflowApproval::query()
                ->where('step_instance_id', $step->id)
                ->where('status', ApprovalStatus::PENDING->value)
                ->update(['status' => ApprovalStatus::CANCELLED->value, 'responded_at' => now()]);
        }

        $state = $this->state($instance);

        foreach ($steps as $step) {
            $this->removeActive($state, (string) $step->node_key);
            $this->markSkipped($state, (string) $step->node_key);
        }

        $this->persistState($instance, $state);

        if ($steps->isNotEmpty()) {
            $this->event($instance, null, 'steps.cancelled', [
                'node_keys' => $steps->pluck('node_key')->all(),
                'reason' => $reason,
            ]);
        }

        return $steps->count();
    }

    /** Tandai instance gagal (§58) — selalu terlihat, selalu beralasan. */
    public function fail(WorkflowInstance $instance, string $reason): void
    {
        if ($instance->status->isTerminal()) {
            return;
        }

        $this->cancelOpenSteps($instance, $reason);

        $instance->forceFill([
            'status' => InstanceStatus::FAILED->value,
            'failure_reason' => mb_substr($reason, 0, 290),
            'completed_at' => now(),
        ])->save();

        $this->audit->record(
            AuditAction::INSTANCE_FAILED,
            $instance,
            $instance,
            after: ['reason' => $reason],
            reason: $reason,
        );

        $this->event($instance, null, 'instance.failed', ['reason' => $reason]);

        $this->notifications->dispatch(
            event: NotificationEvent::WORKFLOW_FAILED,
            instance: $instance,
            recipients: $this->notifications->defaultRecipients($instance),
            title: 'Workflow gagal: '.$instance->code,
            body: $reason,
            payload: ['reason' => $reason],
            keySuffix: 'failed:'.sha1($reason),
        );
    }

    /** Approver baru diberi tahu setelah antrean bertahap maju. */
    protected function notifyCurrentApprovers(WorkflowInstance $instance, WorkflowStepInstance $step, WorkflowContext $context): void
    {
        $ids = $step->approvals()->where('status', ApprovalStatus::PENDING->value)->pluck('approver_id')->all();

        if ($ids === []) {
            return;
        }

        $this->notifications->dispatch(
            event: NotificationEvent::APPROVAL_REQUESTED,
            instance: $instance,
            recipients: $ids,
            title: 'Permintaan persetujuan: '.$instance->title,
            body: sprintf('%s kini menunggu keputusan Anda pada step "%s".', $instance->code, $step->name),
            payload: ['node_key' => $step->node_key],
            step: $step,
            keySuffix: 'requested:'.$step->id.':'.count($ids),
        );
    }

    // -----------------------------------------------------------------
    // Keadaan eksekusi
    // -----------------------------------------------------------------

    /** @return array{completed: list<string>, skipped: list<string>, active: list<string>, inactive_edges: list<string>, hops: int} */
    public function state(WorkflowInstance $instance): array
    {
        $state = (array) ($instance->execution_state ?? []);

        return [
            'completed' => array_values(array_unique(array_map('strval', (array) ($state['completed'] ?? [])))),
            'skipped' => array_values(array_unique(array_map('strval', (array) ($state['skipped'] ?? [])))),
            'active' => array_values(array_unique(array_map('strval', (array) ($state['active'] ?? [])))),
            'inactive_edges' => array_values(array_unique(array_map('strval', (array) ($state['inactive_edges'] ?? [])))),
            'hops' => (int) ($state['hops'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $state */
    public function persistState(WorkflowInstance $instance, array $state): void
    {
        $instance->forceFill([
            'execution_state' => $state,
            'current_node_keys' => $state['active'] === [] ? [] : array_values($state['active']),
        ])->save();
    }

    /** @param array<string, mixed> $state */
    protected function markCompleted(array &$state, string $key): void
    {
        $this->removeActive($state, $key);

        if (! in_array($key, $state['completed'], true)) {
            $state['completed'][] = $key;
        }

        $state['completed'] = array_values(array_unique($state['completed']));
    }

    /** @param array<string, mixed> $state */
    protected function markSkipped(array &$state, string $key): void
    {
        $this->removeActive($state, $key);

        if (! in_array($key, $state['skipped'], true)) {
            $state['skipped'][] = $key;
        }

        $state['skipped'] = array_values(array_unique($state['skipped']));
    }

    /** @param array<string, mixed> $state */
    protected function removeActive(array &$state, string $key): void
    {
        $state['active'] = array_values(array_diff($state['active'], [$key]));
    }

    /** @param array<string, mixed> $state */
    protected function isDone(array $state, string $key): bool
    {
        return in_array($key, $state['completed'], true) || in_array($key, $state['skipped'], true);
    }

    /** @param array<string, mixed> $state */
    protected function edgeInactive(array $state, array $edge): bool
    {
        return in_array($edge['from_key'].'|'.$edge['to_key'], $state['inactive_edges'], true);
    }

    /** @param array<string, mixed> $state */
    protected function isReady(WorkflowGraph $graph, array $state, string $key): bool
    {
        $incoming = array_values(array_filter(
            $graph->incoming($key),
            fn (array $edge) => ! $this->edgeInactive($state, $edge),
        ));

        $startKey = (string) ($graph->startNode()['key'] ?? '');

        if ($incoming === []) {
            return $key === $startKey;
        }

        $sources = array_unique(array_map(fn (array $edge) => (string) $edge['from_key'], $incoming));

        // Sebuah node aktif ketika **seluruh** cabang masuk yang masih hidup
        // sudah berakhir — selesai (completed) maupun dilewati (skipped).
        // Cabang yang benar-benar mati sudah ditangani `pruneUnreachable()`.
        foreach ($sources as $source) {
            if (! in_array($source, $state['completed'], true) && ! in_array($source, $state['skipped'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Node yang seluruh cabang masuknya dinonaktifkan dianggap dilewati —
     * bukan menggantung selamanya (§55: tidak ada node tak terjangkau).
     *
     * @param array<string, mixed> $state
     */
    protected function pruneUnreachable(WorkflowGraph $graph, array &$state): void
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($graph->nodes() as $node) {
                $key = (string) $node['key'];

                if ($this->isDone($state, $key) || in_array($key, $state['active'], true)) {
                    continue;
                }

                $incoming = $graph->incoming($key);

                if ($incoming === []) {
                    continue;
                }

                $activeIncoming = array_filter($incoming, fn (array $edge) => ! $this->edgeInactive($state, $edge));

                if ($activeIncoming === []) {
                    $this->markSkipped($state, $key);
                    $changed = true;
                }
            }
        }
    }

    protected function event(WorkflowInstance $instance, ?WorkflowStepInstance $step, string $type, array $payload = []): void
    {
        WorkflowEvent::query()->create([
            'instance_id' => $instance->id,
            'definition_id' => $instance->definition_id,
            'step_instance_id' => $step?->id,
            'type' => $type,
            'actor_id' => auth()->id(),
            'payload' => $payload,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Batalkan step lain ketika instance berakhir (paralel yang tertinggal). */
    protected function cancelOtherOpenSteps(WorkflowInstance $instance, WorkflowStepInstance $except): void
    {
        WorkflowApproval::query()
            ->where('instance_id', $instance->id)
            ->where('step_instance_id', '!=', $except->id)
            ->where('status', ApprovalStatus::PENDING->value)
            ->update(['status' => ApprovalStatus::CANCELLED->value, 'responded_at' => now()]);

        $others = $instance->steps()
            ->where('id', '!=', $except->id)
            ->whereIn('status', [
                StepStatus::ACTIVE->value,
                StepStatus::WAITING_APPROVAL->value,
                StepStatus::WAITING_REVIEW->value,
                StepStatus::WAITING_FOR_APPROVER->value,
            ])
            ->get();

        foreach ($others as $step) {
            if ($step->slaRecord()->exists()) {
                $this->sla->cancel($step);
            }

            $step->forceFill([
                'status' => StepStatus::CANCELLED->value,
                'result' => StepResult::CANCELLED->value,
                'completed_at' => now(),
            ])->save();
        }
    }
}
