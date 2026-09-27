<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceCashierSessionRequest;
use App\Http\Resources\FinanceCashierSessionResource;
use App\Models\FinanceCashierSession;
use App\Services\Finance\FinanceCashierSessionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesi kasir (§22, §23): buka, tutup (hitung selisih), dan review. Logika ada di
 * `FinanceCashierSessionService`; controller tipis. Review dipisahkan dari kasir
 * lewat permission `finance.cashier.review` + aturan anti-review-sendiri.
 */
class FinanceCashierSessionController extends BaseApiController
{
    public function __construct(private readonly FinanceCashierSessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceCashierSession::query()
            ->with(['cashier', 'cashAccount', 'reviewedBy'])
            ->withCount('payments')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cashier_id'), fn (Builder $q) => $q->where('cashier_id', (int) $request->input('cashier_id')))
            ->when($request->filled('cash_account_id'), fn (Builder $q) => $q->where('cash_account_id', (int) $request->input('cash_account_id')))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('cashier_id', $request->user()?->id))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('opened_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('opened_at', '<=', $request->input('date_to')))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceCashierSessionResource::collection($query->get()), 'Daftar sesi kasir berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceCashierSessionResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceCashierSession::STATUSES, 'status_labels' => FinanceCashierSession::STATUS_LABELS]]),
            'Daftar sesi kasir berhasil diambil',
        );
    }

    public function show(FinanceCashierSession $cashierSession): JsonResponse
    {
        $cashierSession->load(['cashier', 'cashAccount', 'reviewedBy'])->loadCount('payments');

        return $this->successResponse(new FinanceCashierSessionResource($cashierSession), 'Detail sesi kasir berhasil diambil');
    }

    /** Buka sesi kasir baru (§22). */
    public function store(FinanceCashierSessionRequest $request): JsonResponse
    {
        $session = $this->sessions->open($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceCashierSessionResource($session->load(['cashier', 'cashAccount'])),
            'Sesi kasir dibuka',
        );
    }

    /** Tutup sesi: hitung expected vs actual, selisih wajib beralasan (§23). */
    public function close(Request $request, FinanceCashierSession $cashierSession): JsonResponse
    {
        $data = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'difference_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $closed = $this->sessions->close($cashierSession, $data, $request->user());

        return $this->successResponse(new FinanceCashierSessionResource($closed), 'Sesi kasir ditutup');
    }

    /** Review sesi tertutup (§23) — oleh finance, bukan kasir pemilik sesi. */
    public function review(Request $request, FinanceCashierSession $cashierSession): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $reviewed = $this->sessions->review($cashierSession, $request->user(), $data['notes'] ?? null);

        return $this->successResponse(new FinanceCashierSessionResource($reviewed), 'Sesi kasir direview');
    }
}
