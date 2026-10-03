<?php

declare(strict_types=1);

use App\Modules\SchoolOperations\Competitions\Http\Controllers\CompetitionApiController as C;
use Illuminate\Support\Facades\Route;

// Static routes precede model-bound {competition} routes to avoid ambiguous matching.
Route::get('competitions/meta', [C::class, 'meta'])->middleware('permission:competition.view')->name('meta');
Route::get('competition-templates', [C::class, 'templates'])->middleware('permission:competition.view')->name('templates.index');
Route::get('competitions/dashboard', [C::class, 'dashboard'])->middleware('permission:competition.view')->name('dashboard');
Route::get('competitions', [C::class, 'index'])->middleware('permission:competition.view')->name('index');
Route::post('competitions', [C::class, 'store'])->middleware(['permission:competition.create', 'throttle:competition-write'])->name('store');
Route::get('competitions/{competition}', [C::class, 'show'])->middleware('permission:competition.view')->name('show');
Route::match(['put', 'patch'], 'competitions/{competition}', [C::class, 'update'])->middleware(['permission:competition.update', 'throttle:competition-write'])->name('update');
Route::delete('competitions/{competition}', [C::class, 'destroy'])->middleware(['permission:competition.delete', 'throttle:competition-write'])->name('destroy');
Route::get('competitions/{competition}/editions', [C::class, 'editions'])->middleware('permission:competition.view')->name('editions.index');
Route::post('competitions/{competition}/editions', [C::class, 'storeEdition'])->middleware(['permission:competition.update', 'throttle:competition-write'])->name('editions.store');
Route::post('competitions/{competition}/editions/from-template', [C::class, 'instantiateEdition'])->middleware(['permission:competition.update', 'throttle:competition-write'])->name('editions.from-template');

Route::get('competition-editions/{edition}', [C::class, 'showEdition'])->middleware('permission:competition.view')->name('editions.show');
Route::match(['put', 'patch'], 'competition-editions/{edition}', [C::class, 'updateEdition'])->middleware(['permission:competition.update', 'throttle:competition-write'])->name('editions.update');
Route::post('competition-editions/{edition}/publish', [C::class, 'publishEdition'])->middleware(['permission:competition.publish', 'throttle:competition-write'])->name('editions.publish');
Route::post('competition-editions/{edition}/open-registration', [C::class, 'openRegistration'])->middleware(['permission:competition.publish', 'throttle:competition-write'])->name('editions.open-registration');

Route::get('competition-editions/{edition}/categories', [C::class, 'categories'])->middleware('permission:competition.view')->name('categories.index');
Route::post('competition-editions/{edition}/categories', [C::class, 'storeCategory'])->middleware(['permission:competition.update', 'throttle:competition-write'])->name('categories.store');
Route::get('competition-editions/{edition}/rubrics', [C::class, 'rubrics'])->middleware('permission:competition.view')->name('rubrics.index');
Route::post('competition-editions/{edition}/rubrics', [C::class, 'storeRubric'])->middleware(['permission:competition.update', 'throttle:competition-write'])->name('rubrics.store');

Route::get('competition-discover', [C::class, 'discover'])->middleware('permission:registration.create')->name('discover');
Route::get('competition-editions/{edition}/registrations', [C::class, 'registrations'])->middleware('permission:registration.view')->name('registrations.index');
Route::post('competition-editions/{edition}/registrations', [C::class, 'storeRegistration'])->middleware(['permission:registration.create', 'throttle:competition-write'])->name('registrations.store');
Route::get('my-competitions', [C::class, 'myRegistrations'])->middleware('permission:registration.create')->name('registrations.mine');
Route::get('competition-my-children', [C::class, 'myChildren'])->middleware('permission:competition.portal.parent')->name('participants.children');
Route::get('competition-registrations/{registration}', [C::class, 'showRegistration'])->middleware('permission:registration.view')->name('registrations.show');
Route::post('competition-registrations/{registration}/submit', [C::class, 'submitRegistration'])->middleware(['permission:registration.create', 'throttle:competition-write'])->name('registrations.submit');
Route::post('competition-registrations/{registration}/evaluate-eligibility', [C::class, 'evaluateEligibility'])->middleware(['permission:registration.create', 'throttle:competition-write'])->name('registrations.eligibility');
Route::post('competition-registrations/{registration}/approve', [C::class, 'decideRegistration'])->middleware(['permission:registration.approve', 'throttle:competition-write'])->name('registrations.approve');
Route::post('competition-registrations/{registration}/reject', [C::class, 'decideRegistration'])->middleware(['permission:registration.reject', 'throttle:competition-write'])->name('registrations.reject');
Route::post('competition-registrations/{registration}/needs-revision', [C::class, 'decideRegistration'])->middleware(['permission:registration.review', 'throttle:competition-write'])->name('registrations.revision');
Route::post('competition-registrations/{registration}/withdraw', [C::class, 'withdrawRegistration'])->middleware(['permission:registration.create', 'throttle:competition-write'])->name('registrations.withdraw');
Route::post('competition-registrations/{registration}/documents', [C::class, 'uploadDocument'])->middleware(['permission:registration.create', 'throttle:competition-write'])->name('registrations.documents.store');
Route::post('competition-registration-documents/{document}/review', [C::class, 'reviewDocument'])->middleware(['permission:registration.review', 'throttle:competition-write'])->name('registrations.documents.review');
Route::get('competition-registration-documents/{document}/download', [C::class, 'downloadDocument'])->middleware('permission:registration.view')->name('registrations.documents.download');
Route::post('competition-consents/{consent}/decide', [C::class, 'decideConsent'])->middleware(['permission:consent.decide', 'throttle:competition-write'])->name('consents.decide');
Route::post('competition-registrations/{registration}/sync-payment', [C::class, 'syncPaymentStatus'])->middleware(['permission:registration.review', 'throttle:competition-write'])->name('registrations.payment.sync');
Route::post('competition-registrations/{registration}/waive-payment', [C::class, 'waiveRegistrationFee'])->middleware(['permission:registration.override', 'throttle:competition-write'])->name('registrations.payment.waive');

