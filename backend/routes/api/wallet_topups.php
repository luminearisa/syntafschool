<?php

use App\Http\Controllers\Api\Wallet\WalletTopUpController;
use Illuminate\Support\Facades\Route;

/*
 * Wallet — pengajuan & verifikasi top up (§10–§13).
 *
 * Dimuat otomatis oleh `routes/api.php` di dalam grup `auth:sanctum` + prefix `v1`,
 * jadi seluruhnya terautentikasi; tidak ada `Route::prefix('v1')` atau
 * `middleware('auth:sanctum')` di sini.
 *
 * Otorisasi cukup di sini — satu `permission:` per route, tanpa pemeriksaan tambahan
 * di controller (§2). Pemisahan granted-nya sengaja mengikuti keputusan bisnis:
 * `wallet.topup.create` (mengajukan), `wallet.topup.verify` (menyetujui = menambah
 * saldo orang lain), `wallet.topup.reject` (menolak), `wallet.topup.view` (daftar).
 *
 * Webhook pembayaran TIDAK ada di file ini: ia publik dan punya berkasnya sendiri
 * (`wallet_topup_webhook.php`).
 */
Route::prefix('wallet')->group(function (): void {
    Route::get('topups', [WalletTopUpController::class, 'index'])
        ->middleware('permission:wallet.topup.view')->name('wallet.topups.index');

    Route::get('topups/{topup}', [WalletTopUpController::class, 'show'])
        ->middleware('permission:wallet.topup.view')->name('wallet.topups.show');

    Route::post('wallets/{wallet}/topups', [WalletTopUpController::class, 'store'])
        ->middleware('permission:wallet.topup.create')->name('wallet.topups.store');

    Route::post('topups/{topup}/verify', [WalletTopUpController::class, 'verify'])
        ->middleware('permission:wallet.topup.verify')->name('wallet.topups.verify');

    Route::post('topups/{topup}/reject', [WalletTopUpController::class, 'reject'])
        ->middleware('permission:wallet.topup.reject')->name('wallet.topups.reject');
});
