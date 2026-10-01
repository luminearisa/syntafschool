<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Application\Support\ApproverResolution;
use App\Modules\Workflow\Application\Support\WorkflowPolicy;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\ApprovalMode;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Enums\EmptyApproverPolicy;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Enums\NodeType;
use App\Modules\Workflow\Domain\Enums\QuorumPolicy;
use App\Modules\Workflow\Domain\Enums\StepStatus;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowEvent;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Support\Facades\DB;

/**
 * Mengaktifkan step persetujuan: dari konfigurasi node menjadi penugasan nyata.
 *
 * Tanggung jawabnya:
 *  - resolusi approver (delegasi sudah diterapkan oleh resolver),
 *  - pembuatan baris `workflow_step_instances` + `workflow_approvals`,
 *  - antrean bertahap untuk mode SEQUENTIAL/HYBRID,
 *  - penerapan **kebijakan approver kosong** (§59) — FAIL/ESCALATE/USE_FALLBACK/PAUSE,
 *  - pencatatan SLA dan notifikasi permintaan persetujuan.
 */
class WorkflowStepService
{
    public function __construct(
        private readonly ApproverResolverService $resolvers,
        private readonly SlaService $sla,
        private readonly WorkflowNotificationService $notifications,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Aktifkan sebuah node persetujuan/peninjauan.
     *
     * @param  array<string, mixed>  $node
     * @return array{status: string, step: WorkflowStepInstance, warnings: list<string>}
     */
    public function activate(WorkflowInstance $instance, array $node, WorkflowContext $context, int $round): array
    {
        $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);

        $context = $context->withMeta([
            'definition_code' => (string) $instance->definition?->code,
            'module' => (string) $instance->module,
            'node_key' => (string) $node['key'],
        ]);

        $type = NodeType::tryFrom((string) $node['type']) ?? NodeType::APPROVAL;

        $step = WorkflowStepInstance::query()->create([
            'instance_id' => $instance->id,
            'node_key' => (string) $node['key'],
            'node_type' => $type->value,
            'name' => (string) ($node['name'] ?? $node['key']),
            'sequence' => (int) ($node['sequence'] ?? 0),
            'round' => $round,
            'status' => StepStatus::ACTIVE->value,
            'approval_mode' => ($node['approval_mode'] ?? null) ?: ApprovalMode::PARALLEL->value,
            'quorum_policy' => ($node['quorum_policy'] ?? null) ?: QuorumPolicy::ALL->value,
            'quorum_value' => $node['quorum_value'] ?? null,
            'required' => (bool) ($node['required'] ?? true),
            'activated_at' => now(),
            'metadata' => ['warnings' => []],
        ]);

        $resolution = $this->resolvers->resolveForNode($node, $context, $policy->allowSelfApproval);
        $warnings = $resolution->warnings;

        if ($resolution->isEmpty()) {
            return $this->handleEmptyApprovers($instance, $step, $node, $context, $resolution, $policy);
        }

        $this->assignApprovers($instance, $step, $node, $context, $resolution, $policy);

        $instance->forceFill([
            'status' => $type === NodeType::REVIEW ? InstanceStatus::WAITING_REVIEW->value : InstanceStatus::WAITING_APPROVAL->value,
            'current_node_keys' => [(string) $node['key']],
        ])->save();

        $this->sla->start($instance, $step, $node, $policy->calendarCode);

        $this->notifyApprovers($instance, $step, $node, $context);

        $this->audit->record(
            AuditAction::STEP_ACTIVATED,
            $instance,
            $step,
            after: [
                'node_key' => $step->node_key,
                'total_approvers' => $step->total_approvers,
                'quorum' => $step->quorum_policy?->value,
                'warnings' => $warnings,
            ],
            actor: $context->meta()['actor_id'] ?? null,
            extra: ['step_instance_id' => $step->id],
        );

        $this->event($instance, $step, 'step.activated', [
            'node_key' => $step->node_key,
            'approvers' => $resolution->userIds(),
            'warnings' => $warnings,
        ]);

        return ['status' => 'waiting', 'step' => $step->refresh(), 'warnings' => $warnings];
    }

