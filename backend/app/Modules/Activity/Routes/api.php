<?php

use App\Modules\Activity\Http\Controllers\ActivityAssessmentController;
use App\Modules\Activity\Http\Controllers\ActivityAttendanceController;
use App\Modules\Activity\Http\Controllers\ActivityCatalogController;
use App\Modules\Activity\Http\Controllers\ActivityCompetitionController;
use App\Modules\Activity\Http\Controllers\ActivityMembershipController;
use App\Modules\Activity\Http\Controllers\ActivityMetaController;
use App\Modules\Activity\Http\Controllers\ActivityPortalController;
use App\Modules\Activity\Http\Controllers\ActivityRecognitionController;
use App\Modules\Activity\Http\Controllers\ActivityRegistrationController;
use App\Modules\Activity\Http\Controllers\ActivityScheduleController;
use Illuminate\Support\Facades\Route;

/*
| Route Modul Student Activity & Extracurricular — seluruhnya di `/api/v1/activities/*`.
|
| Middleware `permission:activity.*` menentukan *layar/aksi* yang boleh dibuka.
| Cakupan *baris* (pembina Futsal tidak boleh melihat anggota Robotics, orang tua
| A tidak boleh melihat anak B, juri hanya penampilan yang ditugaskan) ditegakkan
| terpisah di `ActivityAccessService`.
|
| Verifikasi sertifikat sengaja **tanpa autentikasi** — memang untuk pihak luar —
| tetapi dibatasi laju dan hanya mengembalikan fakta sertifikat, bukan data siswa.
*/

