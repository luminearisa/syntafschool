<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinancePaymentRequest;
use App\Http\Resources\FinancePaymentResource;
use App\Models\FinancePayment;
use App\Services\Finance\FinanceGatewayService;
use App\Services\Finance\FinancePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pembayaran (§19–§22): catat, verifikasi, tolak, batalkan, dan buat charge
 * gateway. Logika uang (jurnal, penerapan invoice, kuitansi) ada di
 * `FinancePaymentService`; controller ini tipis.
 */
class FinancePaymentController extends BaseApiController
{
    public function __construct(
        private readonly FinancePaymentService $payments,
        private readonly FinanceGatewayService $gateway,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinancePayment::query()
            ->with(['student', 'invoice', 'cashAccount', 'bankAccount'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', $request->string('method')))
            ->when($request->filled('invoice_id'), fn (Builder $q) => $q->where('invoice_id', (int) $request->input('invoice_id')))
            ->when($request->filled('billing_id'), fn (Builder $q) => $q->where('billing_id', (int) $request->input('billing_id')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('payment_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinancePaymentResource::collection($query->get()), 'Daftar pembayaran berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinancePaymentResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'statuses' => FinancePayment::STATUSES,
                    'status_labels' => FinancePayment::STATUS_LABELS,
                    'methods' => FinancePayment::METHODS,
                    'method_labels' => FinancePayment::METHOD_LABELS,
                ]]),
            'Daftar pembayaran berhasil diambil',
        );
    }

    public function show(FinancePayment $payment): JsonResponse
    {
        $payment->load(['student', 'invoice', 'billing', 'cashAccount', 'bankAccount', 'verifiedBy', 'receipt', 'refunds', 'allocations.invoice']);

        return $this->successResponse(new FinancePaymentResource($payment), 'Detail pembayaran berhasil diambil');
    }

    /** Catat pembayaran (§19). Tunai/wallet langsung lunas; manual/gateway jadi pending. */
    public function store(FinancePaymentRequest $request): JsonResponse
    {
        $payment = $this->payments->record($request->validated(), $request->user());

        return $this->createdResponse(
            new FinancePaymentResource($payment->load(['invoice', 'billing', 'receipt', 'cashAccount', 'bankAccount'])),
            $payment->status === FinancePayment::STATUS_PENDING
                ? 'Pembayaran dicatat, menunggu verifikasi'
                : 'Pembayaran diterima dan kuitansi terbit',
        );
    }

    /** Verifikasi payment pending menjadi lunas (§21). */
    public function verify(Request $request, FinancePayment $payment): JsonResponse
    {
        $data = $request->validate(['wallet_id' => ['nullable', 'integer']]);

        $verified = $this->payments->verify($payment, $request->user(), isset($data['wallet_id']) ? (int) $data['wallet_id'] : null);

        return $this->successResponse(
            new FinancePaymentResource($verified->load(['invoice', 'billing', 'receipt', 'cashAccount', 'bankAccount'])),
            'Pembayaran diverifikasi dan kuitansi terbit',
        );
    }

    /** Tolak payment pending (bukti tidak valid). */
    public function reject(Request $request, FinancePayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $rejected = $this->payments->reject($payment, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinancePaymentResource($rejected), 'Pembayaran ditolak');
    }

    /** Batalkan payment lunas (§25, §29): jurnal dibalik, invoice dipulihkan, kuitansi void. */
    public function cancel(Request $request, FinancePayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->payments->cancel($payment, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinancePaymentResource($cancelled->load('invoice', 'billing')), 'Pembayaran dibatalkan');
    }

    /** Buat charge gateway untuk payment pending (§20). */
    public function charge(Request $request, FinancePayment $payment): JsonResponse
    {
        $data = $request->validate(['provider' => ['nullable', 'string', 'max:40']]);

        $result = $this->gateway->charge($payment, $data['provider'] ?? null);

        return $this->successResponse($result, 'Charge pembayaran berhasil dibuat');
    }
}
