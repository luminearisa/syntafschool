<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Application\Support\WorkflowPolicy;
use App\Modules\Workflow\Domain\Enums\ApprovalStatus;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Enums\StepStatus;
use App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation;
use App\Modules\Workflow\Domain\Exceptions\WorkflowAccessDenied;
use App\Modules\Workflow\Domain\Exceptions\WorkflowConflict;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowDecision;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Pencatat keputusan approver (§32) dengan tiga jaminan produksi:
 *
 * 1. **Idempotensi (§64)** — kunci idempoten unik + kunci baris basis data.
 *    Menekan "Setujui" dua kali, atau mengulang permintaan setelah timeout,
 *    hanya menghasilkan satu keputusan; pengulangan menerima hasil yang sama.
 * 2. **Otorisasi berlapis (§36, §37, §38)** — approver yang ditugaskan, delegasi
 *    aktif, izin `workflow.approval.override` (selalu beralasan), larangan
 *    self-approval, dan aturan Segregation of Duties.
 * 3. **Riwayat tak dapat diubah (§47)** — keputusan ditulis sekali, lengkap
 *    dengan IP, user agent, identitas pemilik kewenangan, dan rujukan delegasi.
 */
class ApprovalService
{
    public function __construct(
        private readonly WorkflowExecutionService $execution,
        private readonly WorkflowNotificationService $notifications,
        private readonly WorkflowAuditService $audit,
        private readonly DelegationService $delegations,
    ) {
    }

    /**
     * Catat satu keputusan.
     *
     * @param  array<string, mixed>  $meta
     */
    public function decide(
        WorkflowApproval $approval,
        User $actor,
        DecisionType $decision,
        ?string $comment = null,
        array $meta = [],
    ): WorkflowDecision {
        $idempotencyKey = $meta['idempotency_key'] ?? null;

        // Pemutaran ulang idempoten: kembalikan keputusan yang sudah ada.
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = WorkflowDecision::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($approval, $actor, $decision, $comment, $meta, $idempotencyKey) {
            /** @var WorkflowApproval $locked */
            $locked = WorkflowApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['instance.definition', 'instance.version', 'step']);

            $instance = $locked->instance;
            $step = $locked->step;
            $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);

            if (! $locked->status->isPending()) {
                $existing = WorkflowDecision::query()->where('approval_id', $locked->id)->latest('id')->first();

                if ($existing !== null) {
                    throw WorkflowConflict::alreadyDecided($existing);
                }

                throw WorkflowConflict::locked('Penugasan ini sudah tidak menunggu keputusan.');
            }

            if (! $instance->status->isOpen()) {
                throw WorkflowConflict::notOpen($instance);
            }

            $authorized = $this->assertAuthorized($locked, $instance, $actor, $policy, $meta);
            $selfOverride = $this->assertSelfApproval($instance, $actor, $step, $policy, $meta);
            $isOverride = $authorized['is_override'] || $selfOverride;
            // Identitas pemilik kewenangan: dari penugasan (delegasi yang
            // sudah diterapkan saat resolusi) atau dari jalur delegasi/override.
            $originalApproverId = $authorized['original_approver_id']
                ?? ($locked->original_approver_id === null ? null : (int) $locked->original_approver_id);
            $this->assertSegregationOfDuties($instance, $actor, $step, $policy, $meta);

            if ($policy->requiresComment($decision->value) && ($comment === null || trim($comment) === '')) {
                throw InvalidWorkflowOperation::make(
                    'Keputusan '.$decision->label().' mewajibkan komentar/alasan.',
                    ['decision' => $decision->value],
                );
            }

            $context = app(WorkflowContextService::class)->forInstance($instance);

            $decisionRow = WorkflowDecision::query()->create([
                'instance_id' => $instance->id,
                'step_instance_id' => $step->id,
                'approval_id' => $locked->id,
                'actor_id' => $actor->id,
                'original_approver_id' => $originalApproverId,
                'decision' => $decision->value,
                'comment' => $comment,
                'is_override' => $isOverride,
                'override_reason' => $meta['override_reason'] ?? null,
                'delegation_id' => $locked->delegation_id,
                'escalation_id' => $locked->escalation_id,
                'idempotency_key' => $idempotencyKey,
                'ip_address' => $meta['ip_address'] ?? request()?->ip(),
                'user_agent' => mb_substr((string) ($meta['user_agent'] ?? request()?->userAgent()), 0, 250),
                'metadata' => $meta['metadata'] ?? null,
                'decided_at' => now(),
                'created_at' => now(),
            ]);

            $this->applyToApproval($locked, $decision, $decisionRow);
            $this->applyToStep($step, $decision, $actor);