Route::get('competition-editions/{edition}/participants', [C::class, 'participants'])->middleware('permission:participant.view')->name('participants.index');
Route::get('competition-editions/{edition}/teams', [C::class, 'teams'])->middleware('permission:participant.view')->name('teams.index');
Route::post('competition-editions/{edition}/teams', [C::class, 'storeTeam'])->middleware(['permission:team.manage', 'throttle:competition-write'])->name('teams.store');
Route::post('competition-teams/{team}/lock-roster', [C::class, 'lockRoster'])->middleware(['permission:team.manage', 'throttle:competition-write'])->name('teams.lock-roster');
Route::post('competition-waitlists/{waitlist}/confirm', [C::class, 'confirmWaitlist'])->middleware(['permission:registration.create', 'throttle:competition-write'])->name('waitlist.confirm');

Route::get('competition-editions/{edition}/rounds', [C::class, 'rounds'])->middleware('permission:schedule.view')->name('rounds.index');
Route::post('competition-editions/{edition}/rounds', [C::class, 'storeRound'])->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('rounds.store');
Route::get('competition-rounds/{round}/bracket', [C::class, 'bracket'])->middleware('permission:schedule.view')->name('rounds.bracket');
Route::post('competition-rounds/{round}/generate-fixtures', [C::class, 'generateFixtures'])->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('rounds.generate');
Route::get('competition-editions/{edition}/matches', [C::class, 'matches'])->middleware('permission:schedule.view')->name('matches.index');
Route::get('competition-matches/{match}', [C::class, 'matchDetail'])->middleware('permission:score.view')->name('matches.show');
Route::post('competition-rounds/{round}/matches', [C::class, 'storeMatch'])->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.store');
Route::patch('competition-matches/{match}/schedule', [C::class, 'scheduleMatch'])->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.schedule');
Route::post('competition-matches/{match}/start', [C::class, 'transitionMatch'])->defaults('transition', 'START')->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.start');
Route::post('competition-matches/{match}/pause', [C::class, 'transitionMatch'])->defaults('transition', 'PAUSE')->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.pause');
Route::post('competition-matches/{match}/resume', [C::class, 'transitionMatch'])->defaults('transition', 'RESUME')->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.resume');
Route::post('competition-matches/{match}/complete', [C::class, 'transitionMatch'])->defaults('transition', 'COMPLETE')->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.complete');
Route::post('competition-matches/{match}/postpone', [C::class, 'transitionMatch'])->defaults('transition', 'POSTPONE')->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.postpone');
Route::post('competition-matches/{match}/cancel', [C::class, 'transitionMatch'])->defaults('transition', 'CANCEL')->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('matches.cancel');
Route::get('competition-editions/{edition}/sessions', [C::class, 'sessions'])->middleware('permission:schedule.view')->name('sessions.index');
Route::post('competition-editions/{edition}/sessions', [C::class, 'storeSession'])->middleware(['permission:schedule.manage', 'throttle:competition-write'])->name('sessions.store');
Route::get('competition-editions/{edition}/schedule-conflicts', [C::class, 'scheduleConflicts'])->middleware('permission:schedule.manage')->name('schedule.conflicts');

Route::get('competition-judges', [C::class, 'judges'])->middleware('permission:judge.view')->name('judges.index');
Route::post('competition-judges', [C::class, 'storeJudge'])->middleware(['permission:judge.assign', 'throttle:competition-write'])->name('judges.store');
Route::post('competition-editions/{edition}/judge-assignments', [C::class, 'assignJudge'])->middleware(['permission:judge.assign', 'throttle:competition-write'])->name('judges.assign');
Route::post('competition-judges/{judge}/conflicts', [C::class, 'declareJudgeConflict'])->middleware(['permission:judge.assign', 'throttle:competition-write'])->name('judges.conflicts.store');
Route::post('competition-judge-conflicts/{conflict}/review', [C::class, 'reviewJudgeConflict'])->middleware(['permission:judge.assign', 'throttle:competition-write'])->name('judges.conflicts.review');
Route::get('judge/assignments', [C::class, 'judgeAssignments'])->middleware('permission:score.enter')->name('judges.mine');

