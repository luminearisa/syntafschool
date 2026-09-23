<?php

use App\Http\Controllers\Api\Wallet\WalletTopUpWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Wallet — callback pembayaran top up (§11, §30).
 *
 * File ini ikut di-require `routes/api.php` di dalam grup `auth:sanctum` + prefix
 * `v1`, tetapi rutenya tetap publik karena yang memanggil adalah server payment
 * gateway, bukan pengguna yang punya token. Cara mempublikasikannya: melepas
 * middleware auth warisan grup induk lewat `withoutMiddleware` — pola yang sama
 * dengan callback Midtrans PPDB (`routes/api/ppdb.php`).
 *
 * Throttle dipasang karena satu-satunya penjaga pintu adalah tanda tangan SHA-512 di
 * dalam `PaymentGateway::verifySignature()`: tanpa batas ini, pihak anonim bisa
 * menggencarkan tebakan tanda tangan ke endpoint yang mengatur uang orang.
 */
Route::prefix('wallet')->group(function (): void {
    Route::post('webhooks/payment/{provider}', [WalletTopUpWebhookController::class, 'handle'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:60,1')
        ->name('wallet.webhooks.payment');
});
