<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Workflow\Application\Services\ApprovalService;
use App\Modules\Workflow\Application\Services\WorkflowAccessService;
use App\Modules\Workflow\Application\Services\WorkflowInboxService;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keputusan approver & kotak masuk persetujuan (§5, §32, §50).
 *
 * Endpoint keputusan sengaja tipis: seluruh aturan (otorisasi, delegasi,
 * self-approval, SoD, komentar wajib, idempotensi, kuorum) ada di
 * `ApprovalService`, sehingga jalur API, perintah artisan, maupun integrasi
 * modul memakai aturan yang sama persis.
 */
class WorkflowApprovalController extends BaseApiController
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly WorkflowAccessService $access,
        private readonly WorkflowInboxService $inbox,
    ) {
    }

    /** My Approvals (§50). */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->inbox->myApprovals($request->user(), $request->all());

        return $this->successResponse($paginator->through(fn (WorkflowApproval $approval) => $this->describe($approval)));
    }

    /** My Requests (§51). */
    public function myRequests(Request $request): JsonResponse
    {
        $paginator = $this->inbox->myRequests($request->user(), $request->all());

        return $this->successResponse($paginator->through(fn (WorkflowInstance $instance) => [
            'id' => $instance->id,
            'code' => $instance->code,
            'title' => $instance->title,
            'module' => $instance->module,
            'definition' => $instance->definition?->code,
            'definition_name' => $instance->definition?->name,
            'subject_reference' => $instance->subject_reference,
            'status' => $instance->status->value,
            'status_label' => $instance->status->label(),
            'priority' => $instance->priority->value,
            'amount' => $instance->amount,
            'currency' => $instance->currency,
            'submission_number' => (int) $instance->submission_number,
            'submitted_at' => $instance->submitted_at?->toIso8601String(),
            'due_at' => $instance->due_at?->toIso8601String(),
            'current_nodes' => $instance->currentKeys(),
            // Progres dipakai kartu daftar: berapa langkah manusia yang sudah selesai.
            'progress' => app(WorkflowInstanceService::class)->progress($instance),
            'can_resubmit' => $this->access->canResubmit($instance, $request->user()),
            'can_cancel' => $this->access->canCancel($instance, $request->user()),
        ]));
    }

    /** Badge jumlah pekerjaan (§50). */
    public function counter(Request $request): JsonResponse
    {
        return $this->successResponse($this->inbox->counter($request->user()));
    }

    public function filterOptions(Request $request): JsonResponse
    {
        return $this->successResponse($this->inbox->filterOptions($request->user()));
    }

    /** Daftar penugasan sesuai cakupan pengguna (manager/auditor). */
    public function all(Request $request): JsonResponse
    {
        $query = $this->access->scopeApprovals(
            WorkflowApproval::query()->with(['instance.definition:id,code,name,module', 'instance.requester:id,name', 'approver:id,name', 'step:id,node_key,name,status,sla_status,due_at']),
            $request->user(),
        );

        if ($status = $request->string('status')->toString()) {
            $query->where('status', strtoupper($status));
        }

        if ($module = $request->string('module')->toString()) {
            $query->whereHas('instance', fn ($q) => $q->where('module', $module));
        }

        return $this->successResponse(
            $query->orderByDesc('assigned_at')->paginate(min(100, (int) $request->integer('per_page', 20)))
                ->through(fn (WorkflowApproval $approval) => $this->describe($approval)),
        );
    }

    public function show(Request $request, WorkflowApproval $approval): JsonResponse
    {
        $approval->loadMissing(['instance.definition', 'instance.requester:id,name', 'step', 'approver:id,name', 'delegation']);

        if (! $this->access->canView($approval->instance, $request->user())) {
            abort(403, 'Anda tidak berhak melihat penugasan ini.');
        }

        return $this->successResponse($this->describe($approval) + [
            'step' => [
                'node_key' => $approval->step?->node_key,
                'name' => $approval->step?->name,
                'status' => $approval->step?->status->value,
                'quorum_policy' => $approval->step?->quorum_policy?->value,
                'approved' => (int) ($approval->step->approvals_count ?? 0),
                'remaining' => $approval->step?->remainingApprovals(),
                'total' => (int) ($approval->step->total_approvers ?? 0),
                'due_at' => $approval->step?->due_at?->toIso8601String(),
            ],
            'delegation' => $approval->delegation === null ? null : [
                'id' => $approval->delegation->id,
                'delegator_id' => $approval->delegation->delegator_id,
                'end_at' => $approval->delegation->end_at?->toIso8601String(),
                'reason' => $approval->delegation->reason,
            ],
            'can_decide' => $this->access->canDecide($approval, $request->user()),
        ]);
    }

    public function approve(Request $request, WorkflowApproval $approval): JsonResponse
    {
        return $this->decide($request, $approval, DecisionType::APPROVE);
    }

    public function reject(Request $request, WorkflowApproval $approval): JsonResponse
    {
        return $this->decide($request, $approval, DecisionType::REJECT);
    }

    public function returnForRevision(Request $request, WorkflowApproval $approval): JsonResponse
    {
        return $this->decide($request, $approval, DecisionType::RETURN);
    }

    public function requestChanges(Request $request, WorkflowApproval $approval): JsonResponse
    {
        return $this->decide($request, $approval, DecisionType::REQUEST_CHANGES);
    }

    /** Alihkan penugasan (§61). */
    public function reassign(Request $request, WorkflowApproval $approval): JsonResponse
    {
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $created = $this->approvals->reassign(
            $approval,
            \App\Models\User::query()->findOrFail($data['to_user_id']),
            $request->user(),
            $data['reason'],
        );

        return $this->successResponse([
            'approval_id' => $created->id,
            'approver_id' => $created->approver_id,
            'original_approver_id' => $created->original_approver_id,
        ], 'Penugasan dialihkan.');
    }

    /** Lewati step (§62) — hanya bila node mengizinkan, selalu beralasan. */
    public function skipStep(Request $request, WorkflowInstance $instance, WorkflowStepInstance $step): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $decision = $this->approvals->skipStep($instance, $step, $request->user(), $data['reason']);

        return $this->successResponse([
            'decision_id' => $decision->id,
            'instance' => $this->approvals->describeInstanceState($instance),
        ], 'Step dilewati dengan catatan audit.');
    }

    protected function decide(Request $request, WorkflowApproval $approval, DecisionType $decision): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
            'override_reason' => ['nullable', 'string', 'max:300'],
            'metadata' => ['nullable', 'array'],
        ]);

        $decisionRow = $this->approvals->decide(
            $approval,
            $request->user(),
            $decision,
            $data['comment'] ?? null,
            [
                'override_reason' => $data['override_reason'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'idempotency_key' => $request->header('Idempotency-Key') ?? $request->input('idempotency_key'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        $approval->refresh();

        return $this->successResponse([
            'decision_id' => $decisionRow->id,
            'decision' => $decisionRow->decision->value,
            'decided_at' => $decisionRow->decided_at?->toIso8601String(),
            'original_approver_id' => $decisionRow->original_approver_id,
            'step' => $this->approvals->describeStep($approval->step),
            'instance' => $this->approvals->describeInstanceState($approval->instance),
        ], 'Keputusan tercatat.');
    }

    /** @return array<string, mixed> */
    protected function describe(WorkflowApproval $approval): array
    {
        $instance = $approval->instance;

        return [
            'id' => $approval->id,
            'instance_id' => $approval->instance_id,
            'instance_code' => $instance?->code,
            'title' => $instance?->title,
            'module' => $instance?->module,
            'subject_type' => $instance?->subject_type,
            'subject_id' => $instance?->subject_id,
            'subject_reference' => $instance?->subject_reference,
            'definition' => $instance?->definition?->code,
            'definition_name' => $instance?->definition?->name,
            'requester' => $instance?->requester?->name,
            'requester_id' => $instance?->requester_id,
            'department' => $instance?->requester_department,
            'amount' => $instance?->amount,
            'currency' => $instance?->currency,
            'priority' => $instance?->priority?->value,
            'instance_status' => $instance?->status->value,
            'step_instance_id' => $approval->step_instance_id,
            'step' => $approval->step?->name,
            'node_key' => $approval->step?->node_key,
            'step_status' => $approval->step?->status->value,
            'status' => $approval->status->value,
            'source' => $approval->source->value,
            'resolver' => $approval->resolver,
            'is_delegate' => (bool) $approval->is_delegate,
            'original_approver_id' => $approval->original_approver_id,
            'original_approver' => $approval->originalApprover?->name,
            'delegation_id' => $approval->delegation_id,
            'assigned_at' => $approval->assigned_at?->toIso8601String(),
            'due_at' => $approval->due_at?->toIso8601String(),
            'sla_status' => $approval->step?->sla_status?->value,
            'remaining_minutes' => $approval->due_at === null ? null : (int) now()->diffInMinutes($approval->due_at, false),
            'responded_at' => $approval->responded_at?->toIso8601String(),
        ];
    }
}
