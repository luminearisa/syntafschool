<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\ActionPlanService;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupActionPlan;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RTL: penyusunan dari temuan, pelaporan kemajuan, verifikasi, papan pemantauan. */
class SupActionPlanController extends SupController
{
    public function __construct(private readonly ActionPlanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $board = $this->service->board($request->only([
            'supervisee_employee_id', 'status', 'overdue_only', 'waiting_verification', 'due_in_days', 'limit',
        ]), $this->actor($request));

        return $this->successResponse($board, 'Papan RTL');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supervisee_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'visit_id' => ['nullable', 'integer', 'exists:sup_visits,id'],
            'title' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'items' => ['nullable', 'array'],
            'items.*.statement' => ['required_with:items', 'string', 'max:500'],
            'items.*.support_needed' => ['nullable', 'string'],
            'items.*.due_date' => ['nullable', 'date'],
            'items.*.indicator_id' => ['nullable', 'integer'],
        ]);

        $plan = $this->service->create($data, $this->actor($request));

        return $this->successResponse($this->service->detail($plan, $this->actor($request)), 'RTL dibuat', 201);
    }

    public function storeFromVisit(Request $request, SupVisit $visit): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $plan = $this->service->createFromVisit($visit, $data, $this->actor($request));

        return $this->successResponse($this->service->detail($plan, $this->actor($request)), 'RTL disusun dari temuan kegiatan', 201);
    }

    public function show(Request $request, SupActionPlan $actionPlan): JsonResponse
    {
        $this->service->ensureVisible($actionPlan, $this->actor($request));

        return $this->successResponse(
            $this->service->detail($actionPlan, $this->actor($request)),
            'Rincian RTL',
        );
    }

    public function update(Request $request, SupActionPlan $actionPlan): JsonResponse
    {
        $this->service->ensureVisible($actionPlan, $this->actor($request));

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        $plan = $this->service->update($actionPlan, $data, $this->actor($request));

        return $this->successResponse($this->service->detail($plan, $this->actor($request)), 'RTL diperbarui');
    }

    public function storeItem(Request $request, SupActionPlan $actionPlan): JsonResponse
    {
        $this->service->ensureVisible($actionPlan, $this->actor($request));

        $data = $request->validate([
            'statement' => ['required', 'string', 'max:500'],
            'support_needed' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'indicator_id' => ['nullable', 'integer'],
            'owner_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $item = $this->service->addItem($actionPlan, $data, $this->actor($request));

        return $this->successResponse($this->service->detail($actionPlan->refresh(), $this->actor($request)), 'Butir RTL ditambahkan', 201);
    }

    public function updateItem(Request $request, SupActionItem $actionItem): JsonResponse
    {
        $this->service->ensureVisible($actionItem->plan()->firstOrFail(), $this->actor($request));

        $data = $request->validate([
            'statement' => ['nullable', 'string', 'max:500'],
            'support_needed' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'owner_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'evidence_path' => ['nullable', 'string', 'max:255'],
            'result_note' => ['nullable', 'string'],
            'status' => ['nullable', 'in:PENDING,IN_PROGRESS,DONE,VERIFIED,DROPPED'],
        ]);

        $item = $this->service->updateItem($actionItem, $data, $this->actor($request));

        return $this->successResponse(
            $this->service->detail($item->plan()->firstOrFail()->refresh(), $this->actor($request)),
            'Butir RTL diperbarui',
        );
    }

    public function reportProgress(Request $request, SupActionItem $actionItem): JsonResponse
    {
        $this->service->ensureVisible($actionItem->plan()->firstOrFail(), $this->actor($request));

        $data = $request->validate([
            'progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ]);

        $item = $this->service->reportProgress($actionItem, $data, $this->actor($request));

        return $this->successResponse(
            $this->service->detail($item->plan()->firstOrFail()->refresh(), $this->actor($request)),
            'Laporan kemajuan tersimpan',
        );
    }

    public function verify(Request $request, SupActionPlan $actionPlan): JsonResponse
    {
        $this->service->ensureVisible($actionPlan, $this->actor($request));

        $data = $request->validate(['note' => ['nullable', 'string']]);

        $plan = $this->service->verify($actionPlan, $this->actor($request), $data['note'] ?? null);

        return $this->successResponse($this->service->detail($plan, $this->actor($request)), 'RTL diverifikasi');
    }

    public function drop(Request $request, SupActionPlan $actionPlan): JsonResponse
    {
        $this->service->ensureVisible($actionPlan, $this->actor($request));

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $plan = $this->service->drop($actionPlan, $this->actor($request), $data['reason']);

        return $this->successResponse($this->service->detail($plan, $this->actor($request)), 'RTL dihentikan');
    }
}
