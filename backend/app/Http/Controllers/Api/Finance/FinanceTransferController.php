<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceTransferRequest;
use App\Http\Resources\FinanceTransferResource;
use App\Models\FinanceTransfer;
use App\Services\Finance\FinanceTransferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transfer internal antar kas/bank (§42, §43): buat → ajukan → approve/reject/cancel
 * → complete. Saldo baru bergerak saat complete (jurnal Dr tujuan / Cr sumber),
 * dan transfer di atas ambang nominal (§16) butuh penyetuju kedua sebelum itu.
 */
class FinanceTransferController extends BaseApiController
{
    private const SIDE_RELATIONS = [
        'sourceCashAccount', 'sourceBankAccount', 'destinationCashAccount', 'destinationBankAccount',
    ];

    private const PEOPLE_RELATIONS = ['approvedBy', 'secondaryApprovedBy', 'cancelledBy', 'createdBy'];

    public function __construct(private readonly FinanceTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceTransfer::query()
            ->with(self::SIDE_RELATIONS)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('transfer_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('transfer_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where('transfer_number', 'like', '%'.trim((string) $request->input('search')).'%'))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceTransferResource::collection($query->get()), 'Daftar transfer berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceTransferResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceTransfer::STATUSES, 'status_labels' => FinanceTransfer::STATUS_LABELS]]),
            'Daftar transfer berhasil diambil',
        );
    }

    public function show(FinanceTransfer $transfer): JsonResponse
    {
        $transfer->load([...self::SIDE_RELATIONS, ...self::PEOPLE_RELATIONS]);

        return $this->successResponse(new FinanceTransferResource($transfer), 'Detail transfer berhasil diambil');
    }

    public function store(FinanceTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceTransferResource($transfer->load(self::SIDE_RELATIONS)),
            'Transfer berhasil dibuat',
        );
    }

    public function submit(Request $request, FinanceTransfer $transfer): JsonResponse
    {
        $updated = $this->transfers->submit($transfer, $request->user());

        return $this->successResponse(new FinanceTransferResource($updated->load(self::SIDE_RELATIONS)), 'Transfer diajukan');
    }

    public function approve(Request $request, FinanceTransfer $transfer): JsonResponse
    {
        $updated = $this->transfers->approve($transfer, $request->user());

        return $this->successResponse(new FinanceTransferResource($updated->load(self::SIDE_RELATIONS)), 'Transfer disetujui');
    }

    /** Persetujuan kedua untuk transfer di atas ambang nominal (§16). */
    public function secondaryApprove(Request $request, FinanceTransfer $transfer): JsonResponse
    {
        $updated = $this->transfers->approveSecondary($transfer, $request->user());

        return $this->successResponse(
            new FinanceTransferResource($updated->load([...self::SIDE_RELATIONS, 'secondaryApprovedBy'])),
            'Persetujuan kedua tercatat',
        );
    }

    public function reject(Request $request, FinanceTransfer $transfer): JsonResponse
    {
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->transfers->reject($transfer, (string) $data['reject_reason'], $request->user());

        return $this->successResponse(new FinanceTransferResource($updated->load(self::SIDE_RELATIONS)), 'Transfer ditolak');
    }

    /** Batalkan transfer yang belum dieksekusi (§3 CANCELLED). */
    public function cancel(Request $request, FinanceTransfer $transfer): JsonResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->transfers->cancel($transfer, (string) $data['cancel_reason'], $request->user());

        return $this->successResponse(new FinanceTransferResource($updated->load(self::SIDE_RELATIONS)), 'Transfer dibatalkan');
    }

    /** Eksekusi transfer yang sudah disetujui: posting jurnal + gerakkan saldo (§43). */
    public function complete(Request $request, FinanceTransfer $transfer): JsonResponse
    {
        $updated = $this->transfers->complete($transfer, $request->user());

        return $this->successResponse(new FinanceTransferResource($updated->load(self::SIDE_RELATIONS)), 'Transfer diselesaikan');
    }
}
