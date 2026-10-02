<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserIdentityController;
use Illuminate\Support\Facades\Route;

// Konteks/peran akun (pegawai · wali murid · siswa) untuk aplikasi mobile.
Route::get('auth/contexts', [AuthController::class, 'contexts'])->name('auth.contexts');
Route::post('auth/switch-context', [AuthController::class, 'switchContext'])->name('auth.switch-context');

// Admin: tautkan satu akun ke identitas pegawai / wali / siswa.
Route::get('users/{user}/identities', [UserIdentityController::class, 'index'])
    ->middleware('permission:user.view')->name('users.identities.index');
Route::get('users/{user}/identities/suggestions', [UserIdentityController::class, 'suggestions'])
    ->middleware('permission:user.view')->name('users.identities.suggestions');
Route::post('users/{user}/identities', [UserIdentityController::class, 'store'])
    ->middleware('permission:user.update')->name('users.identities.store');
Route::delete('users/{user}/identities/{identity}', [UserIdentityController::class, 'destroy'])
    ->middleware('permission:user.update')->name('users.identities.destroy');
