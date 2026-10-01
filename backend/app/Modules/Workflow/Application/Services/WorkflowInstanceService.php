<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Application\Support\WorkflowPolicy;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Enums\Priority;
use App\Modules\Workflow\Domain\Enums\ReturnStrategy;
use App\Modules\Workflow\Domain\Enums\TriggerType;
use App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation;
use App\Modules\Workflow\Domain\Exceptions\WorkflowConflict;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowSubmission;
use App\Modules\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Daur hidup instance workflow (§16, §34, §35, §46).
 *
 * ```
 * create (DRAFT) → submit → [eksekusi graf] → APPROVED | REJECTED | RETURNED
 *                                              RETURNED → revise/resubmit → …
 * ```
 *
 * Setiap perpindahan berarti:
 *  - snapshot konteks dibekukan (dan di-hash),
 *  - submission tercatat sebagai baris tersendiri (§35),
 *  - audit berantai bertambah,
 *  - pihak terkait diberi tahu.
 */
class WorkflowInstanceService
{
    public function __construct(
        private readonly WorkflowExecutionService $execution,
        private readonly WorkflowContextService $contexts,
        private readonly WorkflowNotificationService $notifications,
        private readonly WorkflowAuditService $audit,
        private readonly WorkflowActionService $actions,
        private readonly WorkflowAccessService $access,
    ) {
    }