Route::post('competition-editions/{edition}/checkins', [C::class, 'checkIn'])->middleware(['permission:participant.manage', 'throttle:competition-score'])->name('checkins.store');
Route::post('competition-checkins/{checkin}/no-show', [C::class, 'noShow'])->middleware(['permission:participant.manage', 'throttle:competition-write'])->name('checkins.no-show');

Route::get('competition-editions/{edition}/scorecards', [C::class, 'scorecards'])->middleware('permission:score.view')->name('scorecards.index');
Route::post('competition-matches/{match}/scorecards', [C::class, 'saveScorecard'])->middleware(['permission:score.enter', 'throttle:competition-score'])->name('scorecards.store');
Route::post('competition-scorecards/{scorecard}/submit', [C::class, 'submitScorecard'])->middleware(['permission:score.submit', 'throttle:competition-score'])->name('scorecards.submit');
Route::post('competition-scorecards/{scorecard}/finalize', [C::class, 'finalizeScorecard'])->middleware(['permission:score.finalize', 'throttle:competition-write'])->name('scorecards.finalize');
Route::post('competition-scorecards/{scorecard}/amendments', [C::class, 'createScoreAmendment'])->middleware(['permission:score.amend', 'throttle:competition-write'])->name('scorecards.amendments.store');
Route::post('competition-score-amendments/{amendment}/decide', [C::class, 'decideScoreAmendment'])->middleware(['permission:score.amend', 'throttle:competition-write'])->name('scorecards.amendments.decide');
Route::post('competition-penalties', [C::class, 'applyPenalty'])->middleware(['permission:score.review', 'throttle:competition-write'])->name('penalties.store');

Route::get('competition-editions/{edition}/results', [C::class, 'results'])->middleware('permission:result.view')->name('results.index');
Route::post('competition-editions/{edition}/results/finalize', [C::class, 'finalizeResults'])->middleware(['permission:result.finalize', 'throttle:competition-write'])->name('results.finalize');
Route::post('competition-editions/{edition}/results/publish', [C::class, 'publishResults'])->middleware(['permission:result.publish', 'throttle:competition-write'])->name('results.publish');
Route::get('competition-editions/{edition}/awards', [C::class, 'awards'])->middleware('permission:result.view')->name('awards.index');
Route::post('competition-editions/{edition}/awards', [C::class, 'storeAward'])->middleware(['permission:award.manage', 'throttle:competition-write'])->name('awards.store');
Route::post('competition-awards/{award}/results/{result}', [C::class, 'assignAward'])->middleware(['permission:award.manage', 'throttle:competition-write'])->name('awards.assign');
Route::post('competition-award-recipients/{recipient}/certificate', [C::class, 'generateCertificate'])->middleware(['permission:certificate.generate', 'throttle:competition-write'])->name('certificates.generate');
Route::get('competition-editions/{edition}/protests', [C::class, 'protests'])->middleware('permission:appeal.review')->name('protests.index');
Route::post('competition-editions/{edition}/protests', [C::class, 'storeProtest'])->middleware(['permission:appeal.create', 'throttle:competition-write'])->name('protests.store');
Route::post('competition-protests/{protest}/appeals', [C::class, 'storeAppeal'])->middleware(['permission:appeal.create', 'throttle:competition-write'])->name('appeals.store');
Route::get('competition-editions/{edition}/appeals', [C::class, 'appeals'])->middleware('permission:appeal.review')->name('appeals.index');
Route::post('competition-appeals/{appeal}/decide', [C::class, 'decideAppeal'])->middleware(['permission:appeal.decide', 'throttle:competition-write'])->name('appeals.decide');
Route::post('competition-editions/{edition}/appeal-committees', [C::class, 'storeAppealCommittee'])->middleware(['permission:appeal.review', 'throttle:competition-write'])->name('appeal-committees.store');
Route::post('competition-appeal-committees/{committee}/members', [C::class, 'addAppealCommitteeMember'])->middleware(['permission:appeal.review', 'throttle:competition-write'])->name('appeal-committees.members.store');
Route::get('competition-editions/{edition}/incidents', [C::class, 'incidents'])->middleware('permission:incident.manage')->name('incidents.index');
Route::post('competition-editions/{edition}/incidents', [C::class, 'storeIncident'])->middleware(['permission:incident.manage', 'throttle:competition-write'])->name('incidents.store');
Route::post('competition-incidents/{incident}/resolve', [C::class, 'resolveIncident'])->middleware(['permission:incident.manage', 'throttle:competition-write'])->name('incidents.resolve');
Route::get('competition-editions/{edition}/audit', [C::class, 'audit'])->middleware('permission:competition.audit')->name('audit.index');
