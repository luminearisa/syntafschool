<?php

use App\Modules\Supervisi\Http\Controllers\SupAssignmentController;
use App\Modules\Supervisi\Http\Controllers\SupGradeScaleController;
use App\Modules\Supervisi\Http\Controllers\SupInstrumentController;
use App\Modules\Supervisi\Http\Controllers\SupProgramController;
use App\Modules\Supervisi\Http\Controllers\SupScheduleController;
use App\Modules\Supervisi\Http\Controllers\SupSettingController;
use App\Modules\Supervisi\Http\Controllers\SupSupervisorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route Modul Supervisi
|--------------------------------------------------------------------------
|
| Seluruh endpoint berada di bawah `/api/v1/supervisi/*` dan memakai
| middleware `permission:supervisi.*` (Spatie) — sama seperti modul lain di
| aplikasi ini.
|
| Tata kelola (siapa menilai siapa, versi instrumen yang sudah terbit tidak
| dapat diubah) ditegakkan di service, bukan di route.
|
*/
Route::middleware('auth:sanctum')->prefix('supervisi')->group(function (): void {

    // ---------------- Metadata & pengaturan modul ----------------
    Route::get('meta/instruments', [SupInstrumentController::class, 'metadata'])
        ->middleware('permission:supervisi.view')->name('supervisi.meta.instruments');

    Route::get('supervisors/kinds', [SupSupervisorController::class, 'kinds'])
        ->middleware('permission:supervisi.view')->name('supervisi.supervisors.kinds');
    Route::get('supervisors/candidates', [SupSupervisorController::class, 'candidates'])
        ->middleware('permission:supervisi.supervisor.view')->name('supervisi.supervisors.candidates');
    Route::get('supervisors/coverage', [SupSupervisorController::class, 'coverage'])
        ->middleware('permission:supervisi.supervisor.view')->name('supervisi.supervisors.coverage');
    Route::get('supervisors/eligible', [SupSupervisorController::class, 'eligible'])
        ->middleware('permission:supervisi.supervisor.view')->name('supervisi.supervisors.eligible');

    Route::get('settings', [SupSettingController::class, 'index'])
        ->middleware('permission:supervisi.view')->name('supervisi.settings.index');
    Route::put('settings', [SupSettingController::class, 'update'])
        ->middleware('permission:supervisi.settings.manage')->name('supervisi.settings.update');

    // ---------------- Pengaturan supervisor (registry) ----------------
    Route::get('supervisors', [SupSupervisorController::class, 'index'])
        ->middleware('permission:supervisi.supervisor.view')->name('supervisi.supervisors.index');
    Route::post('supervisors', [SupSupervisorController::class, 'store'])
        ->middleware('permission:supervisi.supervisor.manage')->name('supervisi.supervisors.store');
    Route::get('supervisors/{supervisor}', [SupSupervisorController::class, 'show'])
        ->middleware('permission:supervisi.supervisor.view')->name('supervisi.supervisors.show');
    Route::match(['put', 'patch'], 'supervisors/{supervisor}', [SupSupervisorController::class, 'update'])
        ->middleware('permission:supervisi.supervisor.manage')->name('supervisi.supervisors.update');
    Route::delete('supervisors/{supervisor}', [SupSupervisorController::class, 'destroy'])
        ->middleware('permission:supervisi.supervisor.manage')->name('supervisi.supervisors.destroy');

    // ---------------- Skala nilai ----------------
    Route::get('grade-scales', [SupGradeScaleController::class, 'index'])
        ->middleware('permission:supervisi.instrument.view')->name('supervisi.grade_scales.index');
    Route::post('grade-scales', [SupGradeScaleController::class, 'store'])
        ->middleware('permission:supervisi.settings.manage')->name('supervisi.grade_scales.store');
    Route::match(['put', 'patch'], 'grade-scales/{scale}', [SupGradeScaleController::class, 'update'])
        ->middleware('permission:supervisi.settings.manage')->name('supervisi.grade_scales.update');
    Route::post('grade-scales/{scale}/default', [SupGradeScaleController::class, 'setDefault'])
        ->middleware('permission:supervisi.settings.manage')->name('supervisi.grade_scales.default');

    // ---------------- Instrumen & versi ----------------
    Route::get('instruments', [SupInstrumentController::class, 'index'])
        ->middleware('permission:supervisi.instrument.view')->name('supervisi.instruments.index');
    Route::post('instruments', [SupInstrumentController::class, 'store'])
        ->middleware('permission:supervisi.instrument.create')->name('supervisi.instruments.store');
    Route::post('instruments/import/{instrument}', [SupInstrumentController::class, 'importVersion'])
        ->middleware('permission:supervisi.instrument.import')->name('supervisi.instruments.import');
    Route::get('instruments/{instrument}', [SupInstrumentController::class, 'show'])
        ->middleware('permission:supervisi.instrument.view')->name('supervisi.instruments.show');
    Route::match(['put', 'patch'], 'instruments/{instrument}', [SupInstrumentController::class, 'update'])
        ->middleware('permission:supervisi.instrument.update')->name('supervisi.instruments.update');
    Route::delete('instruments/{instrument}', [SupInstrumentController::class, 'destroy'])
        ->middleware('permission:supervisi.instrument.delete')->name('supervisi.instruments.destroy');
    Route::post('instruments/{instrument}/versions', [SupInstrumentController::class, 'storeVersion'])
        ->middleware('permission:supervisi.instrument.update')->name('supervisi.versions.store');

    Route::get('instrument-versions/{version}', [SupInstrumentController::class, 'showVersion'])
        ->middleware('permission:supervisi.instrument.view')->name('supervisi.versions.show');
    Route::put('instrument-versions/{version}/structure', [SupInstrumentController::class, 'syncStructure'])
        ->middleware('permission:supervisi.instrument.update')->name('supervisi.versions.structure');
    Route::get('instrument-versions/{version}/checklist', [SupInstrumentController::class, 'checklist'])
        ->middleware('permission:supervisi.instrument.view')->name('supervisi.versions.checklist');
    Route::post('instrument-versions/{version}/publish', [SupInstrumentController::class, 'publish'])
        ->middleware('permission:supervisi.instrument.publish')->name('supervisi.versions.publish');
    Route::post('instrument-versions/{version}/clone', [SupInstrumentController::class, 'cloneVersion'])
        ->middleware('permission:supervisi.instrument.create')->name('supervisi.versions.clone');
    Route::get('instrument-versions/{version}/export', [SupInstrumentController::class, 'exportVersion'])
        ->middleware('permission:supervisi.instrument.view')->name('supervisi.versions.export');

    // ---------------- Program supervisi ----------------
    Route::get('programs', [SupProgramController::class, 'index'])
        ->middleware('permission:supervisi.program.view')->name('supervisi.programs.index');
    Route::post('programs', [SupProgramController::class, 'store'])
        ->middleware('permission:supervisi.program.create')->name('supervisi.programs.store');
    Route::get('programs/{program}', [SupProgramController::class, 'show'])
        ->middleware('permission:supervisi.program.view')->name('supervisi.programs.show');
    Route::match(['put', 'patch'], 'programs/{program}', [SupProgramController::class, 'update'])
        ->middleware('permission:supervisi.program.update')->name('supervisi.programs.update');
    Route::post('programs/{program}/publish', [SupProgramController::class, 'publish'])
        ->middleware('permission:supervisi.program.publish')->name('supervisi.programs.publish');
    Route::post('programs/{program}/close', [SupProgramController::class, 'close'])
        ->middleware('permission:supervisi.program.publish')->name('supervisi.programs.close');

    // ---------------- Penugasan ----------------
    Route::get('assignments', [SupAssignmentController::class, 'index'])
        ->middleware('permission:supervisi.assignment.view')->name('supervisi.assignments.index');
    Route::get('assignments/workload', [SupAssignmentController::class, 'workload'])
        ->middleware('permission:supervisi.assignment.view')->name('supervisi.assignments.workload');
    Route::post('programs/{program}/assignments', [SupAssignmentController::class, 'store'])
        ->middleware('permission:supervisi.assignment.manage')->name('supervisi.assignments.store');
    Route::match(['put', 'patch'], 'assignments/{assignment}', [SupAssignmentController::class, 'update'])
        ->middleware('permission:supervisi.assignment.manage')->name('supervisi.assignments.update');
    Route::delete('assignments/{assignment}', [SupAssignmentController::class, 'destroy'])
        ->middleware('permission:supervisi.assignment.manage')->name('supervisi.assignments.destroy');

    // ---------------- Jadwal ----------------
    Route::get('schedules', [SupScheduleController::class, 'index'])
        ->middleware('permission:supervisi.schedule.view')->name('supervisi.schedules.index');
    Route::post('schedules', [SupScheduleController::class, 'store'])
        ->middleware('permission:supervisi.schedule.manage')->name('supervisi.schedules.store');
    Route::match(['put', 'patch'], 'schedules/{schedule}', [SupScheduleController::class, 'update'])
        ->middleware('permission:supervisi.schedule.manage')->name('supervisi.schedules.update');
    Route::post('schedules/{schedule}/reschedule', [SupScheduleController::class, 'reschedule'])
        ->middleware('permission:supervisi.schedule.manage')->name('supervisi.schedules.reschedule');
    Route::post('schedules/{schedule}/cancel', [SupScheduleController::class, 'cancel'])
        ->middleware('permission:supervisi.schedule.manage')->name('supervisi.schedules.cancel');
    Route::post('schedules/{schedule}/invite', [SupScheduleController::class, 'invite'])
        ->middleware('permission:supervisi.schedule.manage')->name('supervisi.schedules.invite');
});