    /**
     * Buat instance baru (belum dikirim).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): WorkflowInstance
    {
        $definition = $this->resolveDefinition($data);

        $version = isset($data['version_id'])
            ? WorkflowVersion::query()->where('definition_id', $definition->id)->findOrFail($data['version_id'])
            : $definition->startableVersion();

        if ($version === null) {
            throw InvalidWorkflowOperation::make(
                "Definisi {$definition->code} belum memiliki versi aktif. Terbitkan versi terlebih dahulu.",
            );
        }

        $policy = WorkflowPolicy::resolve($definition, $version);
        $subjectType = (string) ($data['subject_type'] ?? $definition->entity_type ?? 'manual');
        $subjectId = (string) ($data['subject_id'] ?? ('manual-'.uniqid()));
        $requester = isset($data['requester_id'])
            ? User::query()->findOrFail($data['requester_id'])
            : $actor;

        $context = $this->contexts->build(
            subjectType: $subjectType,
            subjectId: $subjectId,
            requester: $requester,
            payload: (array) ($data['context'] ?? []),
            requiredKeys: $policy->requiredContext,
            definition: $definition,
        );

        return DB::transaction(function () use ($definition, $version, $data, $actor, $requester, $subjectType, $subjectId, $context, $policy) {
            $instance = WorkflowInstance::query()->create([
                'code' => $this->nextCode(),
                'definition_id' => $definition->id,
                'version_id' => $version->id,
                'module' => $definition->module,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_reference' => $data['subject_reference'] ?? null,
                'title' => (string) ($data['title'] ?? ($definition->name.' '.$subjectId)),
                'requester_id' => $requester->id,
                'requester_department' => $data['department'] ?? app(\App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface::class)->departmentOf($requester->id),
                'priority' => Priority::tryFrom(strtoupper((string) ($data['priority'] ?? 'NORMAL')))?->value ?? 'NORMAL',
                'status' => InstanceStatus::DRAFT->value,
                'amount' => $context->get('amount'),
                'currency' => (string) ($data['currency'] ?? $context->get('currency', 'IDR')),
                'cost_center_id' => $context->get('cost_center_id'),
                'context_summary' => $this->contexts->summarize($context),
                'total_steps' => $this->countHumanSteps($version),
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                AuditAction::INSTANCE_CREATED,
                $instance,
                $instance,
                after: [
                    'definition' => $definition->code,
                    'version' => $version->version,
                    'subject' => $subjectType.':'.$subjectId,
                    'trigger' => $data['trigger_type'] ?? TriggerType::MANUAL->value,
                ],
                actor: $actor,
                extra: ['definition_id' => $definition->id, 'version_id' => $version->id],
            );

            return $instance;
        });
    }

    /**
     * Kirim request: bekukan konteks, catat submission, jalankan alur.
     *
     * @param  array<string, mixed>  $options
     */
    public function submit(WorkflowInstance $instance, User $actor, ?string $revisionReason = null, array $options = []): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $actor, $revisionReason, $options) {
            $instance->refresh()->loadMissing(['definition', 'version']);

            if (! in_array($instance->status, [InstanceStatus::DRAFT, InstanceStatus::RETURNED], true)) {
                throw WorkflowConflict::notOpen($instance);
            }

            $policy = WorkflowPolicy::resolve($instance->definition, $instance->version);
            $isResubmission = $instance->status === InstanceStatus::RETURNED;

            $context = $this->buildContextForSubmit($instance, $actor, $options);

            $submissionNumber = (int) $instance->submission_number + 1;

            $this->contexts->freeze($instance, $context, $submissionNumber);

            WorkflowSubmission::query()->create([
                'instance_id' => $instance->id,
                'submission_number' => $submissionNumber,
                'submitted_by' => $actor->id,
                'revision_reason' => $revisionReason,
                'context_hash' => $context->hash(),
                'status' => 'SUBMITTED',
                'submitted_at' => now(),
                'created_at' => now(),
            ]);

            $state = $this->execution->state($instance);

            if ($isResubmission && $policy->returnStrategy === ReturnStrategy::RESTART_WORKFLOW) {
                // Mulai dari awal: keputusan lama tetap tersimpan di riwayat.
                $state = [
                    'completed' => [],
                    'skipped' => [],
                    'active' => [],
                    'inactive_edges' => [],
                    'hops' => 0,
                ];
            }

            $instance->forceFill([
                'status' => InstanceStatus::SUBMITTED->value,
                'submission_number' => $submissionNumber,
                'submitted_at' => now(),
                'returned_at' => null,
                'completed_at' => null,
                'context_summary' => $this->contexts->summarize($context),
                'execution_state' => $state,
                'amount' => $context->get('amount', $instance->amount),
                'current_node_keys' => [],
            ])->save();

            $this->audit->record(
                $isResubmission ? AuditAction::INSTANCE_RESUBMITTED : AuditAction::INSTANCE_SUBMITTED,
                $instance,
                $instance,
                after: [
                    'submission_number' => $submissionNumber,
                    'context_hash' => $context->hash(),
                    'restart' => $isResubmission && $policy->returnStrategy === ReturnStrategy::RESTART_WORKFLOW,
                ],
                reason: $revisionReason,
                actor: $actor,
            );

            $this->actions->run($isResubmission ? 'resubmitted' : 'submitted', $instance, $context, null, $actor);

            if ($isResubmission) {
                $this->notifications->dispatch(
                    event: NotificationEvent::RESUBMITTED,
                    instance: $instance,
                    recipients: $this->notifications->defaultRecipients($instance),
                    title: 'Request disubmit ulang: '.$instance->title,
                    body: sprintf('%s dikirim ulang untuk ditinjau. Catatan revisi: %s', $instance->code, $revisionReason ?? '-'),
                    keySuffix: 'resubmitted:'.$submissionNumber,
                );
            }

            $this->execution->start($instance->refresh(), $context);

            return $instance->refresh();
        });
    }

    /**
     * Revisi permintaan yang dikembalikan (§34) — perbarui data lalu kirim ulang.
     *
     * @param  array<string, mixed>  $options
     */
    public function revise(WorkflowInstance $instance, User $actor, array $options, string $revisionReason): WorkflowInstance
    {
        $instance->refresh()->loadMissing(['definition', 'version']);

        if ($instance->status !== InstanceStatus::RETURNED) {
            throw InvalidWorkflowOperation::make('Hanya request berstatus RETURNED yang dapat direvisi.', [
                'status' => $instance->status->value,
            ]);
        }

        $this->assertRequester($instance, $actor);

        $before = $instance->context_summary;

        $instance->forceFill([
            'title' => $options['title'] ?? $instance->title,
            'priority' => isset($options['priority'])
                ? (Priority::tryFrom(strtoupper((string) $options['priority']))?->value ?? $instance->priority->value)
                : $instance->priority->value,
            'metadata' => array_merge((array) $instance->metadata, (array) ($options['metadata'] ?? [])),
        ])->save();

        $this->audit->record(
            AuditAction::INSTANCE_REVISED,
            $instance,
            $instance,
            before: ['context_summary' => $before],
            after: ['context_summary' => $instance->context_summary],
            reason: $revisionReason,
            actor: $actor,
        );

        return $this->submit($instance->refresh(), $actor, $revisionReason, $options);
    }

    /**
     * Batalkan request (§63). Keputusan yang sudah ada tidak dihapus.
     */
    public function cancel(WorkflowInstance $instance, User $actor, string $reason): WorkflowInstance
    {
        if (trim($reason) === '') {
            throw InvalidWorkflowOperation::make('Pembatalan mewajibkan alasan.');
        }

        if (! $instance->status->isOpen()) {
            throw WorkflowConflict::notOpen($instance);
        }

        if (! $this->access->canCancel($instance, $actor)) {
            throw \App\Modules\Workflow\Domain\Exceptions\WorkflowAccessDenied::make('Anda tidak berwenang membatalkan request ini.');
        }

        return DB::transaction(function () use ($instance, $actor, $reason) {
            $cancelledSteps = $this->execution->cancelOpenSteps($instance, $reason);

            $instance->forceFill([
                'status' => InstanceStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'current_node_keys' => [],
            ])->save();

            $context = $this->contexts->forInstance($instance);

            $this->actions->run('cancelled', $instance, $context, null, $actor);

            $this->audit->record(
                AuditAction::INSTANCE_CANCELLED,
                $instance,
                $instance,
                after: ['reason' => $reason, 'cancelled_steps' => $cancelledSteps],
                reason: $reason,
                actor: $actor,
            );

            $this->notifications->dispatch(
                event: NotificationEvent::CANCELLED,
                instance: $instance,
                recipients: $this->notifications->defaultRecipients($instance),
                title: 'Request dibatalkan: '.$instance->title,
                body: sprintf('%s dibatalkan oleh %s. Alasan: %s', $instance->code, $actor->name, $reason),
                payload: ['reason' => $reason],
                keySuffix: 'cancelled',
            );

            return $instance->refresh();
        });
    }

    /** Timeline immutable sebuah instance (§47). */
    public function timeline(WorkflowInstance $instance): array
    {
        $events = $instance->events()->with('actor:id,name')->get()->map(fn ($event) => [
            'at' => $event->occurred_at?->toIso8601String(),
            'kind' => 'event',
            'type' => $event->type,
            'actor' => $event->actor?->name,
            'actor_id' => $event->actor_id,
            'detail' => $event->payload,
        ]);

        $decisions = $instance->decisions()->with(['actor:id,name', 'originalApprover:id,name', 'step:id,node_key,name'])->get()->map(fn ($decision) => [
            'at' => $decision->decided_at?->toIso8601String(),
            'kind' => 'decision',
            'type' => 'decision.'.$decision->decision->value,
            'actor' => $decision->actor?->name,
            'actor_id' => $decision->actor_id,
            'detail' => [
                'decision' => $decision->decision->value,
                'comment' => $decision->comment,
                'step' => $decision->step?->name,
                'node_key' => $decision->step?->node_key,
                'original_approver' => $decision->originalApprover?->name,
                'is_override' => $decision->is_override,
                'override_reason' => $decision->override_reason,
            ],
        ]);

        $submissions = $instance->submissions()->with('submitter:id,name')->get()->map(fn ($submission) => [
            'at' => $submission->submitted_at?->toIso8601String(),
            'kind' => 'submission',
            'type' => 'submission.'.$submission->submission_number,
            'actor' => $submission->submitter?->name,
            'actor_id' => $submission->submitted_by,
            'detail' => [
                'submission_number' => $submission->submission_number,
                'revision_reason' => $submission->revision_reason,
                'context_hash' => $submission->context_hash,
            ],
        ]);

        return $events
            ->concat($decisions)
            ->concat($submissions)
            ->sortBy('at')
            ->values()
            ->all();
    }

    /** Progres request: berapa step manusia yang sudah selesai. */
    public function progress(WorkflowInstance $instance): array
    {
        $steps = $instance->steps()->whereIn('node_type', ['APPROVAL', 'REVIEW'])->get();

        return [
            'total' => $steps->count(),
            'completed' => $steps->where('status', 'COMPLETED')->count(),
            'rejected' => $steps->where('status', 'REJECTED')->count(),
            'returned' => $steps->where('status', 'RETURNED')->count(),
            'cancelled' => $steps->where('status', 'CANCELLED')->count(),
            'active' => $steps->whereIn('status', ['ACTIVE', 'WAITING_APPROVAL', 'WAITING_REVIEW', 'WAITING_FOR_APPROVER'])->count(),
            'percent' => $steps->isEmpty() ? 0 : (int) round(($steps->where('status', 'COMPLETED')->count() / max(1, $steps->count())) * 100),
        ];
    }

    /** Tandai request RETURNED yang kedaluwarsa (§17 EXPIRED). */
    public function expireStale(int $returnedDays, int $draftDays): array
    {
        $returned = WorkflowInstance::query()
            ->where('status', InstanceStatus::RETURNED->value)
            ->where('returned_at', '<', now()->subDays($returnedDays))
            ->get();

        foreach ($returned as $instance) {
            $instance->forceFill([
                'status' => InstanceStatus::EXPIRED->value,
                'completed_at' => now(),
                'failure_reason' => "Tidak direvisi dalam {$returnedDays} hari.",
            ])->save();

            $this->audit->record(
                AuditAction::INSTANCE_EXPIRED,
                $instance,
                $instance,
                after: ['reason' => 'returned_expired', 'days' => $returnedDays],
            );
        }

        $drafts = WorkflowInstance::query()
            ->where('status', InstanceStatus::DRAFT->value)
            ->where('created_at', '<', now()->subDays($draftDays))
            ->get();

        foreach ($drafts as $instance) {
            $instance->forceFill([
                'status' => InstanceStatus::EXPIRED->value,
                'completed_at' => now(),
                'failure_reason' => "Draf tidak pernah dikirim dalam {$draftDays} hari.",
            ])->save();

            $this->audit->record(
                AuditAction::INSTANCE_EXPIRED,
                $instance,
                $instance,
                after: ['reason' => 'draft_expired', 'days' => $draftDays],
            );
        }

        return ['returned' => $returned->count(), 'drafts' => $drafts->count()];
    }

    // -----------------------------------------------------------------

    /** @param array<string, mixed> $data */
    protected function resolveDefinition(array $data): WorkflowDefinition
    {
        $definition = isset($data['definition_id'])
            ? WorkflowDefinition::query()->findOrFail($data['definition_id'])
            : WorkflowDefinition::query()->where('code', strtoupper((string) ($data['definition_code'] ?? '')))->first();

        if ($definition === null) {
            throw InvalidWorkflowOperation::make('Definisi workflow tidak ditemukan.', [
                'definition_code' => $data['definition_code'] ?? null,
            ]);
        }

        if ($definition->status !== \App\Modules\Workflow\Domain\Enums\DefinitionStatus::ACTIVE) {
            throw InvalidWorkflowOperation::make(
                "Definisi {$definition->code} berstatus {$definition->status->value} dan tidak menerima request baru.",
                ['status' => $definition->status->value],
            );
        }

        return $definition;
    }

    /** @param array<string, mixed> $options */
    protected function buildContextForSubmit(WorkflowInstance $instance, User $actor, array $options): WorkflowContext
    {
        $definition = $instance->definition;
        $policy = WorkflowPolicy::resolve($definition, $instance->version);

        $payload = (array) ($options['context'] ?? []);

        // Data yang sudah terbukti pada saat pembuatan tetap tersedia bila
        // provider tidak lagi dapat membacanya (mis. entitas terhapus).
        $fallback = (array) ($instance->context_summary ?? []);
        unset($payload['hash']);

        $payload = array_merge($fallback, $payload);

        return $this->contexts->build(
            subjectType: (string) $instance->subject_type,
            subjectId: (string) $instance->subject_id,
            requester: $instance->requester ?? $actor,
            payload: $payload,
            requiredKeys: $policy->requiredContext,
            definition: $definition,
        );
    }

    protected function assertRequester(WorkflowInstance $instance, User $actor): void
    {
        if ((int) $instance->requester_id !== $actor->id && ! $actor->can('workflow.request.manage')) {
            throw \App\Modules\Workflow\Domain\Exceptions\WorkflowAccessDenied::make('Hanya requester (atau pengelola workflow) yang dapat merevisi request ini.');
        }
    }

    protected function countHumanSteps(?WorkflowVersion $version): int
    {
        if ($version === null) {
            return 0;
        }

        $graph = $version->graph();

        return count(array_filter($graph->nodes(), fn (array $node) => in_array($node['type'] ?? '', ['APPROVAL', 'REVIEW'], true)));
    }

    /**
     * Kode request berurutan: WF-<tahun>-<id berurut>.
     *
     * Memakai kunci basis data (`id`) sebagai basis, sehingga tidak ada dua
     * request yang bisa mendapat kode sama meski dibuat bersamaan.
     */
    protected function nextCode(): string
    {
        $prefix = (string) config('workflow.code_prefix', 'WF').'-'.now()->format('Y').'-';

        return $prefix.str_pad((string) (microtime(true) * 10000), 10, '0', STR_PAD_LEFT);
    }

    /** Ringkasan instance untuk daftar/kartu (mengikuti hak akses pemanggil). */
    public function describe(WorkflowInstance $instance): array
    {
        $instance->loadMissing(['definition:id,code,name,module', 'requester:id,name', 'version:id,version']);

        return [
            'id' => $instance->id,
            'code' => $instance->code,
            'title' => $instance->title,
            'module' => $instance->module,
            'definition' => $instance->definition?->code,
            'definition_name' => $instance->definition?->name,
            'version' => $instance->version?->version,
            'subject_type' => $instance->subject_type,
            'subject_id' => $instance->subject_id,
            'subject_reference' => $instance->subject_reference,
            'requester_id' => $instance->requester_id,
            'requester' => $instance->requester?->name,
            'department' => $instance->requester_department,
            'status' => $instance->status->value,
            'status_label' => $instance->status->label(),
            'priority' => $instance->priority->value,
            'amount' => $instance->amount,
            'currency' => $instance->currency,
            'submission_number' => (int) $instance->submission_number,
            'progress' => $this->progress($instance),
            'current_nodes' => $instance->currentKeys(),
            'submitted_at' => $instance->submitted_at?->toIso8601String(),
            'due_at' => $instance->due_at?->toIso8601String(),
            'completed_at' => $instance->completed_at?->toIso8601String(),
            'cancelled_at' => $instance->cancelled_at?->toIso8601String(),
            'failure_reason' => $instance->failure_reason,
            'created_at' => $instance->created_at?->toIso8601String(),
        ];
    }
}
