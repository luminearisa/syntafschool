<?php

use App\Modules\CBT\Http\Controllers\CbtController;
use Illuminate\Support\Facades\Route;

Route::prefix('cbt')->name('cbt.')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('dashboard', [CbtController::class, 'dashboard'])->middleware('permission:cbt.view');

        Route::get('questions', [CbtController::class, 'questionsIndex'])->middleware('permission:cbt.question.view');
        Route::post('questions', [CbtController::class, 'questionsStore'])->middleware('permission:cbt.question.create');
        Route::post('questions/import', [CbtController::class, 'importQuestions'])->middleware('permission:cbt.question.create');
        Route::get('questions/{question}', [CbtController::class, 'questionsShow'])->middleware('permission:cbt.question.view');
        Route::match(['put', 'patch'], 'questions/{question}', [CbtController::class, 'questionsUpdate'])->middleware('permission:cbt.question.update');
        Route::delete('questions/{question}', [CbtController::class, 'questionsDestroy'])->middleware('permission:cbt.question.delete');

        Route::get('blueprints', [CbtController::class, 'blueprintsIndex'])->middleware('permission:cbt.blueprint.view');
        Route::post('blueprints', [CbtController::class, 'blueprintsStore'])->middleware('permission:cbt.blueprint.manage');
        Route::get('blueprints/{blueprint}', [CbtController::class, 'blueprintsShow'])->middleware('permission:cbt.blueprint.view');
        Route::match(['put', 'patch'], 'blueprints/{blueprint}', [CbtController::class, 'blueprintsUpdate'])->middleware('permission:cbt.blueprint.manage');

        Route::get('sessions', [CbtController::class, 'sessionsIndex'])->middleware('permission:cbt.session.view');
        Route::post('sessions', [CbtController::class, 'sessionsStore'])->middleware('permission:cbt.session.manage');
        Route::get('sessions/{session}', [CbtController::class, 'sessionsShow'])->middleware('permission:cbt.session.view');
        Route::post('sessions/{session}/enroll', [CbtController::class, 'enroll'])->middleware('permission:cbt.session.manage');
        Route::post('sessions/{session}/schedule', [CbtController::class, 'schedule'])->middleware('permission:cbt.session.manage');
        Route::post('sessions/{session}/open', [CbtController::class, 'open'])->middleware('permission:cbt.session.manage');
        Route::post('sessions/{session}/close', [CbtController::class, 'close'])->middleware('permission:cbt.session.manage');
        Route::post('sessions/{session}/token', [CbtController::class, 'rotateToken'])->middleware('permission:cbt.proctor');
        Route::post('sessions/{session}/release', [CbtController::class, 'release'])->middleware('permission:cbt.session.manage');
        Route::get('sessions/{session}/monitor', [CbtController::class, 'monitor'])->middleware('permission:cbt.proctor');
        Route::get('sessions/{session}/report', [CbtController::class, 'report'])->middleware('permission:cbt.report.view');
        Route::get('sessions/{session}/export', [CbtController::class, 'exportCsv'])->middleware('permission:cbt.report.view');
        Route::post('sessions/{session}/push-scores', [CbtController::class, 'pushScores'])->middleware('permission:cbt.grade');

        Route::get('grading', [CbtController::class, 'gradeQueue'])->middleware('permission:cbt.grade');
        Route::post('answers/{answer}/grade', [CbtController::class, 'gradeEssay'])->middleware('permission:cbt.grade');
        Route::post('attempts/{attempt}/void', [CbtController::class, 'voidAttempt'])->middleware('permission:cbt.proctor');
        Route::get('attempts/{attempt}/review', [CbtController::class, 'review'])->middleware('permission:cbt.attempt');

        Route::get('my/sessions', [CbtController::class, 'mySessions'])->middleware('permission:cbt.attempt');
        Route::post('sessions/{session}/start', [CbtController::class, 'start'])->middleware(['permission:cbt.attempt', 'throttle:cbt-start']);
        Route::get('attempts/{attempt}', [CbtController::class, 'player'])->middleware('permission:cbt.attempt');
        Route::put('attempts/{attempt}/questions/{question}', [CbtController::class, 'save'])->middleware(['permission:cbt.attempt', 'throttle:cbt-answer']);
        Route::post('attempts/{attempt}/heartbeat', [CbtController::class, 'heartbeat'])->middleware(['permission:cbt.attempt', 'throttle:cbt-heartbeat']);
        Route::post('attempts/{attempt}/incidents', [CbtController::class, 'incident'])->middleware('permission:cbt.attempt');
        Route::post('attempts/{attempt}/submit', [CbtController::class, 'submit'])->middleware('permission:cbt.attempt');
    });
});
