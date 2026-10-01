<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkEscalationService;
use App\Modules\Bk\Application\Services\BkExportService;
use App\Modules\Bk\Application\Services\BkRetentionService;
use App\Modules\Bk\Application\Services\BkSecureSearchService;
use App\Modules\Bk\Application\Services\BkSlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian aman, ekspor, retensi, SLA, dan eskalasi.
 *
 * Semua endpoint di sini menyentuh data sensitif, jadi semuanya memeriksa izin
 * lebih dulu, mencatat tindakan yang berisiko, dan tidak pernah mengirim isi
 * catatan konseling — bahkan kepada admin.
 */
class BkSecurityController extends Controller
{
    use ApiResponse;


    public function __construct(
        private readonly BkSecureSearchService $search,
        private readonly BkExportService $exports,
        private readonly BkRetentionService $retention,
        private readonly BkSlaService $sla,
        private readonly BkEscalationService $escalations,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $request->user()->can('bk.search') || abort(403);

        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'scope' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->search->search($request->user(), $data['q'], $data['scope'] ?? null));
    }

    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dataset' => ['required', 'string', 'in:cases,referrals,follow_ups,indicators'],
            'format' => ['required', 'string', 'in:CSV,XLSX,PDF,JSON'],
            'confirm' => ['required', 'accepted'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $result = $this->exports->create(
            actor: $request->user(),
            dataset: $data['dataset'],
            format: \App\Modules\Bk\Domain\Enums\ExportFormat::from($data['format']),
            filters: ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            confirmed: (bool) $data['confirm'],
        );

        return $this->successResponse($result, $result['message']);
    }

    public function exportFiles(Request $request): JsonResponse
    {
        $request->user()->can('bk.report.export') || abort(403);

        return $this->successResponse($this->exports->files($request->user()));
    }

    public function slaEvents(Request $request): JsonResponse
    {
        $request->user()->can('bk.sla.view') || abort(403);

        return $this->successResponse($this->sla->paginate($request->user(), $request->only([
            'status', 'subject_type', 'only_running', 'assigned_user_id',
        ]), (int) $request->input('per_page', 25)));
    }

    public function monitorSla(Request $request): JsonResponse
    {
        $request->user()->can('bk.sla.manage') || abort(403);

        return $this->successResponse($this->sla->monitor(), 'Pemantauan SLA dijalankan.');
    }

    public function escalations(Request $request): JsonResponse
    {
        $request->user()->can('bk.sla.view') || abort(403);

        return $this->successResponse($this->escalations->paginate($request->user(), $request->only([
            'status', 'to_level', 'subject_type',
        ]), (int) $request->input('per_page', 25)));
    }

    public function acknowledgeEscalation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.sla.manage') || abort(403);

        $escalation = \App\Modules\Bk\Models\BkEscalation::query()->findOrFail($id);
        $data = $request->validate(['note' => ['nullable', 'string']]);

        return $this->successResponse($this->escalations->acknowledge($request->user(), $escalation, $data['note'] ?? null), 'Eskalasi diakui.');
    }

    public function resolveEscalation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.sla.manage') || abort(403);

        $escalation = \App\Modules\Bk\Models\BkEscalation::query()->findOrFail($id);
        $data = $request->validate(['resolution' => ['required', 'string']]);

        return $this->successResponse($this->escalations->resolve($request->user(), $escalation, $data['resolution']), 'Eskalasi diselesaikan.');
    }

    public function retention(Request $request): JsonResponse
    {
        $request->user()->can('bk.retention.view') || abort(403);

        return $this->successResponse([
            'overview' => $this->retention->overview(),
            'policies' => $this->retention->paginatePolicies($request->user()),
            'pending' => \App\Modules\Bk\Models\BkRetentionAction::query()
                ->where('action', \App\Modules\Bk\Domain\Enums\RetentionActionType::DISPOSAL_REQUESTED->value)
                ->where('status', 'PENDING')
                ->with('performer:id,name')
                ->orderBy('performed_at')
                ->paginate(25, ['*'], 'pending_page'),
        ]);
    }

    public function setRetentionPolicy(Request $request): JsonResponse
    {
        $request->user()->can('bk.retention.manage') || abort(403);

        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'retention_days' => ['required', 'integer', 'min:1'],
            'archive_after_days' => ['nullable', 'integer', 'min:1'],
            'requires_approval_for_disposal' => ['nullable', 'boolean'],
            'legal_basis' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string'],
        ]);

        $policy = $this->retention->setPolicy(
            $request->user(),
            \App\Modules\Bk\Domain\Enums\RetentionEntityType::from($data['entity_type']),
            $data,
        );

        return $this->createdResponse($policy, 'Kebijakan retensi ditetapkan sebagai versi baru.');
    }

    public function requestDisposal(Request $request): JsonResponse
    {
        $request->user()->can('bk.retention.manage') || abort(403);

        $data = $request->validate(['entity_type' => ['nullable', 'string']]);

        $summary = $this->retention->requestDisposal(
            $request->user(),
            isset($data['entity_type']) ? \App\Modules\Bk\Domain\Enums\RetentionEntityType::from($data['entity_type']) : null,
        );

        return $this->successResponse($summary, 'Permintaan pemusnahan diajukan dan menunggu persetujuan.');
    }

    public function approveDisposal(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.retention.approve') || abort(403);

        $record = \App\Modules\Bk\Models\BkRetentionAction::query()->findOrFail($id);

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
        ]);

        $result = $this->retention->approveDisposal($request->user(), $record, (bool) $data['approved'], $data['note'] ?? null);

        return $this->successResponse($result, $data['approved'] ? 'Pemusnahan disetujui.' : 'Pemusnahan ditolak.');
    }

    public function executeDisposal(Request $request): JsonResponse
    {
        $request->user()->can('bk.retention.approve') || abort(403);

        return $this->successResponse($this->retention->executeApprovedDisposal($request->user()), 'Pemusnahan yang sudah disetujui dijalankan.');
    }

    public function automation(Request $request): JsonResponse
    {
        $request->user()->can('bk.settings.view') || abort(403);

        return $this->successResponse([
            'automation' => app(\App\Modules\Bk\Application\Services\BkAutomationService::class)->overview(),
            'runs' => \App\Modules\Bk\Models\BkAutomationRun::query()->orderByDesc('ran_at')->paginate(25),
        ]);
    }
}
