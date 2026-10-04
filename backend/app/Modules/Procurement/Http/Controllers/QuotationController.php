<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\QuotationEvaluationService;
use App\Modules\Procurement\Application\Services\QuotationService;
use App\Modules\Procurement\Models\Quotation;
use App\Modules\Procurement\Models\Rfq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Penawaran vendor, perbandingan, dan evaluasi (§13–§15). */
class QuotationController extends BaseApiController
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly QuotationEvaluationService $evaluations,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->quotations->paginate(
            $request->only(['rfq_id', 'vendor_id', 'status']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rfq_id' => ['nullable', 'integer', 'exists:proc_rfqs,id'],
            'vendor_id' => ['required', 'integer', 'exists:proc_vendors,id'],
            'event_id' => ['nullable', 'integer', 'exists:proc_events,id'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'delivery_terms' => ['nullable', 'string', 'max:120'],
            'delivery_days' => ['nullable', 'integer', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.rfq_item_id' => ['nullable', 'integer', 'exists:proc_rfq_items,id'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:proc_items,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['submitted_by'] = $request->user()?->id;

        return $this->createdResponse($this->quotations->record($data), 'Penawaran dicatat');
    }

    public function show(Quotation $quotation): JsonResponse
    {
        return $this->successResponse($quotation->load(['vendor:id,code,legal_name', 'items', 'documents', 'evaluations.items']));
    }

    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $request->validate([
            'valid_until' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'delivery_terms' => ['nullable', 'string', 'max:120'],
            'delivery_days' => ['nullable', 'integer', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        return $this->successResponse($this->quotations->update($quotation, $data), 'Penawaran diperbarui');
    }

    /** Matriks perbandingan penawaran (§14). */
    public function comparison(Rfq $rfq): JsonResponse
    {
        return $this->successResponse($this->quotations->comparison($rfq->id));
    }

    /** Kriteria evaluasi efektif untuk RFQ (§15). */
    public function criteria(Rfq $rfq): JsonResponse
    {
        return $this->successResponse($this->evaluations->criteria($rfq->id));
    }

    public function defineCriteria(Request $request, Rfq $rfq): JsonResponse
    {
        $data = $request->validate([
            'criteria' => ['required', 'array', 'min:1'],
            'criteria.*.code' => ['required', 'string', 'max:40'],
            'criteria.*.name' => ['required', 'string', 'max:120'],
            'criteria.*.weight' => ['required', 'numeric', 'min:0'],
            'criteria.*.max_score' => ['nullable', 'numeric', 'min:1'],
            'criteria.*.type' => ['nullable', 'in:NUMERIC,YESNO,PASSFAIL'],
        ]);

        $this->evaluations->defineCriteria($rfq->id, $data['criteria']);

        return $this->successResponse($this->evaluations->criteria($rfq->id), 'Kriteria evaluasi disimpan');
    }

    /** Simpan evaluasi satu penawaran. */
    public function evaluate(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $request->validate([
            'comments' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string', 'max:255'],
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.code' => ['required', 'string', 'max:40'],
            'scores.*.score' => ['required', 'numeric', 'min:0'],
            'scores.*.comment' => ['nullable', 'string', 'max:500'],
            'scores.*.evidence_path' => ['nullable', 'string', 'max:255'],
        ]);

        $data['evaluator_id'] = $request->user()?->id;

        return $this->createdResponse($this->evaluations->evaluate($quotation, $data), 'Evaluasi disimpan');
    }

    /** Peringkat penawaran (informasi). */
    public function ranking(Rfq $rfq): JsonResponse
    {
        return $this->successResponse($this->evaluations->ranking($rfq->id));
    }
}
