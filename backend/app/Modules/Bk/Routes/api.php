<?php

use App\Modules\Bk\Http\Controllers\BkAppointmentController;
use App\Modules\Bk\Http\Controllers\BkCaseController;
use App\Modules\Bk\Http\Controllers\BkSecurityController;
use App\Modules\Bk\Http\Controllers\BkRiskIndicatorController;
use App\Modules\Bk\Http\Controllers\BkGroupProgramController;
use App\Modules\Bk\Http\Controllers\BkFeedbackController;
use App\Modules\Bk\Http\Controllers\BkExternalReferralController;
use App\Modules\Bk\Http\Controllers\BkDevelopmentPlanController;
use App\Modules\Bk\Http\Controllers\BkConsentController;
use App\Modules\Bk\Http\Controllers\BkConferenceController;
use App\Modules\Bk\Http\Controllers\BkCareerController;
use App\Modules\Bk\Http\Controllers\BkAnalyticsController;
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

    /*
    |--------------------------------------------------------------------------
    | Phase 2 — konferensi kasus, rujukan eksternal, penanda, pengembangan
    |--------------------------------------------------------------------------
    |
    | Cakupan baris dan kerahasiaan tetap ditegakkan layanan; middleware di sini
    | hanya menyaring pemanggil yang sudah pasti tidak berhak, sehingga kesalahan
    | izin terlihat lebih awal dan jejaknya lebih rapi.
    */
    Route::middleware('permission:bk.conference.view')->group(function (): void {
        Route::get('conferences', [BkConferenceController::class, 'index'])->middleware('throttle:bk-module');
        Route::get('conferences/{id}', [BkConferenceController::class, 'show'])->whereNumber('id')->middleware('throttle:bk-module');
        Route::get('cases/{id}/conference', [BkConferenceController::class, 'index'])->whereNumber('id')->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.conference.manage')->group(function (): void {
        Route::post('conferences', [BkConferenceController::class, 'store'])->middleware('throttle:bk-write');
        Route::post('cases/{id}/conference', [BkConferenceController::class, 'store'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::patch('conferences/{id}', [BkConferenceController::class, 'update'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('conferences/{id}/start', [BkConferenceController::class, 'start'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('conferences/{id}/complete', [BkConferenceController::class, 'complete'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('conferences/{id}/participants', [BkConferenceController::class, 'addParticipant'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::delete('conferences/{id}/participants/{participantId}', [BkConferenceController::class, 'removeParticipant'])
            ->whereNumber('id')->whereNumber('participantId')->middleware('throttle:bk-write');
        Route::post('conferences/{id}/actions', [BkConferenceController::class, 'addAction'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('conference-actions/{actionId}/complete', [BkConferenceController::class, 'completeAction'])->whereNumber('actionId')->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.external-referral.view')->group(function (): void {
        Route::get('referral-providers', [BkExternalReferralController::class, 'providers'])->middleware('throttle:bk-module');
        Route::get('external-referrals', [BkExternalReferralController::class, 'index'])->middleware('throttle:bk-module');
        Route::get('external-referrals/{id}', [BkExternalReferralController::class, 'show'])->whereNumber('id')->middleware('throttle:bk-module');
        Route::get('cases/{id}/external-referrals', [BkExternalReferralController::class, 'index'])->whereNumber('id')->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.external-referral.manage')->group(function (): void {
        Route::post('referral-providers', [BkExternalReferralController::class, 'storeProvider'])->middleware('throttle:bk-write');
        Route::patch('referral-providers/{id}', [BkExternalReferralController::class, 'updateProvider'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals', [BkExternalReferralController::class, 'store'])->middleware('throttle:bk-write');
        Route::post('cases/{id}/external-referrals', [BkExternalReferralController::class, 'store'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/assessment', [BkExternalReferralController::class, 'assessment'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/consent', [BkExternalReferralController::class, 'requestConsent'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/submit', [BkExternalReferralController::class, 'submit'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/appointment', [BkExternalReferralController::class, 'appointment'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/result', [BkExternalReferralController::class, 'result'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/follow-up', [BkExternalReferralController::class, 'followUp'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/complete', [BkExternalReferralController::class, 'complete'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('external-referrals/{id}/cancel', [BkExternalReferralController::class, 'cancel'])->whereNumber('id')->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.consent.view')->group(function (): void {
        Route::get('consents', [BkConsentController::class, 'index'])->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.consent.request')->group(function (): void {
        Route::post('consents', [BkConsentController::class, 'store'])->middleware('throttle:bk-write');
    });

    // Pemberian/pencabutan juga dapat dilakukan wali; controller memeriksa hubungan wali.
    Route::post('consents/{id}/grant', [BkConsentController::class, 'grant'])->whereNumber('id')->middleware('throttle:bk-write');
    Route::post('consents/{id}/revoke', [BkConsentController::class, 'revoke'])->whereNumber('id')->middleware('throttle:bk-write');

    Route::middleware('permission:bk.risk.view')->group(function (): void {
        Route::get('risk-indicators', [BkRiskIndicatorController::class, 'index'])->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.risk.manage')->group(function (): void {
        Route::post('risk-indicators', [BkRiskIndicatorController::class, 'store'])->middleware('throttle:bk-write');
        Route::post('risk-indicators/{id}/review', [BkRiskIndicatorController::class, 'review'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('risk-indicators/{id}/escalate', [BkRiskIndicatorController::class, 'escalate'])->whereNumber('id')->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.early-warning.view')->group(function (): void {
        Route::get('early-warning/rules', [BkRiskIndicatorController::class, 'rules'])->middleware('throttle:bk-module');
        Route::get('early-warning/events', [BkRiskIndicatorController::class, 'events'])->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.early-warning.manage')->group(function (): void {
        Route::post('early-warning/rules', [BkRiskIndicatorController::class, 'storeRule'])->middleware('throttle:bk-write');
        Route::post('early-warning/rules/{id}/toggle', [BkRiskIndicatorController::class, 'toggleRule'])->whereNumber('id')->middleware('throttle:bk-write');
    });

    Route::post('early-warning/evaluate', [BkRiskIndicatorController::class, 'evaluate'])
        ->middleware(['permission:bk.early-warning.run', 'throttle:bk-report']);

    Route::middleware('permission:bk.development.view')->group(function (): void {
        Route::get('development-plans', [BkDevelopmentPlanController::class, 'index'])->middleware('throttle:bk-module');
        Route::get('development-plans/{id}', [BkDevelopmentPlanController::class, 'show'])->whereNumber('id')->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.development.manage')->group(function (): void {
        Route::post('development-plans', [BkDevelopmentPlanController::class, 'store'])->middleware('throttle:bk-write');
        Route::patch('development-plans/{id}', [BkDevelopmentPlanController::class, 'update'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('development-plans/{id}/goals', [BkDevelopmentPlanController::class, 'addGoal'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('development-plans/{id}/activities', [BkDevelopmentPlanController::class, 'addActivity'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('development-goals/{goalId}/baseline', [BkDevelopmentPlanController::class, 'baseline'])->whereNumber('goalId')->middleware('throttle:bk-write');
        Route::post('development-goals/{goalId}/review', [BkDevelopmentPlanController::class, 'review'])->whereNumber('goalId')->middleware('throttle:bk-write');
        Route::post('development-activities/{activityId}/complete', [BkDevelopmentPlanController::class, 'completeActivity'])->whereNumber('activityId')->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.career.view')->group(function (): void {
        Route::get('career-profiles', [BkCareerController::class, 'index'])->middleware('throttle:bk-module');
        Route::get('students/{studentId}/career-sessions', [BkCareerController::class, 'sessions'])->whereNumber('studentId')->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.career.manage')->group(function (): void {
        Route::post('career-profiles', [BkCareerController::class, 'upsert'])->middleware('throttle:bk-write');
        Route::post('career-guidance-sessions', [BkCareerController::class, 'storeSession'])->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.program.view')->group(function (): void {
        Route::get('programs', [BkGroupProgramController::class, 'index'])->middleware('throttle:bk-module');
        Route::get('programs/{id}', [BkGroupProgramController::class, 'show'])->whereNumber('id')->middleware('throttle:bk-module');
        Route::get('programs/{id}/evaluation', [BkGroupProgramController::class, 'evaluation'])->whereNumber('id')->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.program.manage')->group(function (): void {
        Route::post('programs', [BkGroupProgramController::class, 'store'])->middleware('throttle:bk-write');
        Route::patch('programs/{id}', [BkGroupProgramController::class, 'update'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('programs/{id}/sessions', [BkGroupProgramController::class, 'addSession'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('programs/{id}/members', [BkGroupProgramController::class, 'addMember'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('program-sessions/{sessionId}/attendance', [BkGroupProgramController::class, 'attendance'])->whereNumber('sessionId')->middleware('throttle:bk-write');
    });

    // Umpan balik: siswa & wali memakai endpoint sendiri; konselor hanya membaca.
    Route::post('feedback/student', [BkFeedbackController::class, 'storeStudent'])->middleware('throttle:bk-write');
    Route::post('feedback/guardian', [BkFeedbackController::class, 'storeGuardian'])->middleware('throttle:bk-write');
    Route::patch('feedback/{id}', [BkFeedbackController::class, 'update'])->whereNumber('id')->middleware('throttle:bk-write');
    Route::post('feedback/{id}/finalize', [BkFeedbackController::class, 'finalize'])->whereNumber('id')->middleware('throttle:bk-write');
    Route::post('feedback/{id}/withdraw', [BkFeedbackController::class, 'withdraw'])->whereNumber('id')->middleware('throttle:bk-write');

    Route::middleware('permission:bk.feedback.view')->group(function (): void {
        Route::get('feedback', [BkFeedbackController::class, 'index'])->middleware('throttle:bk-module');
        Route::post('feedback/{id}/review', [BkFeedbackController::class, 'review'])->whereNumber('id')->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.analytics.view')->group(function (): void {
        Route::get('analytics/cases', [BkAnalyticsController::class, 'cases'])->middleware('throttle:bk-report');
        Route::get('analytics/referrals', [BkAnalyticsController::class, 'referrals'])->middleware('throttle:bk-report');
        Route::get('analytics/outcomes', [BkAnalyticsController::class, 'outcomes'])->middleware('throttle:bk-report');
        Route::get('analytics/service', [BkAnalyticsController::class, 'service'])->middleware('throttle:bk-report');
        Route::get('analytics/trends', [BkAnalyticsController::class, 'trends'])->middleware('throttle:bk-report');
        Route::get('analytics/aging', [BkAnalyticsController::class, 'aging'])->middleware('throttle:bk-report');
    });

    Route::middleware('permission:bk.workload.view')->group(function (): void {
        Route::get('analytics/workload', [BkAnalyticsController::class, 'workload'])->middleware('throttle:bk-report');
    });

    Route::middleware('permission:bk.search')->group(function (): void {
        Route::get('search', [BkSecurityController::class, 'search'])->middleware('throttle:bk-search');
    });

    Route::middleware('permission:bk.report.export')->group(function (): void {
        Route::post('exports', [BkSecurityController::class, 'export'])->middleware('throttle:bk-report');
        Route::get('exports', [BkSecurityController::class, 'exportFiles'])->middleware('throttle:bk-report');
    });

    Route::middleware('permission:bk.sla.view')->group(function (): void {
        Route::get('sla/events', [BkSecurityController::class, 'slaEvents'])->middleware('throttle:bk-module');
        Route::get('escalations', [BkSecurityController::class, 'escalations'])->middleware('throttle:bk-module');
    });

    Route::middleware('permission:bk.sla.manage')->group(function (): void {
        Route::post('sla/monitor', [BkSecurityController::class, 'monitorSla'])->middleware('throttle:bk-report');
        Route::post('escalations/{id}/acknowledge', [BkSecurityController::class, 'acknowledgeEscalation'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('escalations/{id}/resolve', [BkSecurityController::class, 'resolveEscalation'])->whereNumber('id')->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.retention.view')->group(function (): void {
        Route::get('retention', [BkSecurityController::class, 'retention'])->middleware('throttle:bk-report');
    });

    Route::middleware('permission:bk.retention.manage')->group(function (): void {
        Route::post('retention/policies', [BkSecurityController::class, 'setRetentionPolicy'])->middleware('throttle:bk-write');
        Route::post('retention/request-disposal', [BkSecurityController::class, 'requestDisposal'])->middleware('throttle:bk-write');
    });

    Route::middleware('permission:bk.retention.approve')->group(function (): void {
        Route::post('retention/actions/{id}/approve', [BkSecurityController::class, 'approveDisposal'])->whereNumber('id')->middleware('throttle:bk-write');
        Route::post('retention/execute-disposal', [BkSecurityController::class, 'executeDisposal'])->middleware('throttle:bk-report');
    });

    Route::middleware('permission:bk.settings.view')->group(function (): void {
        Route::get('automation', [BkSecurityController::class, 'automation'])->middleware('throttle:bk-module');
        Route::get('early-warning/health', [BkRiskIndicatorController::class, 'health'])->middleware('throttle:bk-module');
    });

});