            $this->audit->record(
                AuditAction::DECISION_RECORDED,
                $instance,
                $decisionRow,
                after: [
                    'decision' => $decision->value,
                    'actor_id' => $actor->id,
                    'original_approver_id' => $originalApproverId,
                    'delegation_id' => $locked->delegation_id,
                    'comment' => $comment,
                ],
                reason: $comment,
                actor: $actor,
                extra: ['step_instance_id' => $step->id, 'target_type' => WorkflowDecision::class, 'target_id' => (string) $decisionRow->id],
            );

            $instance->forceFill(['last_decision_at' => now()])->save();

            $this->execution->handleDecision($instance->refresh(), $step->refresh(), $decisionRow, $context, $policy);

            return $decisionRow;
        });
    }

    /**
     * Pastikan aktor berhak memutuskan penugasan ini.
     *
     * @param  array<string, mixed>  $meta
     * @return array{original_approver_id: int|null, is_override: bool}
     */
    protected function assertAuthorized(WorkflowApproval $approval, WorkflowInstance $instance, User $actor, WorkflowPolicy $policy, array $meta): array
    {
        if ($approval->approver_id === $actor->id) {
            return ['original_approver_id' => null, 'is_override' => false];
        }

        // 1. Delegasi aktif dari pemegang tugas kepada aktor.
        $context = app(WorkflowContextService::class)->forInstance($instance)->withMeta([
            'definition_code' => (string) $instance->definition?->code,
            'module' => (string) $instance->module,
            'node_key' => (string) $approval->step?->node_key,
        ]);

        $delegation = $this->delegations->activeDelegationFor((int) $approval->approver_id, $context);

        if ($delegation !== null && (int) $delegation->delegate_id === $actor->id) {
            return ['original_approver_id' => (int) $approval->approver_id, 'is_override' => false];
        }

        // 2. Override resmi oleh pemegang izin, dengan alasan tertulis.
        if ($actor->can('workflow.approval.override') && ($meta['override_reason'] ?? null) !== null) {
            return ['original_approver_id' => (int) $approval->approver_id, 'is_override' => true];
        }

        throw WorkflowAccessDenied::outOfScope();
    }

    /** §36 — requester tidak boleh menyetujui requestnya sendiri. */
    protected function assertSelfApproval(WorkflowInstance $instance, User $actor, WorkflowStepInstance $step, WorkflowPolicy $policy, array $meta): bool
    {
        if ((int) $instance->requester_id !== $actor->id) {
            return false;
        }

        $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];
        $rule = strtoupper((string) ($node['self_approval_rule'] ?? 'INHERIT'));

        $allowed = match ($rule) {
            'ALLOW' => true,
            'FORBID' => false,
            default => $policy->allowSelfApproval,
        };

        if ($allowed) {
            return false;
        }

        if ($actor->can('workflow.approval.override') && ($meta['override_reason'] ?? null) !== null) {
            return true;
        }

        throw WorkflowAccessDenied::selfApproval();
    }

    /**
     * §37 — Segregation of Duties yang dapat dikonfigurasi definisi:
     *
     * ```json
     * { "sod": { "requester_cannot_be_final_approver": true,
     *            "forbid_same_user_multiple_steps": true,
     *            "min_distinct_approvers": 2 } }
     * ```
     */
    protected function assertSegregationOfDuties(WorkflowInstance $instance, User $actor, WorkflowStepInstance $step, WorkflowPolicy $policy, array $meta): void
    {
        $rules = (array) ($instance->definition?->settings['sod'] ?? $instance->version?->config['sod'] ?? []);

        if ($rules === []) {
            return;
        }

        $override = $actor->can('workflow.approval.override') && ($meta['override_reason'] ?? null) !== null;

        if (($rules['requester_cannot_be_final_approver'] ?? false) && (int) $instance->requester_id === $actor->id) {
            if (! $override && $this->isFinalApprovalStep($instance, $step)) {
                throw WorkflowAccessDenied::make('Requester tidak boleh menjadi approver terakhir (aturan Segregation of Duties).');
            }
        }

        if (($rules['forbid_same_user_multiple_steps'] ?? false) && ! $override) {
            $alreadyDecided = WorkflowDecision::query()
                ->where('instance_id', $instance->id)
                ->where('actor_id', $actor->id)
                ->where('step_instance_id', '!=', $step->id)
                ->exists();

            if ($alreadyDecided) {
                throw WorkflowAccessDenied::make('Satu orang tidak boleh memutuskan lebih dari satu step pada request ini (aturan Segregation of Duties).');
            }
        }

        $minDistinct = (int) ($rules['min_distinct_approvers'] ?? 0);

        if ($minDistinct > 1 && ! $override) {
            $deciders = WorkflowDecision::query()
                ->where('instance_id', $instance->id)
                ->pluck('actor_id')
                ->map('intval')
                ->unique()
                ->push((int) $actor->id)
                ->unique();

            $remaining = $this->remainingHumanSteps($instance, $step);

            if ($deciders->count() + $remaining < $minDistinct) {
                throw WorkflowAccessDenied::make(sprintf(
                    'Request ini mensyaratkan minimal %d orang berbeda; keputusan Anda akan menyisakan terlalu sedikit pihak yang berwenang.',
                    $minDistinct,
                ));
            }
        }
    }

    protected function isFinalApprovalStep(WorkflowInstance $instance, WorkflowStepInstance $step): bool
    {
        $graph = $instance->version?->graph();
        $node = $graph?->node((string) $step->node_key);

        if ($graph === null || $node === null) {
            return false;
        }

        // Tidak ada lagi node persetujuan/peninjauan setelah node ini.
        foreach ($graph->reachableFrom((string) $step->node_key) as $key) {
            $type = $graph->typeOf($key);

            if ($type !== null && $type->waitsForHuman() && $key !== $step->node_key) {
                return false;
            }
        }

        return true;
    }

    /** Berapa step manusia yang masih tersisa setelah step ini (untuk SoD). */
    protected function remainingHumanSteps(WorkflowInstance $instance, WorkflowStepInstance $step): int
    {
        $graph = $instance->version?->graph();

        if ($graph === null) {
            return 0;
        }

        $count = 0;

        foreach ($graph->reachableFrom((string) $step->node_key) as $key) {
            $type = $graph->typeOf($key);

            if ($type !== null && $type->waitsForHuman() && $key !== $step->node_key) {
                $count++;
            }
        }

        return $count;
    }

    protected function applyToApproval(WorkflowApproval $approval, DecisionType $decision, WorkflowDecision $decisionRow): void
    {
        $approval->forceFill([
            'status' => ApprovalStatus::fromDecision($decision)->value,
            'responded_at' => now(),
            'decision_id' => $decisionRow->id,
        ])->save();
    }

    protected function applyToStep(WorkflowStepInstance $step, DecisionType $decision, User $actor): void
    {
        $attributes = [
            'first_response_at' => $step->first_response_at ?? now(),
            'decided_by' => $actor->id,
        ];

        if ($decision->isApproval()) {
            $attributes['approvals_count'] = (int) $step->approvals_count + 1;
        }

        if ($decision === DecisionType::REJECT) {
            $attributes['rejections_count'] = (int) $step->rejections_count + 1;
        }

        if ($decision->returnsToRequester()) {
            $attributes['returns_count'] = (int) $step->returns_count + 1;
        }

        $step->forceFill($attributes)->save();
    }

    /**
     * Alihkan penugasan seorang approver ke pengguna lain (§61).
     *
     * Penugasan lama tidak dihapus: ia ditandai `REASSIGNED` beserta alasannya,
     * dan penugasan baru menunjuk pemegang barunya.
     */
    public function reassign(WorkflowApproval $approval, User $toUser, User $actor, string $reason): WorkflowApproval
    {
        if (trim($reason) === '') {
            throw InvalidWorkflowOperation::make('Pengalihan approver mewajibkan alasan.');
        }

        return DB::transaction(function () use ($approval, $toUser, $actor, $reason) {
            $locked = WorkflowApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('instance', 'step');

            $instance = $locked->instance;
            $step = $locked->step;
            $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);

            if ($locked->status !== ApprovalStatus::PENDING) {
                throw WorkflowConflict::locked('Penugasan ini sudah tidak menunggu keputusan.');
            }

            $before = ['approver_id' => $locked->approver_id];

            $locked->forceFill([
                'status' => ApprovalStatus::REASSIGNED->value,
                'responded_at' => now(),
            ])->save();

            $created = WorkflowApproval::query()->updateOrCreate(
                ['step_instance_id' => $step->id, 'approver_id' => $toUser->id],
                [
                    'instance_id' => $instance->id,
                    'status' => ApprovalStatus::PENDING->value,
                    'source' => 'MANUAL',
                    'resolver' => 'reassign',
                    'resolver_spec' => ['by' => $actor->id, 'reason' => $reason],
                    'original_approver_id' => $before['approver_id'],
                    'assigned_at' => now(),
                ],
            );

            $this->audit->record(
                AuditAction::APPROVER_REASSIGNED,
                $instance,
                $locked,
                before: $before,
                after: ['approver_id' => $toUser->id, 'new_approval_id' => $created->id],
                reason: $reason,
                actor: $actor,
                extra: ['step_instance_id' => $step->id],
            );

            $this->notifications->dispatch(
                event: NotificationEvent::ASSIGNMENT_CHANGED,
                instance: $instance,
                recipients: [$toUser],
                title: 'Penugasan persetujuan dialihkan kepada Anda',
                body: sprintf('%s — step "%s" kini menjadi tanggung jawab Anda. Alasan: %s', $instance->code, $step->name, $reason),
                payload: ['node_key' => $step->node_key, 'reassigned_by' => $actor->id],
                step: $step,
                keySuffix: 'reassign:'.$created->id,
            );

            return $created;
        });
    }

    /**
     * Lewati sebuah step oleh pengguna berwenang (§62) — selalu beralasan,
     * selalu tercatat, dan hanya bila node mengizinkan.
     */
    public function skipStep(WorkflowInstance $instance, WorkflowStepInstance $step, User $actor, string $reason): WorkflowDecision
    {
        if (trim($reason) === '') {
            throw InvalidWorkflowOperation::make('Melewati step mewajibkan alasan tertulis.');
        }

        $node = $instance->version?->graph()->node((string) $step->node_key) ?? [];

        if (! (bool) ($node['allow_skip'] ?? false)) {
            throw InvalidWorkflowOperation::make('Step ini tidak mengizinkan tindakan lewati.', ['node_key' => $step->node_key]);
        }

        return DB::transaction(function () use ($instance, $step, $actor, $reason) {
            $decision = WorkflowDecision::query()->create([
                'instance_id' => $instance->id,
                'step_instance_id' => $step->id,
                'approval_id' => null,
                'actor_id' => $actor->id,
                'decision' => DecisionType::SKIP->value,
                'comment' => $reason,
                'override_reason' => $reason,
                'is_override' => true,
                'decided_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record(
                AuditAction::STEP_SKIPPED,
                $instance,
                $step,
                after: ['node_key' => $step->node_key, 'reason' => $reason],
                reason: $reason,
                actor: $actor,
                extra: ['step_instance_id' => $step->id],
            );

            // Penugasan yang masih menunggu dibatalkan, tidak dihapus.
            WorkflowApproval::query()
                ->where('step_instance_id', $step->id)
                ->where('status', ApprovalStatus::PENDING->value)
                ->update([
                    'status' => ApprovalStatus::SKIPPED->value,
                    'responded_at' => now(),
                ]);

            $context = app(WorkflowContextService::class)->forInstance($instance);

            $this->execution->skipStep($instance, $step, $decision, $context, $reason);

            return $decision;
        });
    }

    /** Apakah pengguna sedang menerima delegasi (untuk membangun inbox). */
    public function delegationsReceived(User $user): int
    {
        return $this->delegations->delegationsReceivedBy($user->id);
    }

    /** Terjemahkan error basis data unik menjadi konflik yang ramah. */
    public static function mapUniqueViolation(QueryException $e): WorkflowConflict
    {
        return WorkflowConflict::locked('Permintaan serupa sedang diproses. Muat ulang halaman untuk melihat hasil terbaru.');
    }

    /** Status instance setelah keputusan (untuk pesan balasan API). */
    public function describeInstanceState(WorkflowInstance $instance): array
    {
        $instance->refresh();

        return [
            'status' => $instance->status->value,
            'status_label' => $instance->status->label(),
            'current_node_keys' => $instance->currentKeys(),
            'submission_number' => (int) $instance->submission_number,
        ];
    }

    /** Ringkasan keputusan terakhir (dipakai respons inklusif). */
    public function describeStep(WorkflowStepInstance $step): array
    {
        $step->refresh();

        return [
            'node_key' => $step->node_key,
            'name' => $step->name,
            'status' => $step->status->value,
            'result' => $step->result?->value,
            'approvals_count' => (int) $step->approvals_count,
            'rejections_count' => (int) $step->rejections_count,
            'total_approvers' => (int) $step->total_approvers,
        ];
    }

    /** Pastikan status instance konsisten dengan step yang berjalan. */
    public function syncInstanceStatus(WorkflowInstance $instance): InstanceStatus
    {
        $open = $instance->steps()->whereIn('status', [
            StepStatus::WAITING_APPROVAL->value,
            StepStatus::WAITING_REVIEW->value,
            StepStatus::WAITING_FOR_APPROVER->value,
            StepStatus::ACTIVE->value,
        ])->get();

        if ($open->isEmpty() || $instance->status->isTerminal()) {
            return $instance->status;
        }

        $hasReview = $open->contains(fn (WorkflowStepInstance $step) => $step->node_type?->value === 'REVIEW');
        $hasPendingAssignment = $open->contains(fn (WorkflowStepInstance $step) => $step->status === StepStatus::WAITING_FOR_APPROVER);

        $status = $hasPendingAssignment
            ? InstanceStatus::WAITING_FOR_APPROVER
            : ($hasReview ? InstanceStatus::WAITING_REVIEW : InstanceStatus::WAITING_APPROVAL);

        $instance->forceFill([
            'status' => $status->value,
            'current_node_keys' => $open->pluck('node_key')->values()->all(),
        ])->save();

        return $status;
    }
}
