<?php

use App\Modules\Bk\Http\Controllers\BkAppointmentController;
use App\Modules\Bk\Http\Controllers\BkCaseController;
use App\Modules\Bk\Http\Controllers\BkCaseRecordController;
use App\Modules\Bk\Http\Controllers\BkFollowUpController;
use App\Modules\Bk\Http\Controllers\BkMetaController;
use App\Modules\Bk\Http\Controllers\BkPortalController;
use App\Modules\Bk\Http\Controllers\BkReferralController;
use App\Modules\Bk\Http\Controllers\BkReportController;
use Illuminate\Support\Facades\Route;

/*
| Route Modul Bimbingan & Konseling — seluruhnya di bawah `/api/v1/bk/*`.
|
| Middleware `permission:bk.*` menentukan **layar/aksi** yang boleh dibuka;
| cakupan **baris** (kasus siapa, catatan siapa) ditegakkan terpisah di
| `BkAccessService` + Policy, sehingga pemegang `bk.case.view` yang bukan
| konselor tetap hanya melihat kasus dalam lingkupnya.
|
| Tidak ada endpoint yang mengembalikan isi `counselor_note` kepada guru, siswa,
| atau orang tua — bukan disaring di tampilan, melainkan tidak pernah dikirim.
*/

Route::middleware(['auth:sanctum', 'throttle:bk-module'])->prefix('bk')->group(function (): void {

    // ---------------- Konteks, metadata, dasbor ----------------
    Route::get('me', [BkMetaController::class, 'me'])
        ->middleware('permission:bk.view')->name('bk.me');
    Route::get('metadata', [BkMetaController::class, 'metadata'])
        ->middleware('permission:bk.view')->name('bk.metadata');
    Route::get('dashboard', [BkMetaController::class, 'dashboard'])
        ->middleware('permission:bk.view')->name('bk.dashboard');
    Route::get('dashboard/today', [BkMetaController::class, 'todaysWorkload'])
        ->middleware('permission:bk.view')->name('bk.dashboard.today');
    Route::get('dashboard/aging-cases', [BkMetaController::class, 'agingCases'])
        ->middleware('permission:bk.case.view')->name('bk.dashboard.aging');
    Route::get('health', [BkMetaController::class, 'health'])
        ->middleware('permission:bk.manage')->name('bk.health');
    Route::get('audit', [BkMetaController::class, 'audit'])
        ->middleware('permission:bk.audit.view')->name('bk.audit');

    // ---------------- Profil dukungan siswa ----------------
    Route::get('students/{studentId}/support-profile', [BkMetaController::class, 'supportProfile'])
        ->middleware('permission:bk.support-profile.view')
        ->name('bk.students.support-profile');

    // ---------------- Referral ----------------
    Route::get('referrals', [BkReferralController::class, 'index'])
        ->middleware('throttle:bk-search')->middleware('permission:bk.referral.view')
        ->name('bk.referrals.index');
    Route::post('referrals', [BkReferralController::class, 'store'])
        ->middleware('throttle:bk-write')->middleware('permission:bk.referral.create')
        ->name('bk.referrals.store');
    Route::get('referrals/{referral}', [BkReferralController::class, 'show'])
        ->middleware('permission:bk.referral.view')->name('bk.referrals.show');
    Route::post('referrals/{referral}/receive', [BkReferralController::class, 'receive'])
        ->middleware('permission:bk.referral.receive')->name('bk.referrals.receive');
    Route::post('referrals/{referral}/screen', [BkReferralController::class, 'screen'])
        ->middleware('permission:bk.screening.create')->name('bk.referrals.screen');
    Route::post('referrals/{referral}/open-case', [BkReferralController::class, 'openCase'])
        ->middleware('permission:bk.case.create')->name('bk.referrals.open-case');
    Route::post('referrals/{referral}/reject', [BkReferralController::class, 'reject'])
        ->middleware('permission:bk.referral.receive')->name('bk.referrals.reject');
    Route::post('referrals/{referral}/cancel', [BkReferralController::class, 'cancel'])
        ->middleware('permission:bk.referral.view')->name('bk.referrals.cancel');

    // ---------------- Kasus ----------------
    Route::get('cases', [BkCaseController::class, 'index'])
        ->middleware('throttle:bk-search')->middleware('permission:bk.case.view')
        ->name('bk.cases.index');
    Route::post('cases', [BkCaseController::class, 'store'])
        ->middleware('throttle:bk-write')->middleware('permission:bk.case.create')
        ->name('bk.cases.store');
    Route::get('cases/workload', [BkCaseController::class, 'workload'])
        ->middleware('permission:bk.workload.view')->name('bk.cases.workload');
    Route::get('cases/{case}', [BkCaseController::class, 'show'])
        ->middleware('permission:bk.case.view')->name('bk.cases.show');
    Route::patch('cases/{case}', [BkCaseController::class, 'update'])
        ->middleware('permission:bk.case.update')->name('bk.cases.update');
    Route::post('cases/{case}/transition', [BkCaseController::class, 'transition'])
        ->middleware('permission:bk.case.update')->name('bk.cases.transition');
    Route::post('cases/{case}/assign', [BkCaseController::class, 'assign'])
        ->middleware('permission:bk.case.assign')->name('bk.cases.assign');
    Route::post('cases/{case}/assignments/{userId}/end', [BkCaseController::class, 'endAssignment'])
        ->middleware('permission:bk.case.assign')->name('bk.cases.assign.end');
    Route::post('cases/{case}/decline', [BkCaseController::class, 'decline'])
        ->middleware('permission:bk.case.view')->name('bk.cases.decline');
    Route::post('cases/{case}/review', [BkCaseController::class, 'review'])
        ->middleware('permission:bk.case.update')->name('bk.cases.review');
    Route::post('cases/{case}/escalate', [BkCaseController::class, 'escalate'])
        ->middleware('permission:bk.case.escalate')->name('bk.cases.escalate');
    Route::post('cases/{case}/close', [BkCaseController::class, 'close'])
        ->middleware('permission:bk.case.close')->name('bk.cases.close');
    Route::post('cases/{case}/reopen', [BkCaseController::class, 'reopen'])
        ->middleware('permission:bk.case.reopen')->name('bk.cases.reopen');
    Route::get('cases/{case}/timeline', [BkCaseController::class, 'timeline'])
        ->middleware('permission:bk.case.view')->name('bk.cases.timeline');
    Route::post('cases/{case}/participants', [BkCaseController::class, 'addParticipant'])
        ->middleware('permission:bk.case.share')->name('bk.cases.participants.store');
    Route::delete('cases/{case}/participants/{participantId}', [BkCaseController::class, 'removeParticipant'])
        ->middleware('permission:bk.case.share')->name('bk.cases.participants.destroy');
    Route::post('cases/{case}/tags', [BkCaseController::class, 'syncTags'])
        ->middleware('permission:bk.case.update')->name('bk.cases.tags');

    // ---------------- Rekaman di dalam kasus ----------------
    Route::post('cases/{case}/screenings', [BkCaseRecordController::class, 'screen'])
        ->middleware('permission:bk.screening.create')->name('bk.cases.screenings.store');
    Route::get('cases/{case}/assessments', [BkCaseRecordController::class, 'assessments'])
        ->middleware('permission:bk.assessment.view')->name('bk.cases.assessments.index');
    Route::post('cases/{case}/assessments', [BkCaseRecordController::class, 'storeAssessment'])
        ->middleware('permission:bk.assessment.assign')->name('bk.cases.assessments.store');
    Route::get('cases/{case}/sessions', [BkCaseRecordController::class, 'sessions'])
        ->middleware('permission:bk.session.view')->name('bk.cases.sessions.index');
    Route::post('cases/{case}/sessions', [BkCaseRecordController::class, 'storeSession'])
        ->middleware('permission:bk.session.manage')->name('bk.cases.sessions.store');
    Route::get('cases/{case}/plans', [BkCaseRecordController::class, 'plans'])
        ->middleware('permission:bk.plan.view')->name('bk.cases.plans.index');
    Route::post('cases/{case}/plans', [BkCaseRecordController::class, 'storePlan'])
        ->middleware('permission:bk.plan.manage')->name('bk.cases.plans.store');
    Route::get('cases/{case}/interventions', [BkCaseRecordController::class, 'interventions'])
        ->middleware('permission:bk.intervention.view')->name('bk.cases.interventions.index');
    Route::post('cases/{case}/interventions', [BkCaseRecordController::class, 'storeIntervention'])
        ->middleware('permission:bk.intervention.manage')->name('bk.cases.interventions.store');
    Route::get('cases/{case}/follow-ups', [BkCaseRecordController::class, 'followUps'])
        ->middleware('permission:bk.follow-up.view')->name('bk.cases.follow-ups.index');
    Route::post('cases/{case}/follow-ups', [BkCaseRecordController::class, 'storeFollowUp'])
        ->middleware('permission:bk.follow-up.manage')->name('bk.cases.follow-ups.store');
    Route::get('cases/{case}/documents', [BkCaseRecordController::class, 'documents'])
        ->middleware('permission:bk.document.view')->name('bk.cases.documents.index');
    Route::post('cases/{case}/documents', [BkCaseRecordController::class, 'storeDocument'])
        ->middleware('throttle:bk-download')->middleware('permission:bk.document.upload')
        ->name('bk.cases.documents.store');
    Route::get('cases/{case}/notes', [BkCaseRecordController::class, 'notes'])
        ->middleware('permission:bk.note.view')->name('bk.cases.notes.index');
    Route::post('cases/{case}/notes', [BkCaseRecordController::class, 'storeNote'])
        ->middleware('permission:bk.note.create')->name('bk.cases.notes.store');
    Route::get('cases/{case}/meetings', [BkCaseRecordController::class, 'meetings'])
        ->middleware('permission:bk.meeting.view')->name('bk.cases.meetings.index');
    Route::post('cases/{case}/meetings', [BkCaseRecordController::class, 'storeMeeting'])
        ->middleware('permission:bk.meeting.manage')->name('bk.cases.meetings.store');

    // ---------------- Asesmen, sasaran, intervensi, dokumen, catatan ----------------
    Route::get('assessments/{assessment}', [BkCaseRecordController::class, 'assessments'])
        ->middleware('permission:bk.assessment.view')->name('bk.assessments.show');
    Route::post('assessments/{assessment}/responses', [BkCaseRecordController::class, 'storeAssessment'])
        ->middleware('permission:bk.assessment.submit')->name('bk.assessments.responses');
    Route::patch('goals/{goal}', [BkCaseRecordController::class, 'updateGoal'])
        ->middleware('permission:bk.plan.manage')->name('bk.goals.update');
    Route::post('plans/{plan}/review', [BkCaseRecordController::class, 'reviewPlan'])
        ->middleware('permission:bk.plan.manage')->name('bk.plans.review');
    Route::patch('interventions/{intervention}', [BkCaseRecordController::class, 'updateIntervention'])
        ->middleware('permission:bk.intervention.manage')->name('bk.interventions.update');
    Route::post('meetings/{meeting}/minutes', [BkCaseRecordController::class, 'recordMinutes'])
        ->middleware('permission:bk.meeting.manage')->name('bk.meetings.minutes');
    Route::post('meetings/{meeting}/actions/{actionId}/follow-up', [BkCaseRecordController::class, 'convertMeetingAction'])
        ->middleware('permission:bk.meeting.manage')->name('bk.meetings.actions.follow-up');
    Route::get('documents/{document}/download', [BkCaseRecordController::class, 'downloadDocument'])
        ->middleware('throttle:bk-download')->middleware('permission:bk.document.view')
        ->name('bk.documents.download');
    Route::post('notes/{note}/amend', [BkCaseRecordController::class, 'amendNote'])
        ->middleware('permission:bk.note.create')->name('bk.notes.amend');

    // ---------------- Janji temu ----------------
    Route::get('appointments', [BkAppointmentController::class, 'index'])
        ->middleware('permission:bk.appointment.view')->name('bk.appointments.index');
    Route::post('appointments', [BkAppointmentController::class, 'store'])
        ->middleware('throttle:bk-write')->middleware('permission:bk.appointment.manage')
        ->name('bk.appointments.store');
    Route::post('appointments/check-conflicts', [BkAppointmentController::class, 'checkConflicts'])
        ->middleware('throttle:bk-search')->middleware('permission:bk.appointment.view')
        ->name('bk.appointments.check-conflicts');
    Route::patch('appointments/{appointment}/reschedule', [BkAppointmentController::class, 'reschedule'])
        ->middleware('permission:bk.appointment.manage')->name('bk.appointments.reschedule');
    Route::post('appointments/{appointment}/cancel', [BkAppointmentController::class, 'cancel'])
        ->middleware('permission:bk.appointment.manage')->name('bk.appointments.cancel');
    Route::post('appointments/{appointment}/attendance', [BkAppointmentController::class, 'attendance'])
        ->middleware('permission:bk.appointment.manage')->name('bk.appointments.attendance');

    // ---------------- Tindak lanjut ----------------
    Route::get('follow-ups', [BkFollowUpController::class, 'index'])
        ->middleware('permission:bk.follow-up.view')->name('bk.follow-ups.index');
    Route::post('follow-ups/{followUp}/complete', [BkFollowUpController::class, 'complete'])
        ->middleware('permission:bk.follow-up.manage')->name('bk.follow-ups.complete');
    Route::post('follow-ups/{followUp}/reschedule', [BkFollowUpController::class, 'reschedule'])
        ->middleware('permission:bk.follow-up.manage')->name('bk.follow-ups.reschedule');
    Route::post('follow-ups/{followUp}/miss', [BkFollowUpController::class, 'miss'])
        ->middleware('permission:bk.follow-up.manage')->name('bk.follow-ups.miss');

    // ---------------- Laporan ----------------
    Route::get('reports', [BkReportController::class, 'index'])
        ->middleware('permission:bk.report.view')->name('bk.reports.index');
    Route::get('reports/{type}', [BkReportController::class, 'show'])
        ->middleware('throttle:bk-report')->middleware('permission:bk.report.view')
        ->name('bk.reports.show');

    // ---------------- Portal siswa & orang tua ----------------
    Route::prefix('portal')->group(function (): void {
        Route::get('summary', [BkPortalController::class, 'summary'])
            ->middleware('permission:bk.portal.view')->name('bk.portal.summary');
        Route::get('requests', [BkPortalController::class, 'requests'])
            ->middleware('permission:bk.portal.view')->name('bk.portal.requests');
        Route::post('requests/student', [BkPortalController::class, 'requestByStudent'])
            ->middleware('permission:bk.support-request.create')->name('bk.portal.requests.student');
        Route::post('requests/guardian', [BkPortalController::class, 'requestByGuardian'])
            ->middleware('permission:bk.support-request.create')->name('bk.portal.requests.guardian');
        Route::post('requests/{supportRequest}/cancel', [BkPortalController::class, 'cancelRequest'])
            ->middleware('permission:bk.portal.view')->name('bk.portal.requests.cancel');
        Route::get('appointments', [BkPortalController::class, 'appointments'])
            ->middleware('permission:bk.portal.view')->name('bk.portal.appointments');
        Route::get('consents', [BkPortalController::class, 'consents'])
            ->middleware('permission:bk.portal.view')->name('bk.portal.consents');
        Route::post('consents/{consent}/decide', [BkPortalController::class, 'decideConsent'])
            ->middleware('permission:bk.consent.decide')->name('bk.portal.consents.decide');
    });
});
