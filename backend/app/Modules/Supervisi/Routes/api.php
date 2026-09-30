<?php

use App\Modules\Supervisi\Http\Controllers\SupActionPlanController;
use App\Modules\Supervisi\Http\Controllers\SupAppraisalController;
use App\Modules\Supervisi\Http\Controllers\SupAssignmentController;
use App\Modules\Supervisi\Http\Controllers\SupGradeScaleController;
use App\Modules\Supervisi\Http\Controllers\SupGrowthController;
use App\Modules\Supervisi\Http\Controllers\SupInstrumentController;
use App\Modules\Supervisi\Http\Controllers\SupProgramController;
use App\Modules\Supervisi\Http\Controllers\SupScheduleController;
use App\Modules\Supervisi\Http\Controllers\SupSettingController;
use App\Modules\Supervisi\Http\Controllers\SupSupervisorController;
use App\Modules\Supervisi\Http\Controllers\SupVisitAcknowledgmentController;
use App\Modules\Supervisi\Http\Controllers\SupVisitConferenceController;
use App\Modules\Supervisi\Http\Controllers\SupVisitController;
use App\Modules\Supervisi\Http\Controllers\SupVisitEvidenceController;
use App\Modules\Supervisi\Http\Controllers\SupVisitFindingController;
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

    // ---------------- Kegiatan supervisi (pelaksanaan) ----------------
    Route::get('visits', [SupVisitController::class, 'index'])
        ->middleware('permission:supervisi.visit.view')->name('supervisi.visits.index');
    Route::post('visits', [SupVisitController::class, 'store'])
        ->middleware('permission:supervisi.visit.create')->name('supervisi.visits.store');
    Route::get('visits/{visit}', [SupVisitController::class, 'show'])
        ->middleware('permission:supervisi.visit.view')->name('supervisi.visits.show');
    Route::match(['put', 'patch'], 'visits/{visit}', [SupVisitController::class, 'update'])
        ->middleware('permission:supervisi.visit.execute')->name('supervisi.visits.update');
    Route::delete('visits/{visit}', [SupVisitController::class, 'destroy'])
        ->middleware('permission:supervisi.visit.create')->name('supervisi.visits.destroy');

    Route::put('visits/{visit}/answers', [SupVisitController::class, 'answers'])
        ->middleware('permission:supervisi.visit.execute')->name('supervisi.visits.answers');
    Route::get('visits/{visit}/preview', [SupVisitController::class, 'preview'])
        ->middleware('permission:supervisi.visit.view')->name('supervisi.visits.preview');
    Route::post('visits/{visit}/submit', [SupVisitController::class, 'submit'])
        ->middleware('permission:supervisi.visit.execute')->name('supervisi.visits.submit');
    Route::post('visits/{visit}/finalize', [SupVisitController::class, 'finalize'])
        ->middleware('permission:supervisi.visit.finalize')->name('supervisi.visits.finalize');
    Route::post('visits/{visit}/reopen', [SupVisitController::class, 'reopen'])
        ->middleware('permission:supervisi.visit.reopen')->name('supervisi.visits.reopen');
    Route::post('visits/{visit}/cancel', [SupVisitController::class, 'cancel'])
        ->middleware('permission:supervisi.visit.cancel')->name('supervisi.visits.cancel');

    // Wawancara pra & pasca-observasi
    Route::get('visits/{visit}/conferences', [SupVisitConferenceController::class, 'index'])
        ->middleware('permission:supervisi.visit.view')->name('supervisi.visits.conferences.index');
    Route::put('visits/{visit}/conferences/{kind}', [SupVisitConferenceController::class, 'record'])
        ->middleware('permission:supervisi.conference.manage')->name('supervisi.visits.conferences.record');

    // Temuan analisis data
    Route::get('visits/{visit}/findings', [SupVisitFindingController::class, 'index'])
        ->middleware('permission:supervisi.visit.view')->name('supervisi.visits.findings.index');
    Route::put('visits/{visit}/findings', [SupVisitFindingController::class, 'replace'])
        ->middleware('permission:supervisi.visit.execute')->name('supervisi.visits.findings.replace');

    // Bukti kegiatan
    Route::post('visits/{visit}/evidence', [SupVisitEvidenceController::class, 'store'])
        ->middleware('permission:supervisi.evidence.manage')->name('supervisi.visits.evidence.store');
    Route::delete('visits/{visit}/evidence/{evidence}', [SupVisitEvidenceController::class, 'destroy'])
        ->middleware('permission:supervisi.evidence.manage')->name('supervisi.visits.evidence.destroy');

    // Tanda tangan hasil & keberatan
    Route::post('visits/{visit}/acknowledge', [SupVisitAcknowledgmentController::class, 'acknowledge'])
        ->middleware('permission:supervisi.visit.view')->name('supervisi.visits.acknowledge');
    Route::post('visits/{visit}/dispute', [SupVisitAcknowledgmentController::class, 'dispute'])
        ->middleware('permission:supervisi.dispute.create')->name('supervisi.visits.dispute');
    Route::post('disputes/{dispute}/resolve', [SupVisitAcknowledgmentController::class, 'resolve'])
        ->middleware('permission:supervisi.dispute.resolve')->name('supervisi.disputes.resolve');

    // -----------------------------------------------------------------
    // Fase 3 — tindak lanjut, penilaian kinerja, dan pengembangan
    // -----------------------------------------------------------------

    // Rencana Tindak Lanjut (RTL) & papan pemantauan
    Route::get('action-plans', [SupActionPlanController::class, 'index'])
        ->middleware('permission:supervisi.action_plan.view')->name('supervisi.action_plans.index');
    Route::post('action-plans', [SupActionPlanController::class, 'store'])
        ->middleware('permission:supervisi.action_plan.create')->name('supervisi.action_plans.store');
    Route::get('action-plans/{actionPlan}', [SupActionPlanController::class, 'show'])
        ->middleware('permission:supervisi.action_plan.view')->name('supervisi.action_plans.show');
    Route::patch('action-plans/{actionPlan}', [SupActionPlanController::class, 'update'])
        ->middleware('permission:supervisi.action_plan.update')->name('supervisi.action_plans.update');
    Route::post('action-plans/{actionPlan}/items', [SupActionPlanController::class, 'storeItem'])
        ->middleware('permission:supervisi.action_plan.update')->name('supervisi.action_plans.items.store');
    Route::patch('action-plans/items/{actionItem}', [SupActionPlanController::class, 'updateItem'])
        ->middleware('permission:supervisi.action_plan.update')->name('supervisi.action_plans.items.update');
    Route::post('action-plans/items/{actionItem}/progress', [SupActionPlanController::class, 'reportProgress'])
        ->middleware('permission:supervisi.action_plan.update')->name('supervisi.action_plans.items.progress');
    Route::post('action-plans/{actionPlan}/verify', [SupActionPlanController::class, 'verify'])
        ->middleware('permission:supervisi.action_plan.verify')->name('supervisi.action_plans.verify');
    Route::post('action-plans/{actionPlan}/drop', [SupActionPlanController::class, 'drop'])
        ->middleware('permission:supervisi.action_plan.verify')->name('supervisi.action_plans.drop');
    Route::post('visits/{visit}/action-plan', [SupActionPlanController::class, 'storeFromVisit'])
        ->middleware('permission:supervisi.action_plan.create')->name('supervisi.visits.action_plan.store');

    // Penilaian kinerja (PKG, PKG BK, NKKS, tinjauan pegawai, SKP)
    Route::get('appraisals', [SupAppraisalController::class, 'index'])
        ->middleware('permission:supervisi.appraisal.view|supervisi.report.view')->name('supervisi.appraisals.index');
    Route::get('appraisals/reference', [SupAppraisalController::class, 'reference'])
        ->middleware('permission:supervisi.appraisal.view')->name('supervisi.appraisals.reference');
    Route::post('appraisals', [SupAppraisalController::class, 'store'])
        ->middleware('permission:supervisi.appraisal.create')->name('supervisi.appraisals.store');
    Route::get('appraisals/{appraisal}', [SupAppraisalController::class, 'show'])
        ->middleware('permission:supervisi.appraisal.view|supervisi.report.view')->name('supervisi.appraisals.show');
    Route::patch('appraisals/{appraisal}', [SupAppraisalController::class, 'update'])
        ->middleware('permission:supervisi.appraisal.create')->name('supervisi.appraisals.update');
    Route::put('appraisals/{appraisal}/scores', [SupAppraisalController::class, 'saveScores'])
        ->middleware('permission:supervisi.appraisal.calculate')->name('supervisi.appraisals.scores');
    Route::post('appraisals/{appraisal}/calculate', [SupAppraisalController::class, 'calculate'])
        ->middleware('permission:supervisi.appraisal.calculate')->name('supervisi.appraisals.calculate');
    Route::put('appraisals/{appraisal}/sources', [SupAppraisalController::class, 'saveSources'])
        ->middleware('permission:supervisi.appraisal.calculate')->name('supervisi.appraisals.sources');
    Route::post('appraisals/{appraisal}/publish', [SupAppraisalController::class, 'publish'])
        ->middleware('permission:supervisi.appraisal.publish')->name('supervisi.appraisals.publish');
    Route::post('appraisals/{appraisal}/reopen', [SupAppraisalController::class, 'reopen'])
        ->middleware('permission:supervisi.appraisal.publish')->name('supervisi.appraisals.reopen');

    // Pengembangan kompetensi & coaching
    Route::get('developments', [SupGrowthController::class, 'developments'])
        ->middleware('permission:supervisi.development.manage|supervisi.report.view')->name('supervisi.developments.index');
    Route::post('developments', [SupGrowthController::class, 'storeDevelopment'])
        ->middleware('permission:supervisi.development.manage')->name('supervisi.developments.store');
    Route::patch('developments/{development}', [SupGrowthController::class, 'updateDevelopment'])
        ->middleware('permission:supervisi.development.manage')->name('supervisi.developments.update');
    Route::delete('developments/{development}', [SupGrowthController::class, 'destroyDevelopment'])
        ->middleware('permission:supervisi.development.manage')->name('supervisi.developments.destroy');

    Route::get('coachings', [SupGrowthController::class, 'coachings'])
        ->middleware('permission:supervisi.coaching.manage|supervisi.report.view')->name('supervisi.coachings.index');
    Route::post('coachings', [SupGrowthController::class, 'storeCoaching'])
        ->middleware('permission:supervisi.coaching.manage')->name('supervisi.coachings.store');
    Route::patch('coachings/{coaching}', [SupGrowthController::class, 'updateCoaching'])
        ->middleware('permission:supervisi.coaching.manage')->name('supervisi.coachings.update');
    Route::post('coachings/{coaching}/close', [SupGrowthController::class, 'closeCoaching'])
        ->middleware('permission:supervisi.coaching.manage')->name('supervisi.coachings.close');

    // Rencana Perbaikan Kinerja (PIP)
    Route::get('pips', [SupGrowthController::class, 'pips'])
        ->middleware('permission:supervisi.pip.manage|supervisi.report.view')->name('supervisi.pips.index');
    Route::post('pips', [SupGrowthController::class, 'storePip'])
        ->middleware('permission:supervisi.pip.manage')->name('supervisi.pips.store');
    Route::get('pips/{pipPlan}', [SupGrowthController::class, 'showPip'])
        ->middleware('permission:supervisi.pip.manage|supervisi.report.view')->name('supervisi.pips.show');
    Route::patch('pips/{pipPlan}', [SupGrowthController::class, 'updatePip'])
        ->middleware('permission:supervisi.pip.manage')->name('supervisi.pips.update');
    Route::post('pips/{pipPlan}/items', [SupGrowthController::class, 'storePipItem'])
        ->middleware('permission:supervisi.pip.manage')->name('supervisi.pips.items.store');
    Route::patch('pips/items/{pipItem}', [SupGrowthController::class, 'updatePipItem'])
        ->middleware('permission:supervisi.pip.manage')->name('supervisi.pips.items.update');
    Route::post('pips/{pipPlan}/reviews', [SupGrowthController::class, 'reviewPip'])
        ->middleware('permission:supervisi.pip.manage')->name('supervisi.pips.reviews.store');
    Route::post('pips/{pipPlan}/conclude', [SupGrowthController::class, 'concludePip'])
        ->middleware('permission:supervisi.pip.manage')->name('supervisi.pips.conclude');
    Route::post('pips/{pipPlan}/acknowledge', [SupGrowthController::class, 'acknowledgePip'])
        ->middleware('permission:supervisi.report.view')->name('supervisi.pips.acknowledge');
});
