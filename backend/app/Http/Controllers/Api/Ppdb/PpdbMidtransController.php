<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Ppdb\PpdbMidtransGateway;
use App\Services\Ppdb\PpdbPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback pembayaran Midtrans.
 *
 * Rute publik (tanpa auth) karena yang memanggil server Midtrans, jadi satu-satunya
 * penjaga pintu adalah tanda tangan `SHA512(order_id + status_code + gross_amount +
 * server_key)` yang dibandingkan waktu-konstan + header `X-Route`. Notifikasi tanpa
 * tanda tangan selalu ditolak, kecuali mode unsigned dinyalakan eksplisit lewat
 * `services.midtrans.allow_unsigned_notifications` (uji lokal/mock).
 *
 * Penyelesaian tagihan memakai kode yang sama dengan verifikasi manual
 * (`PpdbPaymentService` → `PpdbInvoiceService::settle()`), dan dipanggil lewat
 * `handleMidtransNotification()` yang idempoten: notifikasi berulang tidak
 * menambah `paid_amount` dua kali.
 */
class PpdbMidtransController extends BaseApiController
{
    public function __construct(
        protected PpdbMidtransGateway $gateway,
        protected PpdbPaymentService $payments,
    ) {
    }

    /**
     * POST /ppdb/payments/midtrans/callback.
     */
    public function callback(Request $request): JsonResponse
    {
        if (! $this->gateway->verifyNotification($request)) {
            Log::warning('Notifikasi Midtrans ditolak: tanda tangan tidak cocok.', [
                'order_id' => (string) data_get($this->gateway->notificationPayload($request), 'transaction_details.order_id', ''),
                'x_route' => (string) $request->header('X-Route', ''),
            ]);

            return $this->errorResponse('Tanda tangan notifikasi tidak valid.', 403);
        }

        $payload = $this->gateway->notificationPayload($request);

        if ($payload === []) {
            return $this->errorResponse('Body notifikasi kosong atau tidak dapat dibaca.', 422);
        }

        $result = $this->payments->handleMidtransNotification($payload);

        return match ($result['status']) {
            'not_found' => $this->errorResponse($result['message'], 422, ['status' => $result['status']]),
            default => $this->successResponse($result, $result['message']),
        };
    }
}
