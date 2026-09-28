<?php

use App\Http\Controllers\Api\Wallet\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('wallet')->name('wallet.')->group(function (): void {
    Route::get('students', [WalletController::class, 'studentOptions'])
        ->middleware('permission:wallet.create')->name('students.options');
    Route::get('wallets', [WalletController::class, 'index'])
        ->middleware('permission:wallet.view')->name('wallets.index');
    Route::post('wallets', [WalletController::class, 'store'])
        ->middleware('permission:wallet.create')->name('wallets.store');
    Route::get('wallets/{wallet}', [WalletController::class, 'show'])
        ->middleware('permission:wallet.view')->name('wallets.show');
    Route::patch('wallets/{wallet}', [WalletController::class, 'update'])
        ->middleware('permission:wallet.update')->name('wallets.update');
    Route::get('wallets/{wallet}/ledger', [WalletController::class, 'ledger'])
        ->middleware('permission:wallet.ledger.view')->name('wallets.ledger');
    Route::post('wallets/{wallet}/block', [WalletController::class, 'block'])
        ->middleware('permission:wallet.block')->name('wallets.block');
    Route::post('wallets/{wallet}/unblock', [WalletController::class, 'unblock'])
        ->middleware('permission:wallet.unblock')->name('wallets.unblock');
    Route::post('wallets/{wallet}/close', [WalletController::class, 'close'])
        ->middleware('permission:wallet.close')->name('wallets.close');
});
