<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Finance\FinanceGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Callback pembayaran tagihan dari payment gateway (§20, §66).
 *
 * Rutenya PUBLIK (`withoutMiddleware(['auth:sanctum'])`) — yang memanggil server
 * penyedia, bukan pengguna bertoken. Penjaga pintu satu-satunya adalah tanda tangan
 * provider (`PaymentGateway::verifySignature()`) plus throttle di rute.
 *
 * Webhook WAJIB idempoten (§66): notifikasi ganda tidak boleh menghasilkan
 * pembayaran ganda — `FinanceGatewayService` hanya memproses payment yang masih
 * `pending`. Balasan "tidak diproses" sengaja 200 agar penyedia tidak mengirim
 * ulang (retry bomb); hanya tanda tangan salah (403) dan provider tak dikenal (404)
 * yang dibalas galat supaya mudah diperiksa.
 */
class FinancePaymentWebhookController extends BaseApiController
{
    public function __construct(private readonly FinanceGatewayService $gateway) {}

    /**
     * POST /finance/webhooks/payment/{provider}.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $result = $this->gateway->handleWebhook($request, $provider);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['signature'])) {
                return $this->errorResponse('Tanda tangan notifikasi tidak valid.', 403, ['status' => 'invalid_signature']);
            }

            if (isset($errors['provider'])) {
                return $this->errorResponse($e->getMessage(), 404, ['status' => 'unsupported']);
            }

            throw $e;
        }

        if (! ($result['handled'] ?? false)) {
            Log::info('Notifikasi pembayaran finance tidak diproses.', [
                'provider' => $provider,
                'status' => $result['status'] ?? null,
                'payment_id' => $result['payment_id'] ?? null,
            ]);
        }

        return $this->successResponse($result, 'Notifikasi diterima.');
    }
}
