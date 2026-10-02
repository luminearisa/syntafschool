<?php

use App\Modules\Hrd\Http\Controllers\HrdPhase2Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HRD Phase 2 — Workforce, Payroll, Talent, Development
|--------------------------------------------------------------------------
| Seluruh endpoint membaca lewat layanan domain; penulisan tetap berada pada
| layanan transaksi (§78) sehingga aturan bisnis tidak dapat dilewati API.
| Izin per kelompok memakai izin HRD yang di-seed oleh HrdPhase2PermissionSeeder.
*/

Route::middleware(['api', 'auth:sanctum', 'throttle:hrd-phase2'])
    ->prefix('api/v1/hrd/phase2')
    ->group(function () {
        Route::get('meta', [HrdPhase2Controller::class, 'meta']);

        Route::middleware('permission:hr.analytics.view')->group(function () {
            Route::get('dashboard', [HrdPhase2Controller::class, 'dashboard']);
            Route::get('analytics/{scope}', [HrdPhase2Controller::class, 'analytics']);
        });

        Route::middleware('permission:hr.alert.view')->group(function () {
            Route::get('alerts', [HrdPhase2Controller::class, 'alerts']);
            Route::post('alerts/{alert}/acknowledge', [HrdPhase2Controller::class, 'acknowledgeAlert']);
        });

        Route::middleware('permission:hr.service.view')->group(function () {
            Route::get('service-center/queue', [HrdPhase2Controller::class, 'serviceQueue']);
        });

        Route::middleware('permission:hr.recruitment.view')->group(function () {
            Route::get('recruitment/analytics', [HrdPhase2Controller::class, 'recruitmentAnalytics']);
        });

        Route::middleware('permission:hr.attendance.view')->group(function () {
            Route::get('attendance/summary/{employee}', [HrdPhase2Controller::class, 'attendanceSummary']);
            Route::get('attendance/reconcile/{date}', [HrdPhase2Controller::class, 'attendanceReconcile']);
        });

        Route::middleware('permission:hr.leave.view')->group(function () {
            Route::get('leave/balances/{employee}', [HrdPhase2Controller::class, 'leaveBalances']);
            Route::get('leave/calendar', [HrdPhase2Controller::class, 'leaveCalendar']);
        });

        Route::middleware('permission:hr.payroll.view')->group(function () {
            Route::get('payroll/runs', [HrdPhase2Controller::class, 'payrollRuns']);
            Route::get('payroll/runs/{run}/reconciliation', [HrdPhase2Controller::class, 'payrollReconciliation']);
            Route::get('payroll/payslips/{employee}', [HrdPhase2Controller::class, 'payslip']);
        });

        Route::middleware('permission:hr.benefits.view')->group(function () {
            Route::get('benefits/summary', [HrdPhase2Controller::class, 'benefitsSummary']);
            Route::get('benefits/employees/{employee}', [HrdPhase2Controller::class, 'benefitEligibility']);
        });

        Route::middleware('permission:hr.performance.view')->group(function () {
            Route::get('performance/summary', [HrdPhase2Controller::class, 'performanceSummary']);
            Route::get('performance/nine-box', [HrdPhase2Controller::class, 'nineBox']);
        });

        Route::middleware('permission:hr.learning.view')->group(function () {
            Route::get('learning/matrix', [HrdPhase2Controller::class, 'trainingMatrix']);
            Route::get('learning/analytics', [HrdPhase2Controller::class, 'learningAnalytics']);
        });

        Route::middleware('permission:hr.career.view')->group(function () {
            Route::get('career/paths/{position}', [HrdPhase2Controller::class, 'careerPath']);
            Route::get('career/readiness/{employee}/{position}', [HrdPhase2Controller::class, 'careerReadiness']);
        });

        Route::middleware('permission:hr.talent.view')->group(function () {
            Route::get('talent/dashboard', [HrdPhase2Controller::class, 'talentDashboard']);
        });

        Route::middleware('permission:hr.skill.view')->group(function () {
            Route::get('competency/skills', [HrdPhase2Controller::class, 'competencies']);
        });

        // Layanan mandiri pegawai: hanya data miliknya sendiri.
        Route::get('self', [HrdPhase2Controller::class, 'selfService']);

        // Saran AI wajib ditinjau manusia; izin khusus dan tidak pernah final.
        Route::middleware('permission:hr.ai.use')->group(function () {
            Route::post('ai/suggest', [HrdPhase2Controller::class, 'aiSuggest']);
        });
    });
