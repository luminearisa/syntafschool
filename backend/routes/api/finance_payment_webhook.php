<?php

use App\Http\Controllers\Api\Finance\FinancePaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Finance — callback pembayaran tagihan dari payment gateway (§20, §66).
 *
 * Dipisah dari `finance.php` karena rutenya PUBLIK: yang memanggil adalah server
 * penyedia, bukan pengguna bertoken. File ini tetap di-require `routes/api.php`
 * di dalam grup `auth:sanctum` + prefix `v1`, lalu middleware auth warisan dilepas
 * lewat `withoutMiddleware` — pola yang sama dengan `wallet_topup_webhook.php`.
 *
 * Throttle dipasang karena satu-satunya penjaga pintu adalah tanda tangan provider
 * (`PaymentGateway::verifySignature()`); tanpa batas ini pihak anonim bisa
 * menggencarkan tebakan tanda tangan ke endpoint yang mengatur uang.
 */
Route::prefix('finance')->group(function (): void {
    Route::post('webhooks/payment/{provider}', [FinancePaymentWebhookController::class, 'handle'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:60,1')
        ->name('finance.webhooks.payment');
});
