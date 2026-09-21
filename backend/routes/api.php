<?php

use App\Http\Controllers\Api\AcademicController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassSubjectController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TimeSlotController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // -----------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // -------------------------------------------------------------
        // RBAC — Pengguna
        // -------------------------------------------------------------
        Route::get('users', [UserController::class, 'index'])
            ->middleware('permission:user.view')->name('users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('permission:user.create')->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])
            ->middleware('permission:user.view')->name('users.show');
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])
            ->middleware('permission:user.update')->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->middleware('permission:user.delete')->name('users.destroy');
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])
            ->middleware('permission:user.update')->name('users.toggle-active');

        // -------------------------------------------------------------
        // RBAC — Role & Permission
        // -------------------------------------------------------------
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:user.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('permission:user.create')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])
            ->middleware('permission:user.view')->name('roles.show');
        Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])
            ->middleware('permission:user.update')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:user.delete')->name('roles.destroy');

        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:user.view')->name('permissions.index');

        // -------------------------------------------------------------
        // Akademik — periode aktif (tahun ajaran & semester)
        // -------------------------------------------------------------
        Route::get('academic/active', [AcademicController::class, 'active'])
            ->middleware('permission:academic.view')->name('academic.active');
        Route::post('academic-years/{academicYear}/activate', [AcademicController::class, 'activateYear'])
            ->middleware('permission:academic.update')->name('academic-years.activate');
        Route::post('semesters/{semester}/activate', [AcademicController::class, 'activateSemester'])
            ->middleware('permission:academic.update')->name('semesters.activate');

        // -------------------------------------------------------------
        // Akademik — Kelas & Rombel
        // -------------------------------------------------------------
        Route::get('classes', [SchoolClassController::class, 'index'])
            ->middleware('permission:academic.view')->name('classes.index');
        Route::post('classes', [SchoolClassController::class, 'store'])
            ->middleware('permission:academic.create')->name('classes.store');
        Route::get('classes/{class}', [SchoolClassController::class, 'show'])
            ->middleware('permission:academic.view')->name('classes.show');
        Route::match(['put', 'patch'], 'classes/{class}', [SchoolClassController::class, 'update'])
            ->middleware('permission:academic.update')->name('classes.update');
        Route::delete('classes/{class}', [SchoolClassController::class, 'destroy'])
            ->middleware('permission:academic.delete')->name('classes.destroy');
        Route::get('classes/{class}/students', [SchoolClassController::class, 'students'])
            ->middleware('permission:academic.view')->name('classes.students.index');
        Route::post('classes/{class}/students', [SchoolClassController::class, 'addStudents'])
            ->middleware('permission:academic.update')->name('classes.students.store');
        Route::delete('classes/{class}/students/{student}', [SchoolClassController::class, 'removeStudent'])
            ->middleware('permission:academic.update')->name('classes.students.destroy');

        // -------------------------------------------------------------
        // Akademik — Mapel per kelas (struktur pengajaran rombel)
        // -------------------------------------------------------------
        Route::get('classes/{class}/subjects', [ClassSubjectController::class, 'index'])
            ->middleware('permission:academic.view')->name('classes.subjects.index');
        Route::post('classes/{class}/subjects', [ClassSubjectController::class, 'store'])
            ->middleware('permission:academic.update')->name('classes.subjects.store');
        Route::post('classes/{class}/subjects/copy', [ClassSubjectController::class, 'copy'])
            ->middleware('permission:academic.update')->name('classes.subjects.copy');
        Route::match(['put', 'patch'], 'classes/{class}/subjects/{classSubject}', [ClassSubjectController::class, 'update'])
            ->middleware('permission:academic.update')->name('classes.subjects.update');
        Route::delete('classes/{class}/subjects/{classSubject}', [ClassSubjectController::class, 'destroy'])
            ->middleware('permission:academic.update')->name('classes.subjects.destroy');

        // -------------------------------------------------------------
        // Akademik — Jam pelajaran (kerangka waktu grid jadwal)
        // -------------------------------------------------------------
        Route::get('time-slots', [TimeSlotController::class, 'index'])
            ->middleware('permission:academic.view')->name('time-slots.index');
        Route::post('time-slots', [TimeSlotController::class, 'store'])
            ->middleware('permission:academic.create')->name('time-slots.store');
        Route::post('time-slots/generate', [TimeSlotController::class, 'generate'])
            ->middleware('permission:academic.create')->name('time-slots.generate');
        Route::match(['put', 'patch'], 'time-slots/{timeSlot}', [TimeSlotController::class, 'update'])
            ->middleware('permission:academic.update')->name('time-slots.update');
        Route::delete('time-slots/{timeSlot}', [TimeSlotController::class, 'destroy'])
            ->middleware('permission:academic.delete')->name('time-slots.destroy');

        // -------------------------------------------------------------
        // Akademik — Jadwal pelajaran (4 deteksi bentrok)
        // -------------------------------------------------------------
        Route::get('schedules', [ScheduleController::class, 'index'])
            ->middleware('permission:academic.view')->name('schedules.index');
        Route::post('schedules', [ScheduleController::class, 'store'])
            ->middleware('permission:academic.create')->name('schedules.store');
        Route::post('schedules/check-conflict', [ScheduleController::class, 'checkConflict'])
            ->middleware('permission:academic.view')->name('schedules.check-conflict');
        Route::get('schedules/grid', [ScheduleController::class, 'grid'])
            ->middleware('permission:academic.view')->name('schedules.grid');
        Route::match(['put', 'patch'], 'schedules/{schedule}', [ScheduleController::class, 'update'])
            ->middleware('permission:academic.update')->name('schedules.update');
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy'])
            ->middleware('permission:academic.delete')->name('schedules.destroy');

        // Perubahan jadwal: guru pengganti, pindah jam, pembatalan
        Route::get('schedule-changes', [ScheduleController::class, 'indexChanges'])
            ->middleware('permission:academic.view')->name('schedule-changes.index');
        Route::post('schedules/{schedule}/changes', [ScheduleController::class, 'storeChange'])
            ->middleware('permission:academic.update')->name('schedules.changes.store');
        Route::delete('schedule-changes/{scheduleChange}', [ScheduleController::class, 'destroyChange'])
            ->middleware('permission:academic.update')->name('schedule-changes.destroy');

        // -------------------------------------------------------------
        // Kesiswaan — Data Siswa
        // -------------------------------------------------------------
        Route::get('students', [StudentController::class, 'index'])
            ->middleware('permission:student.view')->name('students.index');
        Route::post('students', [StudentController::class, 'store'])
            ->middleware('permission:student.create')->name('students.store');
        Route::get('students/{student}', [StudentController::class, 'show'])
            ->middleware('permission:student.view')->name('students.show');
        Route::match(['put', 'patch'], 'students/{student}', [StudentController::class, 'update'])
            ->middleware('permission:student.update')->name('students.update');
        Route::delete('students/{student}', [StudentController::class, 'destroy'])
            ->middleware('permission:student.delete')->name('students.destroy');
        Route::patch('students/{student}/toggle-active', [StudentController::class, 'toggleActive'])
            ->middleware('permission:student.update')->name('students.toggle-active');
        Route::post('students/{student}/account', [StudentController::class, 'createAccount'])
            ->middleware('permission:student.update')->name('students.account.store');

        // Wali murid per siswa (satu wali utama per siswa)
        Route::post('students/{student}/guardians', [StudentController::class, 'attachGuardian'])
            ->middleware('permission:student.update')->name('students.guardians.store');
        Route::match(['put', 'patch'], 'students/{student}/guardians/{guardian}', [StudentController::class, 'updateGuardian'])
            ->middleware('permission:student.update')->name('students.guardians.update');
        Route::delete('students/{student}/guardians/{guardian}', [StudentController::class, 'detachGuardian'])
            ->middleware('permission:student.update')->name('students.guardians.destroy');

        // Riwayat kelas per tahun ajaran
        Route::get('students/{student}/histories', [StudentController::class, 'histories'])
            ->middleware('permission:student.view')->name('students.histories.index');
        Route::post('students/{student}/histories', [StudentController::class, 'storeHistory'])
            ->middleware('permission:student.update')->name('students.histories.store');
        Route::delete('students/{student}/histories/{history}', [StudentController::class, 'destroyHistory'])
            ->middleware('permission:student.update')->name('students.histories.destroy');

        // -------------------------------------------------------------
        // Kesiswaan — Data Wali Murid
        // -------------------------------------------------------------
        Route::get('guardians', [GuardianController::class, 'index'])
            ->middleware('permission:student.view')->name('guardians.index');
        Route::post('guardians', [GuardianController::class, 'store'])
            ->middleware('permission:student.create')->name('guardians.store');
        Route::get('guardians/{guardian}', [GuardianController::class, 'show'])
            ->middleware('permission:student.view')->name('guardians.show');
        Route::match(['put', 'patch'], 'guardians/{guardian}', [GuardianController::class, 'update'])
            ->middleware('permission:student.update')->name('guardians.update');
        Route::delete('guardians/{guardian}', [GuardianController::class, 'destroy'])
            ->middleware('permission:student.delete')->name('guardians.destroy');

        // -------------------------------------------------------------
        // Kepegawaian — Data Pegawai
        // -------------------------------------------------------------
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employee.view')->name('employees.index');
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('permission:employee.create')->name('employees.store');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])
            ->middleware('permission:employee.view')->name('employees.show');
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:employee.update')->name('employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission:employee.delete')->name('employees.destroy');
        Route::patch('employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive'])
            ->middleware('permission:employee.update')->name('employees.toggle-active');
        Route::post('employees/{employee}/account', [EmployeeController::class, 'createAccount'])
            ->middleware('permission:employee.update')->name('employees.account.store');

        // -------------------------------------------------------------
        // Master data (config-driven CRUD) — dilindungi permission master-data
        // -------------------------------------------------------------
        Route::get('master/{resource}', [\App\Http\Controllers\Api\MasterDataController::class, 'index'])
            ->middleware('permission:master-data.view')->name('master.index');
        Route::post('master/{resource}', [\App\Http\Controllers\Api\MasterDataController::class, 'store'])
            ->middleware('permission:master-data.create')->name('master.store');
        Route::get('master/{resource}/{id}', [\App\Http\Controllers\Api\MasterDataController::class, 'show'])
            ->middleware('permission:master-data.view')->name('master.show');
        Route::match(['put', 'patch'], 'master/{resource}/{id}', [\App\Http\Controllers\Api\MasterDataController::class, 'update'])
            ->middleware('permission:master-data.update')->name('master.update');
        Route::delete('master/{resource}/{id}', [\App\Http\Controllers\Api\MasterDataController::class, 'destroy'])
            ->middleware('permission:master-data.delete')->name('master.destroy');

        // -------------------------------------------------------------
        // Route modul per-domain (lihat routes/api/)
        // -------------------------------------------------------------
        // Setiap domain akademik mendaftarkan route-nya di file sendiri-sendiri
        // agar pengembangan paralel tidak saling menimpa file yang sama.
        // File dimuat di dalam grup auth:sanctum ini sehingga otomatis
        // terautentikasi dan mengikuti prefix `v1`.
        foreach (glob(__DIR__.'/api/*.php') ?: [] as $domainRoutes) {
            require $domainRoutes;
        }
    });
});
