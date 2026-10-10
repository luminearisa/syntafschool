<?php

use App\Http\Controllers\Api\AcademicExportController;
use App\Http\Controllers\Api\AttendanceCorrectionController;
use App\Http\Controllers\Api\EmployeeAttendanceController;
use App\Http\Controllers\Api\StudentAttendanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A6 — Presensi siswa, presensi pegawai, dan koreksi presensi
|--------------------------------------------------------------------------
|
| File ini dimuat otomatis oleh routes/api.php di dalam grup `v1` +
| `auth:sanctum`, jadi tidak perlu prefix maupun middleware auth di sini.
| Otorisasi ditegakkan per-route: `academic.*` untuk proses akademik dan
| `employee.*` untuk layar kepegawaian (alias yang lama tetap didukung).
|
| Catatan permission: guru memegang `academic.attendance` untuk mencatat
| presensi, sedangkan persetujuan koreksi khusus `academic.approve`.
|
*/

// ------------------------------------------------------------ Presensi siswa
Route::get('student-attendances', [StudentAttendanceController::class, 'index'])
    ->middleware('permission:academic.view')->name('student-attendances.index');

// Route statis didahulukan agar tidak tertangkap `{attendance}`.
Route::get('student-attendances/sheet', [StudentAttendanceController::class, 'sheet'])
    ->middleware('permission:academic.view')->name('student-attendances.sheet');

Route::post('student-attendances/bulk', [StudentAttendanceController::class, 'bulk'])
    ->middleware('permission:academic.attendance|academic.create')->name('student-attendances.bulk');

Route::post('student-attendances', [StudentAttendanceController::class, 'store'])
    ->middleware('permission:academic.attendance|academic.create')->name('student-attendances.store');

Route::get('student-attendances/{attendance}', [StudentAttendanceController::class, 'show'])
    ->middleware('permission:academic.view')->name('student-attendances.show');

Route::match(['put', 'patch'], 'student-attendances/{attendance}', [StudentAttendanceController::class, 'update'])
    ->middleware('permission:academic.attendance|academic.update')->name('student-attendances.update');

Route::delete('student-attendances/{attendance}', [StudentAttendanceController::class, 'destroy'])
    ->middleware('permission:academic.attendance|academic.delete')->name('student-attendances.destroy');

// ------------------------------------------------------------------- Rekap
Route::get('attendance-recap/student/{student}', [StudentAttendanceController::class, 'recapStudent'])
    ->middleware(['permission:academic.view|academic.portal', 'student.family'])->name('attendance-recap.student');

Route::get('attendance-recap/class/{class}', [StudentAttendanceController::class, 'recapClass'])
    ->middleware('permission:academic.view')->name('attendance-recap.class');

Route::get('attendance-recap/export', [AcademicExportController::class, 'attendanceRecap'])
    ->middleware('permission:academic.view')->name('attendance-recap.export');

// Referensi status/izin/semester dapat dibaca staf kepegawaian atau akademik.
Route::get('attendance-lookups', [EmployeeAttendanceController::class, 'lookups'])
    ->middleware('permission:employee.view|academic.view')->name('attendance-lookups');

// ----------------------------------------------------------- Presensi pegawai
Route::get('employee-attendances', [EmployeeAttendanceController::class, 'index'])
    ->middleware('permission:employee.view|academic.view')->name('employee-attendances.index');

Route::get('employee-attendances/recap', [EmployeeAttendanceController::class, 'recap'])
    ->middleware('permission:employee.view|academic.view')->name('employee-attendances.recap');

Route::post('employee-attendances', [EmployeeAttendanceController::class, 'store'])
    ->middleware('permission:employee.create|academic.attendance|academic.create')->name('employee-attendances.store');

Route::get('employee-attendances/{employeeAttendance}', [EmployeeAttendanceController::class, 'show'])
    ->middleware('permission:employee.view|academic.view')->name('employee-attendances.show');

Route::match(['put', 'patch'], 'employee-attendances/{employeeAttendance}', [EmployeeAttendanceController::class, 'update'])
    ->middleware('permission:employee.update|academic.attendance|academic.update')->name('employee-attendances.update');

Route::delete('employee-attendances/{employeeAttendance}', [EmployeeAttendanceController::class, 'destroy'])
    ->middleware('permission:employee.delete|academic.attendance|academic.delete')->name('employee-attendances.destroy');

// ---------------------------------------------------------- Koreksi presensi
Route::get('attendance-corrections', [AttendanceCorrectionController::class, 'index'])
    ->middleware('permission:academic.view')->name('attendance-corrections.index');

Route::post('attendance-corrections', [AttendanceCorrectionController::class, 'store'])
    ->middleware('permission:academic.attendance|academic.create')->name('attendance-corrections.store');

Route::get('attendance-corrections/{correction}', [AttendanceCorrectionController::class, 'show'])
    ->middleware('permission:academic.view')->name('attendance-corrections.show');

Route::post('attendance-corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])
    ->middleware('permission:academic.approve')->name('attendance-corrections.approve');

Route::post('attendance-corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])
    ->middleware('permission:academic.approve')->name('attendance-corrections.reject');
