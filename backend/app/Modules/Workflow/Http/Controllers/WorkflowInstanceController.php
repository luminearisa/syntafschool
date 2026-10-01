<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Workflow\Application\Services\WorkflowAccessService;
use App\Modules\Workflow\Application\Services\WorkflowAuditService;
use App\Modules\Workflow\Application\Services\WorkflowContextService;
use App\Modules\Workflow\Application\Services\WorkflowExecutionService;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Application\Services\WorkflowSimulationService;
use App\Modules\Workflow\Application\Services\WorkflowStepService;
use App\Modules\Workflow\Models\WorkflowComment;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Request workflow dari sisi requester (§4, §46, §47, §63).
 *
 * Cakupan baris ditegakkan `WorkflowAccessService`: request milik orang lain
 * hanya terlihat oleh approver, pengamat, atau pemegang izin
 * `workflow.instance.view` — termasuk bila ID-nya ditebak.
 */
class WorkflowInstanceController extends BaseApiController
{
    public function __construct(
        private readonly WorkflowInstanceService $instances,
        private readonly WorkflowAccessService $access,
        private readonly WorkflowContextService $contexts,
        private readonly WorkflowExecutionService $execution,
        private readonly WorkflowStepService $steps,
        private readonly WorkflowSimulationService $simulation,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /** Daftar request sesuai cakupan pengguna. */
    public function index(Request $request): JsonResponse
    {
        $service = app(\App\Modules\Workflow\Application\Services\WorkflowInboxService::class);

        $paginator = $service->visibleInstances($request->user(), $request->all());

        return $this->successResponse($paginator->through(fn (WorkflowInstance $instance) => $this->instances->describe($instance)));
    }

    /** Buat request baru (belum dikirim). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'definition_code' => ['required_without:definition_id', 'nullable', 'string', 'max:80'],
            'definition_id' => ['required_without:definition_code', 'nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'subject_reference' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'priority' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'max:3'],
            'requester_id' => ['nullable', 'integer', 'exists:users,id'],
            'context' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $instance = $this->instances->create($data, $request->user());

        if ($request->boolean('submit', true)) {
            $instance = $this->instances->submit($instance, $instance->requester, null, $data);
        }

        return $this->createdResponse($this->detail($instance), 'Request workflow dibuat.');
    }

    public function show(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        return $this->successResponse($this->detail($instance));
    }

    public function submit(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $data = $request->validate([
            'revision_reason' => ['nullable', 'string', 'max:300'],
            'context' => ['nullable', 'array'],
        ]);

        $instance = $this->instances->submit($instance, $request->user(), $data['revision_reason'] ?? null, $data);

        return $this->successResponse($this->detail($instance), 'Request dikirim untuk persetujuan.');
    }

    public function cancel(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $instance = $this->instances->cancel($instance, $request->user(), $data['reason']);

        return $this->successResponse($this->detail($instance), 'Request dibatalkan.');
    }

    /** Revisi + submit ulang setelah dikembalikan (§34, §35). */
    public function revise(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $data = $request->validate([
            'revision_reason' => ['required', 'string', 'max:300'],
            'title' => ['nullable', 'string', 'max:200'],
            'priority' => ['nullable', 'string', 'max:20'],
            'context' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $instance = $this->instances->revise(
            $instance,
            $request->user(),
            $data,
            $data['revision_reason'],
        );

        return $this->successResponse($this->detail($instance), 'Revisi dikirim ulang.');
    }

    /** Timeline immutable (§47). */
    public function timeline(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        return $this->successResponse([
            'instance' => $this->instances->describe($instance),
            'timeline' => $this->instances->timeline($instance),
            'steps' => $this->stepsPayload($instance),
            'graph' => $this->graphPayload($instance),
        ]);
    }

    /** Snapshot konteks beku (§65). */
    public function context(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        $snapshots = $instance->contextSnapshots()->get()->map(fn ($snapshot) => [
            'submission_number' => $snapshot->submission_number,
            'provider' => $snapshot->provider,
            'hash' => $snapshot->hash,
            'resolved_at' => $snapshot->resolved_at?->toIso8601String(),
            'snapshot' => $snapshot->snapshot,
        ]);

        return $this->successResponse([
            'instance' => $instance->code,
            'snapshots' => $snapshots,
            'current' => $this->contexts->forInstance($instance)->toArray(),
            'age_days' => $this->contexts->ageInDays($instance),
        ]);
    }

    /** Tambah komentar request. */
    public function comment(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'step_instance_id' => ['nullable', 'integer'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        $comment = WorkflowComment::query()->create([
            'instance_id' => $instance->id,
            'step_instance_id' => $data['step_instance_id'] ?? null,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'is_internal' => (bool) ($data['is_internal'] ?? false),
            'created_at' => now(),
        ]);

        $this->audit->record(
            \App\Modules\Workflow\Domain\Enums\AuditAction::COMMENT_ADDED,
            $instance,
            $comment,
            after: ['body' => $data['body']],
            actor: $request->user(),
            extra: ['step_instance_id' => $data['step_instance_id'] ?? null],
        );

        return $this->createdResponse([
            'id' => $comment->id,
            'body' => $comment->body,
            'author_id' => $comment->author_id,
            'author' => $request->user()->name,
            'is_internal' => (bool) $comment->is_internal,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 'Komentar ditambahkan.');
    }

    /** Rujuk dokumen pendukung (§66) — tanpa menyalin berkas. */
    public function attach(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:150'],
            'file_path' => ['nullable', 'string', 'max:300'],
            'original_name' => ['nullable', 'string', 'max:200'],
            'mime' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:30'],
            'external_ref' => ['nullable', 'string', 'max:200'],
        ]);

        $attachment = $instance->attachments()->create($data + [
            'uploaded_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        $this->audit->record(
            \App\Modules\Workflow\Domain\Enums\AuditAction::ATTACHMENT_LINKED,
            $instance,
            $attachment,
            after: $data,
            actor: $request->user(),
        );

        return $this->createdResponse($instance->attachments()->get(), 'Dokumen pendukung ditautkan.');
    }

    /** Audit satu request (§48 + verifikasi rantai). */
    public function audit(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        return $this->successResponse([
            'instance' => $instance->code,
            'logs' => collect($this->audit->chainFor($instance))->map(fn ($log) => [
                'id' => $log->id,
                'at' => $log->occurred_at?->toIso8601String(),
                'action' => $log->action->value,
                'actor' => $log->actor?->name,
                'actor_id' => $log->actor_id,
                'reason' => $log->reason,
                'before' => $log->before,
                'after' => $log->after,
                'ip_address' => $log->ip_address,
                'hash' => $log->hash,
                'previous_hash' => $log->previous_hash,
            ])->all(),
            'chain' => $this->audit->verifyChain($instance->id),
        ]);
    }

    /** Dry run request berjalan (§57). */
    public function dryRun(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeView($request, $instance);

        return $this->successResponse($this->simulation->dryRun($instance), 'Hasil dry run request.');
    }

    /** Penetapan approver manual (§58, §61). */
    public function assignApprovers(Request $request, WorkflowInstance $instance, WorkflowStepInstance $step): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $created = $this->steps->assignManually($instance, $step, $data['user_ids'], $request->user(), $data['reason'] ?? null);

        return $this->successResponse([
            'assignments' => collect($created)->map(fn ($approval) => [
                'id' => $approval->id,
                'approver_id' => $approval->approver_id,
                'source' => $approval->source->value,
            ])->all(),
            'step' => [
                'id' => $step->id,
                'node_key' => $step->node_key,
                'status' => $step->refresh()->status->value,
            ],
        ], 'Approver ditetapkan.');
    }

    /** @return array<string, mixed> */
    protected function detail(WorkflowInstance $instance): array
    {
        $instance->loadMissing(['comments.author:id,name', 'attachments']);

        return $this->instances->describe($instance) + [
            'context_summary' => $instance->context_summary,
            'metadata' => $instance->metadata,
            'cancel_reason' => $instance->cancel_reason,
            'steps' => $this->stepsPayload($instance),
            'graph' => $this->graphPayload($instance),
            'approvals' => $instance->approvals()->with(['approver:id,name', 'originalApprover:id,name'])->get()->map(fn ($approval) => [
                'id' => $approval->id,
                'step_instance_id' => $approval->step_instance_id,
                'approver_id' => $approval->approver_id,
                'approver' => $approval->approver?->name,
                'status' => $approval->status->value,
                'source' => $approval->source->value,
                'is_delegate' => (bool) $approval->is_delegate,
                'original_approver' => $approval->originalApprover?->name,
                'delegation_id' => $approval->delegation_id,
                'due_at' => $approval->due_at?->toIso8601String(),
                'responded_at' => $approval->responded_at?->toIso8601String(),
            ])->all(),
            'decisions' => $instance->decisions()->with(['actor:id,name', 'originalApprover:id,name'])->get()->map(fn ($decision) => [
                'id' => $decision->id,
                'step_instance_id' => $decision->step_instance_id,
                'decision' => $decision->decision->value,
                'actor_id' => $decision->actor_id,
                'actor' => $decision->actor?->name,
                'original_approver' => $decision->originalApprover?->name,
                'comment' => $decision->comment,
                'is_override' => (bool) $decision->is_override,
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ])->all(),
            'comments' => $instance->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->author?->name,
                'author_id' => $comment->author_id,
                'is_internal' => (bool) $comment->is_internal,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->all(),
            'attachments' => $instance->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'label' => $attachment->label,
                'original_name' => $attachment->original_name,
                'source' => $attachment->source,
                'external_ref' => $attachment->external_ref,
                'file_path' => $attachment->file_path,
                'created_at' => $attachment->created_at?->toIso8601String(),
            ])->all(),
            'timeline' => $this->instances->timeline($instance),
            'approval_progress' => $this->instances->progress($instance),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function stepsPayload(WorkflowInstance $instance): array
    {
        return $instance->steps()->with(['approvals.approver:id,name', 'approvals.originalApprover:id,name'])->get()->map(fn (WorkflowStepInstance $step) => [
            'id' => $step->id,
            'node_key' => $step->node_key,
            'node_type' => $step->node_type->value,
            'name' => $step->name,
            'sequence' => (int) $step->sequence,
            'round' => (int) $step->round,
            'status' => $step->status->value,
            'status_label' => $step->status->label(),
            'result' => $step->result?->value,
            'approval_mode' => $step->approval_mode?->value,
            'quorum' => [
                'policy' => $step->quorum_policy?->value,
                'value' => $step->quorum_value,
                'total' => (int) $step->total_approvers,
                'approved' => (int) $step->approvals_count,
                'remaining' => $step->remainingApprovals(),
            ],
            'activated_at' => $step->activated_at?->toIso8601String(),
            'due_at' => $step->due_at?->toIso8601String(),
            'completed_at' => $step->completed_at?->toIso8601String(),
            'sla_status' => $step->sla_status?->value,
            'sla_minutes' => $step->sla_minutes,
            'approvers' => $step->approvals->map(fn ($approval) => [
                'approval_id' => $approval->id,
                'user_id' => $approval->approver_id,
                'name' => $approval->approver?->name,
                'status' => $approval->status->value,
                'source' => $approval->source->value,
                'is_delegate' => (bool) $approval->is_delegate,
                'original_approver' => $approval->originalApprover?->name,
                'due_at' => $approval->due_at?->toIso8601String(),
                'responded_at' => $approval->responded_at?->toIso8601String(),
            ])->all(),
            'warnings' => (array) ($step->metadata['warnings'] ?? []),
        ])->all();
    }

    /** Graf visualizer dengan status tiap node (§49). */
    protected function graphPayload(WorkflowInstance $instance): array
    {
        $graph = $instance->version?->graph();
        $state = $this->execution->state($instance);

        $steps = $instance->steps()->orderBy('round')->get()->groupBy('node_key');

        $nodes = collect($graph?->nodes() ?? [])->map(function (array $node) use ($steps, $state) {
            $key = (string) $node['key'];
            $nodeSteps = $steps->get($key, collect());
            $latest = $nodeSteps->sortByDesc('round')->first();

            $status = match (true) {
                in_array($key, $state['completed'], true) => 'APPROVED',
                $latest?->status->value === 'REJECTED' => 'REJECTED',
                $latest?->status->value === 'RETURNED' => 'RETURNED',
                in_array($key, $state['skipped'], true) => 'SKIPPED',
                in_array($key, $state['active'], true) => 'WAITING',
                default => 'PENDING',
            };

            return [
                'key' => $key,
                'name' => $node['name'] ?? $key,
                'type' => $node['type'],
                'sequence' => (int) ($node['sequence'] ?? 0),
                'status' => $status,
                'approval_mode' => $node['approval_mode'] ?? null,
                'quorum' => $node['quorum_policy'] ?? null,
                'sla_minutes' => $node['sla_minutes'] ?? null,
                'sla_status' => $latest?->sla_status?->value,
                'due_at' => $latest?->due_at?->toIso8601String(),
                'round' => $latest?->round,
            ];
        })->all();

        return [
            'nodes' => $nodes,
            'edges' => $graph?->edges() ?? [],
            'state' => $state,
        ];
    }

    protected function authorizeView(Request $request, WorkflowInstance $instance): void
    {
        if (! $this->access->canView($instance, $request->user())) {
            abort(403, 'Anda tidak berhak melihat request workflow ini.');
        }
    }
}
