<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Wallet\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback pembayaran top up dari payment gateway (§11, §30).
 *
 * Rutenya PUBLIK (`withoutMiddleware(['auth:sanctum'])`) karena yang memanggil adalah
 * server penyedia, bukan pengguna. Satu-satunya penjaga pintu adalah tanda tangan
 * provider yang diverifikasi `PaymentGateway::verifySignature()`, ditambah throttle
 * supaya tebakan tanda tangan dari pihak anonim tidak bisa digencarkan.
 *
 * Balasan untuk keadaan "tidak bisa diproses" sengaja 200, bukan 4xx/5xx: penyedia
 * yang menerima galat akan mengirim ulang notifikasi yang sama berkali-kali (retry
 * bomb), padahal hasilnya tetap sama — permintaan sudah diproses atau memang bukan
 * miliknya. Yang benar-benar gagal tanda tangan dibalas 403 agar mudah diperiksa,
 * dan setiap keputusan dicatat di log aplikasi.
 */
class WalletTopUpWebhookController extends BaseApiController
{
    /** Keadaan yang berarti "sudah kami catat, tidak perlu dikirim ulang". */
    private const ACKNOWLEDGED = ['processed', 'stale', 'ignored', 'not_found', 'amount_mismatch', 'rejected'];

    public function __construct(protected WalletTopUpService $topups)
    {
    }

    /**
     * POST /wallet/webhooks/payment/{provider}.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $result = $this->topups->handleWebhook($provider, $request);

        $status = (string) ($result['status'] ?? 'ignored');

        if (! in_array($status, self::ACKNOWLEDGED, true)) {
            Log::info('Notifikasi pembayaran wallet tidak diproses.', [
                'provider' => $provider,
                'status' => $status,
                'message' => $result['message'] ?? null,
            ]);
        }

        // Provider tidak membaca makna balasan; yang mereka baca hanya status HTTP.
        return match ($status) {
            'invalid_signature' => $this->errorResponse((string) ($result['message'] ?? 'Tanda tangan tidak valid.'), 403, ['status' => $status]),
            'unsupported' => $this->errorResponse((string) ($result['message'] ?? 'Provider tidak dikenal.'), 404, ['status' => $status]),
            default => $this->successResponse($result, (string) ($result['message'] ?? 'Notifikasi diterima.')),
        };
    }
}
