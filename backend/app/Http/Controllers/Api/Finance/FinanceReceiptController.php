<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceReceiptResource;
use App\Models\FinanceReceipt;
use App\Services\Finance\FinancePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kuitansi (§24, §25): baca dan batalkan. Kuitansi terbit otomatis saat payment
 * confirmed (§24) dan TIDAK pernah dihapus — pembatalan hanya menandai batal (§25).
 */
class FinanceReceiptController extends BaseApiController
{
    public function __construct(private readonly FinancePaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceReceipt::query()
            ->with(['payment', 'student', 'invoice'])
            ->when($request->filled('payment_id'), fn (Builder $q) => $q->where('payment_id', (int) $request->input('payment_id')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->has('cancelled'), fn (Builder $q) => $q->where('is_cancelled', $request->boolean('cancelled')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('receipt_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceReceiptResource::collection($query->get()), 'Daftar kuitansi berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceReceiptResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kuitansi berhasil diambil',
        );
    }

    public function show(FinanceReceipt $receipt): JsonResponse
    {
        $receipt->load(['payment', 'student', 'invoice', 'createdBy']);

        return $this->successResponse(new FinanceReceiptResource($receipt), 'Detail kuitansi berhasil diambil');
    }

    /** Batalkan kuitansi (§25) — tidak menghapus, hanya menandai batal + alasan. */
    public function cancel(Request $request, FinanceReceipt $receipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->payments->cancelReceiptDocument($receipt, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinanceReceiptResource($cancelled), 'Kuitansi dibatalkan');
    }
}