    /**
     * Buat penugasan approver sesuai mode.
     *
     * @param  array<string, mixed>  $node
     */
    protected function assignApprovers(
        WorkflowInstance $instance,
        WorkflowStepInstance $step,
        array $node,
        WorkflowContext $context,
        ApproverResolution $resolution,
        WorkflowPolicy $policy,
    ): void {
        $mode = $step->approval_mode ?? ApprovalMode::PARALLEL;
        $tiers = $resolution->tierUserIds();
        $byUser = [];

        foreach ($resolution->approvers as $approver) {
            $byUser[$approver->userId] = $approver;
        }

        $totalAssigned = 0;

        if ($mode === ApprovalMode::SEQUENTIAL) {
            // Satu approver pada satu waktu; sisanya menunggu di antrean.
            $queue = $resolution->userIds();
            $first = array_shift($queue);

            if ($first !== null && isset($byUser[$first])) {
                $this->createApproval($instance, $step, $byUser[$first], $node, $policy);
                $totalAssigned = 1;
            }

            $this->storeQueue($step, $queue, [$resolution->userIds()]);
        } elseif ($mode === ApprovalMode::HYBRID && count($tiers) > 0) {
            $firstTier = array_values(array_filter($tiers[0] ?? [], fn (int $id) => isset($byUser[$id])));

            foreach ($firstTier as $userId) {
                $this->createApproval($instance, $step, $byUser[$userId], $node, $policy);
                $totalAssigned++;
            }

            $remainingTiers = array_map(
                fn (array $ids) => array_values(array_filter($ids, fn (int $id) => isset($byUser[$id]))),
                array_slice($tiers, 1),
            );

            $this->storeQueue($step, array_merge(...$remainingTiers ?: [[]]), $remainingTiers, tierIndex: 1);
        } else {
            foreach ($resolution->approvers as $approver) {
                $this->createApproval($instance, $step, $approver, $node, $policy);
                $totalAssigned++;
            }

            $this->storeQueue($step, [], [$resolution->userIds()]);
        }

        $mode = $step->approval_mode ?? ApprovalMode::PARALLEL;
        // Mode bertahap menilai kuorum per tingkat: satu approver = satu tingkat.
        $required = $mode === ApprovalMode::SEQUENTIAL
            ? 1
            : $this->requiredApprovals($step, $totalAssigned);

        $step->forceFill([
            'total_approvers' => $totalAssigned,
            'status' => StepStatus::WAITING_APPROVAL->value,
            'metadata' => array_merge((array) $step->metadata, [
                'mode' => $mode->value,
                'required_approvals' => $required,
                'assigned_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $instance->forceFill([
            'execution_state' => array_merge((array) $instance->execution_state, [
                'active' => array_values(array_unique(array_merge(
                    (array) ($instance->execution_state['active'] ?? []),
                    [(string) $step->node_key],
                ))),
            ]),
        ])->save();
    }

    /** Buat satu baris penugasan approval. */
    protected function createApproval(
        WorkflowInstance $instance,
        WorkflowStepInstance $step,
        ResolvedApprover $approver,
        array $node,
        WorkflowPolicy $policy,
    ): WorkflowApproval {
        $approval = WorkflowApproval::query()->firstOrCreate(
            ['step_instance_id' => $step->id, 'approver_id' => $approver->userId],
            [
                'instance_id' => $instance->id,
                'status' => 'PENDING',
                'source' => $approver->source->value,
                'resolver' => $approver->resolver,
                'resolver_spec' => $approver->spec,
                'is_delegate' => $approver->isDelegate,
                'original_approver_id' => $approver->originalUserId,
                'delegation_id' => $approver->delegationId,
                'assigned_at' => now(),
            ],
        );

        $this->audit->record(
            AuditAction::APPROVAL_ASSIGNED,
            $instance,
            $approval,
            after: [
                'approver_id' => $approver->userId,
                'source' => $approver->source->value,
                'resolver' => $approver->resolver,
                'original_approver_id' => $approver->originalUserId,
                'delegation_id' => $approver->delegationId,
                'note' => $approver->note,
            ],
            extra: ['step_instance_id' => $step->id],
        );

        return $approval;
    }

    /**
     * Antrean approver untuk mode bertahap.
     *
     * @param  list<int>  $queue
     * @param  list<list<int>>  $tiers
     */
    protected function storeQueue(WorkflowStepInstance $step, array $queue, array $tiers, int $tierIndex = 0): void
    {
        $step->forceFill([
            'metadata' => array_merge((array) $step->metadata, [
                'queue' => array_values($queue),
                'tiers' => array_values($tiers),
                'tier_index' => $tierIndex,
            ]),
        ])->save();
    }

    public function requiredApprovals(WorkflowStepInstance $step, int $total): int
    {
        $policy = $step->quorum_policy ?? QuorumPolicy::ALL;

        return $policy->requiredApprovals($total, (int) ($step->quorum_value ?? 0));
    }

    /**
     * Aktifkan approver berikutnya pada mode SEQUENTIAL/HYBRID.
     *
     * @return bool true bila masih ada approver yang ditugaskan
     */
    public function advanceQueue(WorkflowInstance $instance, WorkflowStepInstance $step, WorkflowContext $context, WorkflowPolicy $policy): bool
    {
        $metadata = (array) $step->metadata;
        $queue = array_values(array_filter((array) ($metadata['queue'] ?? []), fn ($id) => (int) $id > 0));
        $tiers = (array) ($metadata['tiers'] ?? []);

        if ($step->approval_mode === ApprovalMode::HYBRID) {
            // Satu tingkat berikutnya ditugaskan sekaligus (paralel per tingkat).
            $nextTier = [];
            $index = (int) ($metadata['tier_index'] ?? 0);

            if (array_key_exists($index, $tiers)) {
                $nextTier = array_map('intval', (array) $tiers[$index]);
                unset($tiers[$index]);
            }

            if ($nextTier === []) {
                return false;
            }

            foreach ($nextTier as $userId) {
                $user = User::query()->find($userId);

                if ($user === null) {
                    continue;
                }

                $this->createApproval($instance, $step, new ResolvedApprover(
                    userId: $userId,
                    source: ApproverSource::USER,
                    resolver: 'queue',
                    note: 'Antrean tingkat '.($index + 1),
                ), [], $policy);
            }

            $step->forceFill([
                'total_approvers' => (int) $step->total_approvers + count($nextTier),
                'rejections_count' => 0,
                'approvals_count' => 0,
                'metadata' => array_merge($metadata, [
                    'tiers' => array_values($tiers),
                    'tier_index' => $index + 1,
                    'required_approvals' => $this->requiredApprovals($step, count($nextTier)),
                ]),
            ])->save();

            return true;
        }

        $next = array_shift($queue);

        if ($next === null) {
            return false;
        }

        $user = User::query()->find((int) $next);

        if ($user === null) {
            return false;
        }

        $this->createApproval($instance, $step, new ResolvedApprover(
            userId: (int) $next,
            source: ApproverSource::USER,
            resolver: 'queue',
            note: 'Antrean bertahap',
        ), [], $policy);

        // Tingkat baru = penilaian kuorum baru; hitungan tingkat sebelumnya
        // tetap tersimpan pada riwayat keputusan, tidak dihapus.
        $step->forceFill([
            'total_approvers' => (int) $step->total_approvers + 1,
            'approvals_count' => 0,
            'rejections_count' => 0,
            'metadata' => array_merge($metadata, [
                'queue' => array_values($queue),
                'required_approvals' => 1,
                'tier_history' => array_merge((array) ($metadata['tier_history'] ?? []), [[
                    'approvals' => (int) $step->approvals_count,
                    'rejections' => (int) $step->rejections_count,
                    'closed_at' => now()->toIso8601String(),
                ]]),
            ]),
        ])->save();

        return true;
    }

    /**
     * Kebijakan approver kosong (§59).
     *
     * Tidak ada satu pun jalur di sini yang "melewati" step: pilihannya adalah
     * menggagalkan request, menahannya, memakai cadangan, atau menaikkannya.
     *
     * @param  array<string, mixed>  $node
     * @return array{status: string, step: WorkflowStepInstance, warnings: list<string>}
     */
    protected function handleEmptyApprovers(
        WorkflowInstance $instance,
        WorkflowStepInstance $step,
        array $node,
        WorkflowContext $context,
        ApproverResolution $resolution,
        WorkflowPolicy $policy,
    ): array {
        $warnings = $resolution->warnings;
        $policyName = $policy->emptyApproverPolicy;

        if ($policyName === EmptyApproverPolicy::USE_FALLBACK || $policyName === EmptyApproverPolicy::ESCALATE) {
            $fallbackSpecs = $policyName === EmptyApproverPolicy::USE_FALLBACK
                ? (array) ($node['config']['fallback_approvers'] ?? [])
                : $this->escalationSpecs($instance);

            if ($fallbackSpecs !== []) {
                $fallback = $this->resolvers->resolveSpecs($fallbackSpecs, $context->withMeta([
                    'definition_code' => (string) $instance->definition?->code,
                    'module' => (string) $instance->module,
                    'node_key' => (string) $node['key'],
                ]));

                if (! $fallback->isEmpty()) {
                    $warnings[] = $policyName === EmptyApproverPolicy::USE_FALLBACK
                        ? 'Tidak ada approver utama; memakai approver cadangan.'
                        : 'Tidak ada approver utama; dinaikkan ke atasan.';

                    $this->assignApprovers($instance, $step, $node, $context, $fallback, $policy);

                    $instance->forceFill([
                        'status' => InstanceStatus::WAITING_APPROVAL->value,
                        'current_node_keys' => [(string) $node['key']],
                    ])->save();

                    $this->sla->start($instance, $step, $node, $policy->calendarCode);
                    $this->notifyApprovers($instance, $step, $node, $context);
                    $this->blocked($instance, $step, $warnings);

                    return ['status' => 'waiting', 'step' => $step->refresh(), 'warnings' => $warnings];
                }
            }
        }

        if ($policyName === EmptyApproverPolicy::PAUSE) {
            $step->forceFill([
                'status' => StepStatus::WAITING_FOR_APPROVER->value,
                'metadata' => array_merge((array) $step->metadata, ['warnings' => $warnings]),
            ])->save();

            $instance->forceFill([
                'status' => InstanceStatus::WAITING_FOR_APPROVER->value,
                'current_node_keys' => [(string) $node['key']],
                'execution_state' => array_merge((array) $instance->execution_state, [
                    'active' => array_values(array_unique(array_merge(
                        (array) ($instance->execution_state['active'] ?? []),
                        [(string) $node['key']],
                    ))),
                ]),
            ])->save();

            $this->blocked($instance, $step, $warnings);

            return ['status' => 'paused', 'step' => $step->refresh(), 'warnings' => $warnings];
        }

        // FAIL (atau cadangan/eskalasi yang juga tidak menemukan siapa pun).
        $step->forceFill([
            'status' => StepStatus::FAILED->value,
            'result' => 'FAILED',
            'completed_at' => now(),
            'metadata' => array_merge((array) $step->metadata, ['warnings' => $warnings]),
        ])->save();

        $instance->forceFill([
            'status' => InstanceStatus::FAILED->value,
            'failure_reason' => 'Tidak ada approver yang memenuhi syarat pada step '.$step->name.'.',
        ])->save();

        $this->blocked($instance, $step, $warnings);

        return ['status' => 'failed', 'step' => $step->refresh(), 'warnings' => $warnings];
    }

    /**
     * Spesifikasi eskalasi: atasan requester, atau pemegang izin kelola workflow
     * sebagai jalan terakhir — selalu manusia, tidak pernah "dilewati".
     *
     * @return list<array<string, mixed>>
     */
    protected function escalationSpecs(WorkflowInstance $instance): array
    {
        $specs = [
            ['resolver' => 'manager', 'levels' => 1],
        ];

        if ($instance->definition?->owner_user_id !== null) {
            $specs[] = ['resolver' => 'user', 'user_id' => $instance->definition->owner_user_id];
        }

        return $specs;
    }

    /** Catat bahwa step tidak dapat berjalan sendiri (butuh admin). */
    protected function blocked(WorkflowInstance $instance, WorkflowStepInstance $step, array $warnings): void
    {
        $this->audit->record(
            AuditAction::STEP_BLOCKED,
            $instance,
            $step,
            after: ['warnings' => $warnings],
            extra: ['step_instance_id' => $step->id],
        );

        $this->event($instance, $step, 'step.blocked', ['warnings' => $warnings]);

        $this->notifications->dispatch(
            event: NotificationEvent::WORKFLOW_FAILED,
            instance: $instance,
            recipients: $this->managers($instance),
            title: 'Workflow perlu tindakan: '.$instance->code,
            body: implode(' ', $warnings) ?: 'Step tidak menemukan approver.',
            payload: ['node_key' => $step->node_key, 'warnings' => $warnings],
            step: $step,
            keySuffix: 'blocked',
        );
    }

    protected function notifyApprovers(WorkflowInstance $instance, WorkflowStepInstance $step, array $node, WorkflowContext $context): void
    {
        $approverIds = $step->approvals()->where('status', 'PENDING')->pluck('approver_id')->all();

        if ($approverIds === []) {
            return;
        }

        $instance->loadMissing('definition');

        $this->notifications->dispatch(
            event: NotificationEvent::APPROVAL_REQUESTED,
            instance: $instance,
            recipients: $approverIds,
            title: 'Permintaan persetujuan: '.$instance->title,
            body: sprintf(
                '%s menunggu keputusan Anda pada step "%s".',
                $instance->code,
                $step->name,
            ),
            payload: [
                'node_key' => $step->node_key,
                'amount' => $instance->amount,
                'priority' => $instance->priority?->value,
                'definition' => $instance->definition?->code,
            ],
            step: $step,
            keySuffix: 'requested:'.$step->id,
            actionUrl: '/workflow/approvals',
        );

        WorkflowApproval::query()
            ->where('step_instance_id', $step->id)
            ->where('status', 'PENDING')
            ->update(['notified_at' => now()]);
    }

    /** @return list<int> */
    protected function managers(WorkflowInstance $instance): array
    {
        return User::query()
            ->where('is_active', true)
            ->permission('workflow.approval.reassign')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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

    /**
     * Penetapan approver manual oleh workflow manager pada step yang menunggu
     * penetapan (§58) atau penggantian approver (§61).
     *
     * @param  list<int>  $userIds
     */
    public function assignManually(WorkflowInstance $instance, WorkflowStepInstance $step, array $userIds, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($instance, $step, $userIds, $actor, $reason) {
            $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);
            $created = [];

            foreach (array_unique($userIds) as $userId) {
                $user = User::query()->find((int) $userId);

                if ($user === null || ! $user->is_active) {
                    continue;
                }

                $created[] = $this->createApproval($instance, $step, new ResolvedApprover(
                    userId: (int) $userId,
                    source: ApproverSource::MANUAL,
                    resolver: 'manual',
                    spec: ['assigned_by' => $actor->id],
                    note: $reason,
                ), [], $policy);
            }

            $step->forceFill([
                'status' => StepStatus::WAITING_APPROVAL->value,
                'total_approvers' => (int) $step->total_approvers + count($created),
                'metadata' => array_merge((array) $step->metadata, [
                    'required_approvals' => $this->requiredApprovals($step, (int) $step->total_approvers + count($created)),
                    'manually_assigned_by' => $actor->id,
                    'manual_reason' => $reason,
                ]),
            ])->save();

            $instance->forceFill([
                'status' => $step->node_type === NodeType::REVIEW
                    ? InstanceStatus::WAITING_REVIEW->value
                    : InstanceStatus::WAITING_APPROVAL->value,
                'failure_reason' => null,
                'execution_state' => array_merge((array) $instance->execution_state, [
                    'active' => array_values(array_unique(array_merge(
                        (array) ($instance->execution_state['active'] ?? []),
                        [(string) $step->node_key],
                    ))),
                ]),
            ])->save();

            if ($step->slaRecord()->doesntExist()) {
                $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];
                $this->sla->start($instance, $step, $node, $policy->calendarCode);
            }

            $this->audit->record(
                AuditAction::APPROVER_REASSIGNED,
                $instance,
                $step,
                after: ['assigned_user_ids' => array_map(fn ($a) => $a->approver_id, $created)],
                reason: $reason,
                actor: $actor,
                extra: ['step_instance_id' => $step->id],
            );

            return $created;
        });
    }
}
