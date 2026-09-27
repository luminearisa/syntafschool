<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceRefundRequest;
use App\Http\Resources\FinanceRefundResource;
use App\Models\FinancePayment;
use App\Models\FinanceRefund;
use App\Services\Finance\FinanceRefundService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refund (§29): ajukan → setujui → selesaikan (atau tolak). Payment asli tidak
 * dihapus; penyelesaian memposting jurnal balik dan memulihkan sisa invoice.
 */
class FinanceRefundController extends BaseApiController
{
    public function __construct(private readonly FinanceRefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceRefund::query()
            ->with(['payment', 'cashAccount', 'bankAccount', 'requestedBy', 'approvedBy'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_id'), fn (Builder $q) => $q->where('payment_id', (int) $request->input('payment_id')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('refund_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceRefundResource::collection($query->get()), 'Daftar refund berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceRefundResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceRefund::STATUSES, 'status_labels' => FinanceRefund::STATUS_LABELS]]),
            'Daftar refund berhasil diambil',
        );
    }

    public function show(FinanceRefund $refund): JsonResponse
    {
        $refund->load(['payment', 'cashAccount', 'bankAccount', 'requestedBy', 'approvedBy']);

        return $this->successResponse(new FinanceRefundResource($refund), 'Detail refund berhasil diambil');
    }

    /** Ajukan refund atas sebuah payment (§29). */
    public function store(FinanceRefundRequest $request, FinancePayment $payment): JsonResponse
    {
        $refund = $this->refunds->request($payment, $request->validated(), $request->user());

        return $this->createdResponse(new FinanceRefundResource($refund->load('payment')), 'Refund berhasil diajukan');
    }

    public function approve(Request $request, FinanceRefund $refund): JsonResponse
    {
        $approved = $this->refunds->approve($refund, $request->user());

        return $this->successResponse(new FinanceRefundResource($approved), 'Refund disetujui');
    }

    public function reject(Request $request, FinanceRefund $refund): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $rejected = $this->refunds->reject($refund, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinanceRefundResource($rejected), 'Refund ditolak');
    }

    /** Selesaikan refund: posting jurnal keluar + pulihkan sisa invoice (§29). */
    public function complete(Request $request, FinanceRefund $refund): JsonResponse
    {
        $completed = $this->refunds->complete($refund, $request->user());

        return $this->successResponse(new FinanceRefundResource($completed->load('payment')), 'Refund diselesaikan');
    }
}
