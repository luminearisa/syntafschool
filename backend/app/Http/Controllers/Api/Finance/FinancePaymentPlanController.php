<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinancePaymentPlanRequest;
use App\Http\Resources\FinancePaymentPlanResource;
use App\Models\FinancePaymentPlan;
use App\Services\Finance\FinancePaymentPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rencana pembayaran / cicilan (§7).
 *
 * Tidak ada satu pun endpoint di sini yang menerima nominal terbayar atau
 * status: keduanya dihitung dari pelunasan invoice aslinya. Tidak ada pula
 * endpoint "bayar" — membayar angsuran ya lewat endpoint payment biasa (§19),
 * yang memang membuat uang berpindah dan jurnal ter-post.
 */
class FinancePaymentPlanController extends BaseApiController
{
    public function __construct(private readonly FinancePaymentPlanService $plans) {}

    public function index(Request $request): JsonResponse
    {
        $collection = $this->plans->plans($request->query());

        return $this->successResponse(
            FinancePaymentPlanResource::collection($collection)->additional([
                'meta' => ['config' => $this->plans->config()],
            ]),
            'Daftar rencana pembayaran berhasil diambil',
        );
    }

    public function store(FinancePaymentPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->validated(), $request->user());

        return $this->createdResponse(
            new FinancePaymentPlanResource($plan->loadMissing(['invoice', 'student', 'createdBy'])),
            'Rencana pembayaran berhasil dibuat',
        );
    }

    public function show(FinancePaymentPlan $paymentPlan): JsonResponse
    {
        return $this->successResponse(
            new FinancePaymentPlanResource($this->plans->show($paymentPlan)),
            'Detail rencana pembayaran berhasil diambil',
        );
    }

    /** Batalkan jadwal sisanya. Pembayaran yang sudah masuk tidak ikut batal. */
    public function cancel(Request $request, FinancePaymentPlan $paymentPlan): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $plan = $this->plans->cancel($paymentPlan, $data, $request->user());

        return $this->successResponse(
            new FinancePaymentPlanResource($plan->loadMissing(['invoice', 'student', 'cancelledBy'])),
            'Rencana pembayaran berhasil dibatalkan',
        );
    }
}
