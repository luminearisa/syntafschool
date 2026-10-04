<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\RfqService;
use App\Modules\Procurement\Models\ProcurementEvent;
use App\Modules\Procurement\Models\Rfq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RFQ / permintaan penawaran (§12). */
class RfqController extends BaseApiController
{
    public function __construct(private readonly RfqService $rfqs)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->rfqs->paginate(
            $request->only(['status', 'event_id', 'search']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'event_id' => ['nullable', 'integer', 'exists:proc_events,id'],
            'deadline' => ['nullable', 'date'],
            'validity_requirement' => ['nullable', 'string', 'max:120'],
            'delivery_requirement' => ['nullable', 'string', 'max:180'],
            'delivery_location' => ['nullable', 'string', 'max:180'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'vendors' => ['nullable', 'array'],
            'vendors.*' => ['integer', 'exists:proc_vendors,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_request_item_id' => ['nullable', 'integer', 'exists:proc_purchase_request_items,id'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:proc_items,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.specification' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.target_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.required_date' => ['nullable', 'date'],
        ]);

        $data['created_by'] = $request->user()?->id;

        return $this->createdResponse($this->rfqs->create($data), 'RFQ dibuat');
    }

    public function show(Rfq $rfq): JsonResponse
    {
        return $this->successResponse($rfq->load([
            'event:id,number,title', 'items', 'vendors.vendor:id,code,legal_name', 'quotations.vendor:id,legal_name', 'criteria',
        ]));
    }

    public function issue(Rfq $rfq): JsonResponse
    {
        return $this->successResponse($this->rfqs->issue($rfq), 'RFQ diterbitkan');
    }

    public function close(Rfq $rfq): JsonResponse
    {
        return $this->successResponse($this->rfqs->close($rfq), 'RFQ ditutup');
    }

    public function invite(Request $request, Rfq $rfq): JsonResponse
    {
        $data = $request->validate([
            'vendor_ids' => ['required', 'array', 'min:1'],
            'vendor_ids.*' => ['integer', 'exists:proc_vendors,id'],
        ]);

        return $this->successResponse($this->rfqs->invite($rfq, $data['vendor_ids']), 'Vendor diundang');
    }

    /** Buat RFQ dari peristiwa pengadaan gabungan (§11). */
    public function createFromEvent(Request $request, ProcurementEvent $event): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'deadline' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        return $this->createdResponse($this->rfqs->createFromEvent($event, $data), 'RFQ dibuat dari peristiwa pengadaan');
    }
}
