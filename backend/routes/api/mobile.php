<?php

use App\Http\Controllers\Api\Mobile\MobileController;
use Illuminate\Support\Facades\Route;

// Aplikasi mobile — wali murid · siswa · pegawai. Dibatasi KONTEKS token
// (lihat EnforceContext/RequireContext) dan KEPEMILIKAN data anak.
Route::prefix('mobile')->name('mobile.')->group(function (): void {
    // Notifikasi: semua peran
    Route::get('notifications', [MobileController::class, 'notifications'])->name('notifications');
    Route::post('notifications/read-all', [MobileController::class, 'readAllNotifications'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [MobileController::class, 'readNotification'])->name('notifications.read');

    // Wali murid & siswa
    Route::middleware('ctx:guardian,student')->group(function (): void {
        Route::get('home', [MobileController::class, 'home'])->name('home');
        Route::get('children', [MobileController::class, 'children'])->name('children');
        Route::get('children/{student}/attendance', [MobileController::class, 'attendance'])->name('children.attendance');
        Route::get('children/{student}/scores', [MobileController::class, 'scores'])->name('children.scores');
        Route::get('children/{student}/report-cards', [MobileController::class, 'reportCards'])->name('children.report-cards');
        Route::get('children/{student}/schedule', [MobileController::class, 'schedule'])->name('children.schedule');
        Route::get('children/{student}/finance', [MobileController::class, 'finance'])->name('children.finance');
        Route::get('children/{student}/boarding', [MobileController::class, 'boarding'])->name('children.boarding');
    });

    // Pegawai
    Route::middleware('ctx:employee')->prefix('employee')->name('employee.')->group(function (): void {
        Route::get('home', [MobileController::class, 'employeeHome'])->name('home');
        Route::get('attendance', [MobileController::class, 'employeeAttendance'])->name('attendance');
        Route::post('attendance/check-in', [MobileController::class, 'checkIn'])->name('check-in');
        Route::post('attendance/check-out', [MobileController::class, 'checkOut'])->name('check-out');
    });
});
