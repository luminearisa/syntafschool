<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinancePurchaseRequestRequest;
use App\Http\Resources\FinancePurchaseRequestResource;
use App\Models\FinancePurchaseRequest;
use App\Services\Finance\FinancePurchaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase request (§37): buat → ajukan → approve/reject. PR yang disetujui jadi
 * dasar pembuatan PO di {@see FinancePurchaseOrderController}.
 */
class FinancePurchaseRequestController extends BaseApiController
{
    public function __construct(private readonly FinancePurchaseService $purchasing) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinancePurchaseRequest::query()
            ->with(['requester', 'costCenter', 'approvedBy', 'items'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cost_center_id'), fn (Builder $q) => $q->where('cost_center_id', (int) $request->input('cost_center_id')))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('requester_id', $request->user()?->id))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('request_number', 'like', "%$term%")
                    ->orWhere('description', 'like', "%$term%"));
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinancePurchaseRequestResource::collection($query->get()), 'Daftar purchase request berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinancePurchaseRequestResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinancePurchaseRequest::STATUSES]]),
            'Daftar purchase request berhasil diambil',
        );
    }

    public function show(FinancePurchaseRequest $purchaseRequest): JsonResponse
    {
        $purchaseRequest->load(['requester', 'costCenter', 'approvedBy', 'items']);

        return $this->successResponse(new FinancePurchaseRequestResource($purchaseRequest), 'Detail purchase request berhasil diambil');
    }

    public function store(FinancePurchaseRequestRequest $request): JsonResponse
    {
        $purchaseRequest = $this->purchasing->createRequest($request->validated(), $request->user());

        return $this->createdResponse(new FinancePurchaseRequestResource($purchaseRequest), 'Purchase request berhasil dibuat');
    }

    public function submit(Request $request, FinancePurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchasing->submitRequest($purchaseRequest, $request->user());

        return $this->successResponse(new FinancePurchaseRequestResource($updated), 'Purchase request diajukan');
    }

    public function approve(Request $request, FinancePurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchasing->approveRequest($purchaseRequest, $request->user());

        return $this->successResponse(new FinancePurchaseRequestResource($updated), 'Purchase request disetujui');
    }

    public function reject(Request $request, FinancePurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->purchasing->rejectRequest($purchaseRequest, (string) $data['reject_reason'], $request->user());

        return $this->successResponse(new FinancePurchaseRequestResource($updated), 'Purchase request ditolak');
    }

    public function cancel(FinancePurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchasing->cancelRequest($purchaseRequest);

        return $this->successResponse(new FinancePurchaseRequestResource($updated), 'Purchase request dibatalkan');
    }
}
