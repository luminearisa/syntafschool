<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\VendorSelectionService;
use App\Modules\Procurement\Models\Rfq;
use App\Modules\Procurement\Models\VendorSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pemilihan vendor (§16). */
class VendorSelectionController extends BaseApiController
{
    public function __construct(private readonly VendorSelectionService $selections)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = VendorSelection::query()
            ->with(['selectedVendor:id,code,legal_name', 'selectedQuotation:id,number,total', 'rfq:id,number,title'])
            ->when($request->query('rfq_id'), fn ($q, $id) => $q->where('rfq_id', (int) $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return $this->successResponse($page);
    }

    /** Pilih vendor untuk sebuah RFQ. */
    public function store(Request $request, Rfq $rfq): JsonResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', 'integer', 'exists:proc_quotations,id'],
            'rationale' => ['required', 'string', 'min:10'],
            'recommendation' => ['nullable', 'string', 'max:255'],
            'alternative_vendor_id' => ['nullable', 'integer', 'exists:proc_vendors,id'],
            'rejection_summary' => ['nullable', 'array'],
            'overridden' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->selections->select($rfq->id, $data), 'Vendor dipilih');
    }

    public function show(VendorSelection $selection): JsonResponse
    {
        return $this->successResponse($selection->load([
            'selectedVendor', 'selectedQuotation.items', 'alternativeVendor', 'approvedBy:id,name', 'rfq:id,number,title',
        ]));
    }

    public function approve(Request $request, VendorSelection $selection): JsonResponse
    {
        return $this->successResponse(
            $this->selections->approve($selection, $request->user()?->id),
            'Pemilihan vendor disetujui',
        );
    }
}
