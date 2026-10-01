<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Services\MaintenanceService;
use App\Modules\Asset\Application\Services\PreventiveMaintenanceService;
use App\Modules\Asset\Http\Requests\StoreMaintenanceRequestRequest;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Asset\Models\MaintenanceSchedule;
use App\Modules\Asset\Models\MaintenanceWorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaintenanceController extends AssetControllerBase
{
    public function __construct(
        private readonly MaintenanceService $maintenance,
        private readonly PreventiveMaintenanceService $preventive,
        private readonly AssetAccessService $access,
    ) {}

    public function requests(Request $request): JsonResponse
    {
        $query = MaintenanceRequest::query()->with(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'requester:id,name', 'technician:id,name']);

        foreach (['status', 'priority', 'asset_id', 'facility_id', 'assigned_to', 'source'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->whereNull('completed_at');
        }

        if ($request->filled('q')) {
            $keyword = (string) $request->input('q');
            $query->where(fn ($inner) => $inner->where('title', 'like', "%$keyword%")->orWhere('request_number', 'like', "%$keyword%"));
        }

        return $this->paginated($query->orderByDesc('reported_at')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar permintaan pemeliharaan.');
    }

    public function storeRequest(StoreMaintenanceRequestRequest $request): JsonResponse
    {
        return $this->created(
            $this->maintenance->createRequest($this->actor($request), $request->validated()),
            'Permintaan pemeliharaan dibuat.',
        );
    }

    public function showRequest(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        Gate::authorize('view', $maintenanceRequest);

        return $this->ok($maintenanceRequest->load(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'workOrders', 'requester:id,name']), 'Rincian permintaan.');
    }

    public function triage(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        Gate::authorize('update', $maintenanceRequest);

        $data = $request->validate([
            'priority' => ['nullable', 'string', 'in:LOW,NORMAL,HIGH,URGENT,CRITICAL'],
            'category' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok($this->maintenance->triage($this->actor($request), $maintenanceRequest, $data), 'Permintaan ditriage.');
    }

    public function assign(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        Gate::authorize('update', $maintenanceRequest);

        $data = $request->validate([
            'technician_id' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'string', 'in:LOW,NORMAL,HIGH,URGENT,CRITICAL'],
            'type' => ['nullable', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'contract_id' => ['nullable', 'integer', 'exists:maintenance_contracts,id'],
            'vendor_id' => ['nullable', 'integer'],
        ]);

        return $this->created(
            $this->maintenance->assign($this->actor($request), $maintenanceRequest, (int) $data['technician_id'], $data),
            'Work order dibuat dan teknisi ditugaskan.',
        );
    }

    public function workOrders(Request $request): JsonResponse
    {
        $query = MaintenanceWorkOrder::query()->with(['asset:id,asset_code,name', 'technician:id,name', 'request:id,request_number,priority']);

        foreach (['status', 'type', 'priority', 'assigned_to', 'asset_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_at')->where('due_at', '<', now())->whereNull('completed_at');
        }

        if ($request->boolean('mine')) {
            $query->where('assigned_to', $this->actor($request)->id);
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar work order.');
    }

    public function showWorkOrder(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('view', $workOrder);

        return $this->ok($workOrder->load(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'tasks', 'logs', 'technician:id,name', 'request:id,request_number']), 'Rincian work order.');
    }

    public function start(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate(['diagnosis' => ['nullable', 'string']]);

        return $this->ok($this->maintenance->start($this->actor($request), $workOrder, $data['diagnosis'] ?? null), 'Pengerjaan dimulai.');
    }

    public function requestParts(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.code' => ['required', 'string', 'max:80'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
        ]);

        return $this->ok($this->maintenance->requestParts($this->actor($request), $workOrder, $data['items']), 'Permintaan suku cadang diproses.');
    }

    public function externalService(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate([
            'cost' => ['required', 'numeric', 'min:0'],
            'vendor_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok($this->maintenance->recordExternalService($this->actor($request), $workOrder, $data), 'Jasa luar dicatat.');
    }

    public function addTask(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->created($this->maintenance->addTask($this->actor($request), $workOrder, $data), 'Langkah pekerjaan ditambahkan.');
    }

    public function completeTask(Request $request, MaintenanceWorkOrder $workOrder, int $task): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate(['notes' => ['nullable', 'string']]);

        return $this->ok($this->maintenance->completeTask($this->actor($request), $workOrder, $task, $data), 'Langkah pekerjaan selesai.');
    }

    public function complete(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate([
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'parts_cost' => ['nullable', 'numeric', 'min:0'],
            'external_cost' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],
            'diagnosis' => ['nullable', 'string'],
            'repair_plan' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'next_maintenance_at' => ['nullable', 'date'],
        ]);

        return $this->ok($this->maintenance->complete($this->actor($request), $workOrder, $data), 'Work order selesai.');
    }

    public function verify(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate(['notes' => ['nullable', 'string']]);

        return $this->ok($this->maintenance->verify($this->actor($request), $workOrder, $data), 'Work order diverifikasi dan permintaan ditutup.');
    }

    public function cancel(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->ok($this->maintenance->cancel($this->actor($request), $workOrder, $data['reason']), 'Work order dibatalkan.');
    }

    public function schedules(Request $request): JsonResponse
    {
        $query = MaintenanceSchedule::query()->with(['asset:id,asset_code,name', 'facility:id,facility_code,name']);

        if ($request->boolean('due')) {
            $query->where('is_active', true)->where('next_due_at', '<=', now()->addDays(30));
        }

        return $this->ok($query->orderBy('next_due_at')->get(), 'Jadwal pemeliharaan preventif.');
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'title' => ['required', 'string', 'max:160'],
            'frequency' => ['nullable', 'string', 'max:24'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'checklist' => ['nullable', 'array'],
            'next_due_at' => ['nullable', 'date'],
            'assigned_role' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->created($this->preventive->createSchedule($this->actor($request), $data), 'Jadwal preventif dibuat.');
    }

    public function runSchedule(Request $request): JsonResponse
    {
        $result = $this->preventive->generateDue($this->actor($request));

        return $this->ok($result, 'Jadwal jatuh tempo dijalankan (idempoten: satu work order per jatuh tempo).');
    }
}
