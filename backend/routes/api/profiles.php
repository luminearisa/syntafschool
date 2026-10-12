<?php

use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

// Profil 360°: halaman lengkap per siswa / wali / pegawai (dimuat per bagian).
// Izin dasar di sini; izin per bagian (keuangan, kesehatan, konseling, gaji, …)
// ditegakkan di ProfileProvider. Profil siswa dipagari `student.family`
// sehingga akun wali/siswa hanya dapat membuka dirinya/anaknya sendiri.
Route::prefix('profiles')->name('profiles.')->group(function (): void {
    Route::get('students/{student}', [ProfileController::class, 'student'])
        ->middleware(['permission:student.view', 'student.family', 'throttle:120,1'])->name('students.show');
    Route::get('students/{student}/sections/{section}', [ProfileController::class, 'studentSection'])
        ->middleware(['permission:student.view', 'student.family', 'throttle:240,1'])->name('students.section');

    Route::get('guardians/{guardian}', [ProfileController::class, 'guardian'])
        ->middleware(['permission:student.view', 'throttle:120,1'])->name('guardians.show');
    Route::get('guardians/{guardian}/sections/{section}', [ProfileController::class, 'guardianSection'])
        ->middleware(['permission:student.view', 'throttle:240,1'])->name('guardians.section');

    Route::get('employees/{employee}', [ProfileController::class, 'employee'])
        ->middleware(['permission:employee.view', 'throttle:120,1'])->name('employees.show');
    Route::get('employees/{employee}/sections/{section}', [ProfileController::class, 'employeeSection'])
        ->middleware(['permission:employee.view', 'throttle:240,1'])->name('employees.section');
});