Route::middleware(['auth:sanctum', 'throttle:activity-module'])->group(function (): void {

    // ------------------------------------------------------------ konteks
    Route::get('me', [ActivityMetaController::class, 'me'])->middleware('permission:activity.view');
    Route::get('metadata', [ActivityMetaController::class, 'metadata'])->middleware('permission:activity.view');
    Route::get('dashboard', [ActivityMetaController::class, 'dashboard'])->middleware('permission:activity.view');
    Route::get('health', [ActivityMetaController::class, 'health'])->middleware('permission:activity.settings.view');
    Route::get('settings', [ActivityMetaController::class, 'settings'])->middleware('permission:activity.settings.view');

    // -------------------------------------------------------------- katalog
    Route::get('categories', [ActivityCatalogController::class, 'categoryTree'])->middleware('permission:activity.view');
    Route::post('categories', [ActivityCatalogController::class, 'categories'])->middleware('permission:activity.category.manage');
    Route::put('categories/{category}', [ActivityCatalogController::class, 'updateCategory'])->middleware('permission:activity.category.manage');
    Route::delete('categories/{category}', [ActivityCatalogController::class, 'deleteCategory'])->middleware('permission:activity.category.manage');

    Route::get('periods', [ActivityCatalogController::class, 'periods'])->middleware('permission:activity.view');
    Route::post('periods', [ActivityCatalogController::class, 'storePeriod'])->middleware('permission:activity.period.manage');
    Route::post('periods/{period}/current', [ActivityCatalogController::class, 'markCurrentPeriod'])->middleware('permission:activity.period.manage');

    Route::get('programs', [ActivityCatalogController::class, 'programs'])->middleware('permission:activity.view');
    Route::get('discover', [ActivityCatalogController::class, 'discover'])->middleware('permission:activity.view');
    Route::get('my-programs', [ActivityCatalogController::class, 'myPrograms'])->middleware('permission:activity.view');
    Route::post('programs', [ActivityCatalogController::class, 'storeProgram'])->middleware('permission:activity.program.manage');
    Route::get('programs/{program}', [ActivityCatalogController::class, 'showProgram'])->middleware('permission:activity.view');
    Route::put('programs/{program}', [ActivityCatalogController::class, 'updateProgram'])->middleware('permission:activity.program.manage');
    Route::post('programs/{program}/publish', [ActivityCatalogController::class, 'publishProgram'])->middleware('permission:activity.program.publish');
    Route::post('programs/{program}/archive', [ActivityCatalogController::class, 'archiveProgram'])->middleware('permission:activity.program.manage');
    Route::get('programs/{program}/capacity', [ActivityCatalogController::class, 'capacity'])->middleware('permission:activity.view');
    Route::post('programs/{program}/eligibility', [ActivityCatalogController::class, 'checkEligibility'])->middleware('permission:activity.view');

    // ---------------------------------------------------------- pendaftaran
    Route::get('registrations', [ActivityRegistrationController::class, 'index'])->middleware('permission:activity.registration.view');
    Route::get('registrations/mine', [ActivityRegistrationController::class, 'mine'])->middleware('permission:activity.portal.view');
    Route::post('programs/{program}/registrations', [ActivityRegistrationController::class, 'store'])
        ->middleware(['permission:activity.registration.create', 'throttle:activity-write']);
    Route::get('registrations/{registration}', [ActivityRegistrationController::class, 'show'])->middleware('permission:activity.registration.view');
    Route::post('registrations/{registration}/submit', [ActivityRegistrationController::class, 'submit'])->middleware('permission:activity.registration.create');
    Route::post('registrations/{registration}/decide', [ActivityRegistrationController::class, 'decide'])->middleware('permission:activity.registration.decide');
    Route::post('registrations/{registration}/cancel', [ActivityRegistrationController::class, 'cancel'])->middleware('permission:activity.registration.create');
    Route::post('registrations/{registration}/selection', [ActivityRegistrationController::class, 'select'])->middleware('permission:activity.registration.decide');
    Route::get('programs/{program}/waitlist', [ActivityRegistrationController::class, 'waitlist'])->middleware('permission:activity.registration.view');

    // ------------------------------------------------------------ keanggotaan
    Route::get('memberships', [ActivityMembershipController::class, 'index'])->middleware('permission:activity.membership.view');
    Route::post('programs/{program}/memberships', [ActivityMembershipController::class, 'store'])->middleware('permission:activity.membership.manage');
    Route::get('memberships/{membership}', [ActivityMembershipController::class, 'show'])->middleware('permission:activity.membership.view');
    Route::post('memberships/{membership}/suspend', [ActivityMembershipController::class, 'suspend'])->middleware('permission:activity.membership.manage');
    Route::post('memberships/{membership}/reactivate', [ActivityMembershipController::class, 'reactivate'])->middleware('permission:activity.membership.manage');
    Route::post('memberships/{membership}/role', [ActivityMembershipController::class, 'changeRole'])->middleware('permission:activity.membership.manage');
    Route::post('memberships/{membership}/withdraw', [ActivityMembershipController::class, 'withdraw'])
        ->middleware('permission:activity.portal.view|activity.membership.manage');
    Route::post('withdrawals/{withdrawal}/decide', [ActivityMembershipController::class, 'decideWithdrawal'])->middleware('permission:activity.membership.manage');
    Route::post('memberships/{membership}/transfer', [ActivityMembershipController::class, 'transfer'])->middleware('permission:activity.membership.manage');
    Route::post('transfers/{transfer}/decide', [ActivityMembershipController::class, 'decideTransfer'])->middleware('permission:activity.membership.manage');
    Route::get('students/{studentId}/memberships', [ActivityMembershipController::class, 'forStudent'])->middleware('permission:activity.view');
    Route::post('programs/{program}/waitlist/promote', [ActivityMembershipController::class, 'promoteWaitlist'])->middleware('permission:activity.membership.manage');

    // ------------------------------------------------------- jadwal & sesi
    Route::get('programs/{program}/schedules', [ActivityScheduleController::class, 'schedules'])->middleware('permission:activity.view');
    Route::post('programs/{program}/schedules', [ActivityScheduleController::class, 'storeSchedule'])->middleware('permission:activity.schedule.manage');
    Route::put('schedules/{schedule}', [ActivityScheduleController::class, 'updateSchedule'])->middleware('permission:activity.schedule.manage');
    Route::delete('schedules/{schedule}', [ActivityScheduleController::class, 'deleteSchedule'])->middleware('permission:activity.schedule.manage');

    Route::get('sessions', [ActivityScheduleController::class, 'sessions'])->middleware('permission:activity.view');
    Route::post('programs/{program}/sessions', [ActivityScheduleController::class, 'storeSession'])->middleware('permission:activity.session.manage');
    Route::put('sessions/{session}', [ActivityScheduleController::class, 'updateSession'])->middleware('permission:activity.session.manage');
    Route::post('sessions/{session}/cancel', [ActivityScheduleController::class, 'cancelSession'])->middleware('permission:activity.session.manage');
    Route::post('programs/{program}/sessions/generate', [ActivityScheduleController::class, 'generateSessions'])->middleware('permission:activity.session.manage');

    Route::get('programs/{program}/materials', [ActivityScheduleController::class, 'materials'])->middleware('permission:activity.view');
    Route::post('programs/{program}/materials', [ActivityScheduleController::class, 'storeMaterial'])->middleware('permission:activity.session.manage');
    Route::get('programs/{program}/announcements', [ActivityScheduleController::class, 'announcements'])->middleware('permission:activity.view');
    Route::post('programs/{program}/announcements', [ActivityScheduleController::class, 'storeAnnouncement'])->middleware('permission:activity.session.manage');

    // ----------------------------------------------------------- kehadiran
    Route::get('sessions/{session}/attendance', [ActivityAttendanceController::class, 'sheet'])->middleware('permission:activity.attendance.view');
    Route::post('sessions/{session}/attendance', [ActivityAttendanceController::class, 'record'])
        ->middleware(['permission:activity.attendance.record', 'throttle:activity-write']);
    Route::post('sessions/{session}/attendance/bulk', [ActivityAttendanceController::class, 'recordBulk'])
        ->middleware(['permission:activity.attendance.record', 'throttle:activity-write']);
    Route::post('sessions/{session}/complete', [ActivityAttendanceController::class, 'complete'])->middleware('permission:activity.attendance.record');
    Route::get('programs/{programId}/attendance/summary', [ActivityAttendanceController::class, 'programSummary'])->middleware('permission:activity.attendance.view');
    Route::get('students/{studentId}/programs/{programId}/attendance', [ActivityAttendanceController::class, 'studentSummary'])->middleware('permission:activity.view');
    Route::get('my-attendance/{programId}', [ActivityPortalController::class, 'myAttendance'])->middleware('permission:activity.portal.view');

    // ----------------------------------------------------------- penilaian
    Route::get('programs/{program}/rubrics', [ActivityAssessmentController::class, 'rubrics'])->middleware('permission:activity.assessment.view');
    Route::post('programs/{program}/rubrics', [ActivityAssessmentController::class, 'storeRubric'])->middleware('permission:activity.assessment.manage');
    Route::get('programs/{program}/assessments', [ActivityAssessmentController::class, 'index'])->middleware('permission:activity.assessment.view');
    Route::post('programs/{program}/assessments', [ActivityAssessmentController::class, 'store'])->middleware('permission:activity.assessment.manage');
    Route::post('assessments/{assessment}/publish', [ActivityAssessmentController::class, 'publish'])->middleware('permission:activity.assessment.publish');
    Route::get('assessments/{assessment}/results', [ActivityAssessmentController::class, 'results'])->middleware('permission:activity.assessment.view');
    Route::post('assessments/{assessment}/results', [ActivityAssessmentController::class, 'submitResult'])->middleware('permission:activity.assessment.manage');
    Route::post('assessments/{assessment}/results/bulk', [ActivityAssessmentController::class, 'submitResults'])->middleware('permission:activity.assessment.manage');
    Route::get('memberships/{membership}/progress', [ActivityAssessmentController::class, 'progress'])->middleware('permission:activity.view');
    Route::get('memberships/{membership}/completion', [ActivityAssessmentController::class, 'evaluateCompletion'])->middleware('permission:activity.view');
    Route::post('memberships/{membership}/complete', [ActivityAssessmentController::class, 'complete'])->middleware('permission:activity.assessment.manage');

    // ---------------------------------------------------------- kompetisi
    Route::get('competitions', [ActivityCompetitionController::class, 'index'])->middleware('permission:activity.competition.view');
    Route::post('competitions', [ActivityCompetitionController::class, 'store'])->middleware('permission:activity.competition.manage');
    Route::get('competitions/{competition}', [ActivityCompetitionController::class, 'show'])->middleware('permission:activity.competition.view');
    Route::post('competitions/{competition}/open', [ActivityCompetitionController::class, 'openRegistration'])->middleware('permission:activity.competition.manage');
    Route::post('competitions/{competition}/participants', [ActivityCompetitionController::class, 'registerParticipant'])->middleware('permission:activity.competition.manage');
    Route::post('competition-participants/{participantId}/verify', [ActivityCompetitionController::class, 'verifyParticipant'])->middleware('permission:activity.competition.manage');
    Route::post('competitions/{competition}/teams', [ActivityCompetitionController::class, 'storeTeam'])->middleware('permission:activity.competition.manage');
    Route::put('competition-teams/{team}/members', [ActivityCompetitionController::class, 'updateTeamMembers'])->middleware('permission:activity.competition.manage');
    Route::post('competitions/{competition}/rounds', [ActivityCompetitionController::class, 'storeRound'])->middleware('permission:activity.competition.manage');
    Route::post('competition-rounds/{round}/matches', [ActivityCompetitionController::class, 'storeMatch'])->middleware('permission:activity.competition.manage');
    Route::post('competitions/{competition}/judges', [ActivityCompetitionController::class, 'assignJudge'])->middleware('permission:activity.competition.manage');
    Route::get('my-judge-assignments', [ActivityCompetitionController::class, 'myJudgeAssignments'])->middleware('permission:activity.competition.judge');
    Route::post('competition-matches/{match}/scores', [ActivityCompetitionController::class, 'submitScore'])->middleware('permission:activity.competition.judge');
    Route::post('competition-matches/{match}/scores/lock', [ActivityCompetitionController::class, 'lockScores'])->middleware('permission:activity.competition.manage');
    Route::post('competition-scores/{score}/correct', [ActivityCompetitionController::class, 'correctScore'])->middleware('permission:activity.competition.score.correct');
    Route::post('competitions/{competition}/results/build', [ActivityCompetitionController::class, 'buildResults'])->middleware('permission:activity.competition.manage');
    Route::post('competition-results/{result}/verify', [ActivityCompetitionController::class, 'verifyResult'])->middleware('permission:activity.competition.result.publish');
    Route::post('competitions/{competition}/results/publish', [ActivityCompetitionController::class, 'publishResults'])->middleware('permission:activity.competition.result.publish');

    // ------------------------------------------- prestasi, sertifikat, portfolio
    Route::get('achievements', [ActivityRecognitionController::class, 'achievements'])->middleware('permission:activity.achievement.view');
    Route::post('achievements', [ActivityRecognitionController::class, 'storeAchievement'])->middleware('permission:activity.achievement.manage');
    Route::post('achievements/{achievement}/verify', [ActivityRecognitionController::class, 'verifyAchievement'])->middleware('permission:activity.achievement.verify');

    Route::get('certificates', [ActivityRecognitionController::class, 'certificates'])->middleware('permission:activity.certificate.view');
    Route::post('completions/{completion}/certificate', [ActivityRecognitionController::class, 'issueCertificate'])->middleware('permission:activity.certificate.issue');
    Route::post('achievements/{achievement}/certificate', [ActivityRecognitionController::class, 'issueAchievementCertificate'])->middleware('permission:activity.certificate.issue');
    Route::post('certificates/{certificate}/revoke', [ActivityRecognitionController::class, 'revokeCertificate'])->middleware('permission:activity.certificate.revoke');

    Route::get('students/{studentId}/portfolio', [ActivityRecognitionController::class, 'portfolio'])->middleware('permission:activity.portfolio.view');
    Route::post('portfolios', [ActivityRecognitionController::class, 'storePortfolio'])->middleware('permission:activity.portfolio.view');
    Route::post('portfolios/{portfolio}/share', [ActivityRecognitionController::class, 'sharePortfolio'])->middleware('permission:activity.portfolio.share');
    Route::get('students/{studentId}/transcript', [ActivityRecognitionController::class, 'transcript'])->middleware('permission:activity.portfolio.view');

    // ------------------------------------------------------------- laporan
    Route::get('reports/{type}', [ActivityRecognitionController::class, 'report'])->middleware('permission:activity.report.view');

    // --------------------------------------------------------------- portal
    Route::get('portal/parent', [ActivityPortalController::class, 'parentDashboard'])->middleware('permission:activity.portal.view');
    Route::get('portal/parent/children/{studentId}', [ActivityPortalController::class, 'parentChild'])->middleware('permission:activity.portal.view');
    Route::get('portal/consents', [ActivityPortalController::class, 'pendingConsents'])->middleware('permission:activity.portal.view');
    Route::post('portal/consents/{consent}/decide', [ActivityPortalController::class, 'decideConsent'])->middleware('permission:activity.consent.decide');
    Route::get('counseling/students/{studentId}/summary', [ActivityPortalController::class, 'counselingSummary'])->middleware('permission:activity.counseling.view');
});

/*
| Verifikasi sertifikat publik (§53) — tanpa login, tetap dibatasi laju.
| Hanya mengembalikan fakta sertifikat; nama/NIS siswa tidak pernah dikirim.
*/
Route::middleware(['throttle:activity-public-verify'])->group(function (): void {
    Route::post('certificates/verify', [ActivityRecognitionController::class, 'verifyCertificate']);
    Route::get('portfolios/public/{token}', [ActivityRecognitionController::class, 'publicPortfolio']);
});
