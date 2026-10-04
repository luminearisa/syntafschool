<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\PurchaseRequestService;
use App\Modules\Procurement\Domain\Enums\ProcurementMethod;
use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestPriority;
use App\Modules\Procurement\Models\AuditLog;
use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Request (§8–§11). */
class PurchaseRequestController extends BaseApiController
{
    public function __construct(private readonly PurchaseRequestService $requests)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->requests->paginate($request->only([
            'search', 'status', 'priority', 'requester_id', 'department_id', 'from', 'to',
        ]), (int) $request->query('per_page', 20));

        return $this->successResponse($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['requester_id'] ??= $request->user()?->id;
        $data['created_by'] = $request->user()?->id;

        return $this->createdResponse($this->requests->create($data), 'PR dibuat');
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        return $this->successResponse($purchaseRequest->load([
            'requester:id,name', 'items.item:id,name,sku', 'items.category:id,name', 'approvals.approver:id,name', 'events:id,number,title',
        ]));
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $this->validated($request, $purchaseRequest);

        return $this->successResponse($this->requests->update($purchaseRequest, $data), 'PR diperbarui');
    }

    /** Validasi PR (§9) — mengembalikan temuan & saran, tidak memblokir. */
    public function validateRequest(PurchaseRequest $purchaseRequest): JsonResponse
    {
        return $this->successResponse($this->requests->validate($purchaseRequest));
    }

    public function submit(PurchaseRequest $purchaseRequest): JsonResponse
    {
        return $this->successResponse($this->requests->submit($purchaseRequest), 'PR diajukan');
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            $this->requests->approve($purchaseRequest, $request->user()?->id, $data['comment'] ?? null),
            'PR disetujui',
        );
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse(
            $this->requests->reject($purchaseRequest, $request->user()?->id, $data['reason']),
            'PR ditolak',
        );
    }

    public function returnRequest(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->requests->return($purchaseRequest, $data['reason']), 'PR dikembalikan');
    }

    /** Konsolidasi beberapa PR → satu peristiwa pengadaan (§11). */
    public function consolidate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_request_ids' => ['required', 'array', 'min:1'],
            'purchase_request_ids.*' => ['integer', 'exists:proc_purchase_requests,id'],
            'title' => ['required', 'string', 'max:180'],
        ]);

        $event = $this->requests->consolidate(
            $data['purchase_request_ids'],
            $data['title'],
            $request->user()?->id,
        );

        return $this->createdResponse($event, 'PR dikonsolidasikan');
    }

    /** Linimasa PR (jejak audit) (§67). */
    public function timeline(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('entity_type', $purchaseRequest::class)
            ->where('entity_id', $purchaseRequest->id)
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        return $this->successResponse([
            'status' => $purchaseRequest->status instanceof \BackedEnum ? $purchaseRequest->status->value : $purchaseRequest->status,
            'timeline' => $logs,
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?PurchaseRequest $pr = null): array
    {
        return $request->validate([
            'requester_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'campus_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:150'],
            'cost_center_id' => ['nullable', 'integer'],
            'budget_reference' => ['nullable', 'string', 'max:60'],
            'project_reference' => ['nullable', 'string', 'max:60'],
            'method' => ['nullable', Rule::in(ProcurementMethod::values())],
            'requested_date' => ['nullable', 'date'],
            'required_date' => [$pr === null ? 'required' : 'sometimes', 'date'],
            'priority' => ['nullable', Rule::in(PurchaseRequestPriority::values())],
            'purpose' => ['nullable', 'string', 'max:255'],
            'justification' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'items' => [$pr === null ? 'required' : 'sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:proc_items,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.specification' => ['nullable', 'string', 'max:255'],
            'items.*.item_type' => ['nullable', Rule::in(ProcurementItemType::values())],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.required_date' => ['nullable', 'date'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:proc_categories,id'],
            'items.*.is_asset' => ['nullable', 'boolean'],
            'items.*.is_inventory' => ['nullable', 'boolean'],
            'items.*.preferred_vendor_id' => ['nullable', 'integer', 'exists:proc_vendors,id'],
            'items.*.notes' => ['nullable', 'string'],
        ]);
    }
}
