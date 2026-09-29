<?php

use App\Modules\LMS\Http\Controllers\LmsActivityController;
use App\Modules\LMS\Http\Controllers\LmsAnnouncementController;
use App\Modules\LMS\Http\Controllers\LmsAssignmentController;
use App\Modules\LMS\Http\Controllers\LmsChoiceController;
use App\Modules\LMS\Http\Controllers\LmsAuthController;
use App\Modules\LMS\Http\Controllers\LmsBookmarkController;
use App\Modules\LMS\Http\Controllers\LmsCategoryController;
use App\Modules\LMS\Http\Controllers\LmsCourseController;
use App\Modules\LMS\Http\Controllers\LmsDashboardController;
use App\Modules\LMS\Http\Controllers\LmsEnrollmentController;
use App\Modules\LMS\Http\Controllers\LmsForumController;
use App\Modules\LMS\Http\Controllers\LmsGradeController;
use App\Modules\LMS\Http\Controllers\LmsAssessmentResultController;
use App\Modules\LMS\Http\Controllers\LmsCompetencyController;
use App\Modules\LMS\Http\Controllers\LmsGradeReleaseController;
use App\Modules\LMS\Http\Controllers\LmsGradeReviewController;
use App\Modules\LMS\Http\Controllers\LmsGradeViewController;
use App\Modules\LMS\Http\Controllers\LmsGradebookController;
use App\Modules\LMS\Http\Controllers\LmsOutcomeController;
use App\Modules\LMS\Http\Controllers\LmsRubricController;
use App\Modules\LMS\Http\Controllers\LmsGroupController;
use App\Modules\LMS\Http\Controllers\LmsLearningController;
use App\Modules\LMS\Http\Controllers\LmsLessonController;
use App\Modules\LMS\Http\Controllers\LmsLearningItemController;
use App\Modules\LMS\Http\Controllers\LmsProgressController;
use App\Modules\LMS\Http\Controllers\LmsQuestionBankController;
use App\Modules\LMS\Http\Controllers\LmsQuestionController;
use App\Modules\LMS\Http\Controllers\LmsQuizAttemptController;
use App\Modules\LMS\Http\Controllers\LmsQuizController;
use App\Modules\LMS\Http\Controllers\LmsPublicController;
use App\Modules\LMS\Http\Controllers\LmsSearchController;
use App\Modules\LMS\Http\Controllers\LmsSectionController;
use App\Modules\LMS\Http\Controllers\LmsSettingsController;
use App\Modules\LMS\Http\Controllers\LmsSubmissionController;
use App\Modules\LMS\Http\Controllers\LmsSurveyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route modul LMS (§37)
|--------------------------------------------------------------------------
|
| Dimuat oleh `LmsServiceProvider` di dalam prefix `api/v1`, sehingga seluruh
| endpoint berada di bawah `/api/v1/lms/*`.
|
| Otorisasi memakai middleware `lms.permission:*` — bukan `permission:*` milik
| Spatie — supaya pemeriksaan yang sama tetap bekerja ketika LMS berjalan
| mandiri (Standalone Mode) tanpa RBAC ERP. Aturan berbasis kepemilikan course
| (guru hanya boleh mengubah course-nya sendiri, §35) ditegakkan di controller
| melalui `CourseAccessService`.
|
*/

Route::prefix('lms')->name('lms.')->group(function (): void {
    // ==================== PUBLIK / GUEST (§30) ====================
    Route::prefix('public')->name('public.')->group(function (): void {
        Route::get('home', [LmsPublicController::class, 'home'])->name('home');
        Route::get('courses', [LmsPublicController::class, 'courses'])->name('courses');
        Route::get('courses/{slug}', [LmsPublicController::class, 'course'])->name('courses.show');
        Route::get('categories', [LmsPublicController::class, 'categories'])->name('categories');
        Route::get('teachers', [LmsPublicController::class, 'teachers'])->name('teachers');
        Route::get('about', [LmsPublicController::class, 'about'])->name('about');
    });

    Route::get('auth/session', [LmsAuthController::class, 'session'])->name('auth.session');
    Route::post('auth/login', [LmsAuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('auth.login');
    Route::post('auth/register', [LmsAuthController::class, 'register'])
        ->middleware('throttle:10,1')->name('auth.register');

    // ==================== TERAUTENTIKASI ====================
    Route::middleware(['auth:sanctum', 'lms.actor'])->group(function (): void {
        Route::get('auth/me', [LmsAuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [LmsAuthController::class, 'logout'])->name('auth.logout');

        // ---------------- Dashboard semua peran (§26–§29) ----------------
        Route::middleware('lms.permission:lms.view')->group(function (): void {
            Route::get('dashboard', [LmsDashboardController::class, 'index'])->name('dashboard');
            Route::get('dashboard/student', [LmsDashboardController::class, 'student'])->name('dashboard.student');
            Route::get('dashboard/teacher', [LmsDashboardController::class, 'teacher'])->name('dashboard.teacher');
            Route::get('dashboard/parent', [LmsDashboardController::class, 'parent'])->name('dashboard.parent');
            Route::get('dashboard/admin', [LmsDashboardController::class, 'admin'])
                ->middleware('lms.permission:lms.report.view,lms.manage')->name('dashboard.admin');
        });

        // ---------------- Portal orang tua (§28) ----------------
        Route::prefix('parent')->name('parent.')->middleware('lms.permission:lms.parent.view,lms.manage')->group(function (): void {
            Route::get('children', [LmsDashboardController::class, 'children'])->name('children');
            Route::get('children/{student}', [LmsDashboardController::class, 'child'])->name('children.show');
        });

        // ---------------- Kategori (§4) ----------------
        Route::get('categories', [LmsCategoryController::class, 'index'])
            ->middleware('lms.permission:lms.category.view,lms.course.view')->name('categories.index');
        Route::post('categories', [LmsCategoryController::class, 'store'])
            ->middleware('lms.permission:lms.category.create')->name('categories.store');
        Route::match(['put', 'patch'], 'categories/{category}', [LmsCategoryController::class, 'update'])
            ->middleware('lms.permission:lms.category.update')->name('categories.update');
        Route::delete('categories/{category}', [LmsCategoryController::class, 'destroy'])
            ->middleware('lms.permission:lms.category.delete')->name('categories.destroy');
        Route::post('categories/reorder', [LmsCategoryController::class, 'reorder'])
            ->middleware('lms.permission:lms.category.update')->name('categories.reorder');

        // ---------------- Course (§5–§9) ----------------
        Route::get('courses/lookups', [LmsCourseController::class, 'lookups'])
            ->middleware('lms.permission:lms.course.view')->name('courses.lookups');
        Route::get('courses', [LmsCourseController::class, 'index'])
            ->middleware('lms.permission:lms.course.view')->name('courses.index');
        Route::post('courses', [LmsCourseController::class, 'store'])
            ->middleware('lms.permission:lms.course.create')->name('courses.store');
        Route::get('courses/{course}', [LmsCourseController::class, 'show'])
            ->middleware('lms.permission:lms.course.view')->name('courses.show');
        Route::match(['put', 'patch'], 'courses/{course}', [LmsCourseController::class, 'update'])
            ->middleware('lms.permission:lms.course.update')->name('courses.update');
        Route::delete('courses/{course}', [LmsCourseController::class, 'destroy'])
            ->middleware('lms.permission:lms.course.delete')->name('courses.destroy');
        Route::post('courses/{course}/duplicate', [LmsCourseController::class, 'duplicate'])
            ->middleware('lms.permission:lms.course.create')->name('courses.duplicate');
        Route::get('courses/{course}/readiness', [LmsCourseController::class, 'readiness'])
            ->middleware('lms.permission:lms.course.update')->name('courses.readiness');
        Route::post('courses/{course}/publish', [LmsCourseController::class, 'publish'])
            ->middleware('lms.permission:lms.course.publish')->name('courses.publish');
        Route::post('courses/{course}/unpublish', [LmsCourseController::class, 'unpublish'])
            ->middleware('lms.permission:lms.course.publish')->name('courses.unpublish');
        Route::post('courses/{course}/archive', [LmsCourseController::class, 'archive'])
            ->middleware('lms.permission:lms.course.publish')->name('courses.archive');
        Route::post('courses/{course}/restore', [LmsCourseController::class, 'restore'])
            ->middleware('lms.permission:lms.course.update')->name('courses.restore');
        Route::get('courses/{course}/statistics', [LmsCourseController::class, 'statistics'])
            ->middleware('lms.permission:lms.report.view,lms.manage')->name('courses.statistics');

        // ---------------- Pengalaman belajar (§39) ----------------
        Route::middleware('lms.permission:lms.content.view')->group(function (): void {
            Route::get('courses/{course}/learn', [LmsLearningController::class, 'player'])->name('courses.learn');
            Route::get('courses/{course}/items/{item}', [LmsLearningController::class, 'item'])->name('courses.items.show');
            Route::post('courses/{course}/items/{item}/view', [LmsLearningController::class, 'trackView'])->name('courses.items.view');
            Route::get('courses/{course}/sections', [LmsSectionController::class, 'index'])->name('courses.sections.index');
            Route::get('items/{item}', [LmsLearningItemController::class, 'show'])->name('items.show');
            Route::get('items/{item}/chapters', [LmsLearningItemController::class, 'chapters'])->name('items.chapters');
            Route::get('resources/{resource}/download', [LmsLearningItemController::class, 'download'])->name('resources.download');
            Route::get('courses/{course}/progress', [LmsProgressController::class, 'courseProgress'])->name('courses.progress');
            Route::get('courses/{course}/continue', [LmsProgressController::class, 'continueLearning'])->name('courses.continue');
            Route::post('courses/{course}/items/{item}/complete', [LmsProgressController::class, 'complete'])->name('courses.items.complete');
            Route::delete('courses/{course}/items/{item}/complete', [LmsProgressController::class, 'uncomplete'])->name('courses.items.uncomplete');
            Route::get('courses/{course}/announcements', [LmsAnnouncementController::class, 'index'])->name('courses.announcements.index');
        });

        // ---------------- Progress pribadi (§23) ----------------
        Route::middleware('lms.permission:lms.view')->group(function (): void {
            Route::get('my/courses', [LmsProgressController::class, 'myCourses'])->name('my.courses');
            Route::get('my/progress', [LmsProgressController::class, 'myProgress'])->name('my.progress');
        });

        // ---------------- Struktur course (§10–§14) ----------------
        Route::post('courses/{course}/sections', [LmsSectionController::class, 'store'])
            ->middleware('lms.permission:lms.content.create')->name('courses.sections.store');
        Route::post('courses/{course}/sections/reorder', [LmsSectionController::class, 'reorder'])
            ->middleware('lms.permission:lms.content.update')->name('courses.sections.reorder');
        Route::match(['put', 'patch'], 'sections/{section}', [LmsSectionController::class, 'update'])
            ->middleware('lms.permission:lms.content.update')->name('sections.update');
        Route::delete('sections/{section}', [LmsSectionController::class, 'destroy'])
            ->middleware('lms.permission:lms.content.delete')->name('sections.destroy');
        Route::post('sections/{section}/duplicate', [LmsSectionController::class, 'duplicate'])
            ->middleware('lms.permission:lms.content.create')->name('sections.duplicate');
        Route::post('sections/{section}/items', [LmsLearningItemController::class, 'store'])
            ->middleware('lms.permission:lms.content.create')->name('sections.items.store');
        Route::post('sections/{section}/items/reorder', [LmsLearningItemController::class, 'reorder'])
            ->middleware('lms.permission:lms.content.update')->name('sections.items.reorder');
        Route::match(['put', 'patch'], 'items/{item}', [LmsLearningItemController::class, 'update'])
            ->middleware('lms.permission:lms.content.update')->name('items.update');
        Route::delete('items/{item}', [LmsLearningItemController::class, 'destroy'])
            ->middleware('lms.permission:lms.content.delete')->name('items.destroy');
        Route::post('items/{item}/duplicate', [LmsLearningItemController::class, 'duplicate'])
            ->middleware('lms.permission:lms.content.create')->name('items.duplicate');
        Route::post('items/{item}/move', [LmsLearningItemController::class, 'move'])
            ->middleware('lms.permission:lms.content.update')->name('items.move');
        Route::post('items/{item}/upload', [LmsLearningItemController::class, 'upload'])
            ->middleware('lms.permission:lms.content.create')->name('items.upload');
        Route::put('items/{item}/chapters', [LmsLearningItemController::class, 'saveChapters'])
            ->middleware('lms.permission:lms.content.update')->name('items.chapters.save');

        // ---------------- Pengumuman (§24) ----------------
        Route::post('courses/{course}/announcements', [LmsAnnouncementController::class, 'store'])
            ->middleware('lms.permission:lms.announcement.manage')->name('courses.announcements.store');
        Route::match(['put', 'patch'], 'announcements/{announcement}', [LmsAnnouncementController::class, 'update'])
            ->middleware('lms.permission:lms.announcement.manage')->name('announcements.update');
        Route::post('announcements/{announcement}/pin', [LmsAnnouncementController::class, 'togglePin'])
            ->middleware('lms.permission:lms.announcement.manage')->name('announcements.pin');
        Route::delete('announcements/{announcement}', [LmsAnnouncementController::class, 'destroy'])
            ->middleware('lms.permission:lms.announcement.manage')->name('announcements.destroy');

        // ---------------- Enrollment (§15–§18) ----------------
        Route::post('courses/{course}/enroll', [LmsEnrollmentController::class, 'selfEnroll'])
            ->middleware('lms.permission:lms.view')->name('courses.enroll');
        Route::get('courses/{course}/enrollments', [LmsEnrollmentController::class, 'index'])
            ->middleware('lms.permission:lms.enrollment.view')->name('courses.enrollments.index');
        Route::post('courses/{course}/enrollments', [LmsEnrollmentController::class, 'store'])
            ->middleware('lms.permission:lms.enrollment.create')->name('courses.enrollments.store');
        Route::post('courses/{course}/enrollments/bulk', [LmsEnrollmentController::class, 'bulk'])
            ->middleware('lms.permission:lms.enrollment.create')->name('courses.enrollments.bulk');
        Route::get('courses/{course}/enrollments/candidates', [LmsEnrollmentController::class, 'candidates'])
            ->middleware('lms.permission:lms.enrollment.create')->name('courses.enrollments.candidates');
        Route::post('courses/{course}/enrollments/sync-academic', [LmsEnrollmentController::class, 'syncAcademic'])
            ->middleware('lms.permission:lms.enrollment.create')->name('courses.enrollments.sync');
        Route::match(['put', 'patch'], 'enrollments/{enrollment}', [LmsEnrollmentController::class, 'update'])
            ->middleware('lms.permission:lms.enrollment.update')->name('enrollments.update');
        Route::post('enrollments/{enrollment}/suspend', [LmsEnrollmentController::class, 'suspend'])
            ->middleware('lms.permission:lms.enrollment.update')->name('enrollments.suspend');
        Route::post('enrollments/{enrollment}/cancel', [LmsEnrollmentController::class, 'cancel'])
            ->middleware('lms.permission:lms.enrollment.update')->name('enrollments.cancel');
        Route::post('enrollments/{enrollment}/reactivate', [LmsEnrollmentController::class, 'reactivate'])
            ->middleware('lms.permission:lms.enrollment.update')->name('enrollments.reactivate');
        Route::post('enrollments/{enrollment}/complete', [LmsEnrollmentController::class, 'complete'])
            ->middleware('lms.permission:lms.enrollment.update')->name('enrollments.complete');
        Route::delete('enrollments/{enrollment}', [LmsEnrollmentController::class, 'destroy'])
            ->middleware('lms.permission:lms.enrollment.delete')->name('enrollments.destroy');

        // ---------------- Kelompok (§19) ----------------
        Route::get('courses/{course}/groups', [LmsGroupController::class, 'index'])
            ->middleware('lms.permission:lms.group.view,lms.enrollment.view')->name('courses.groups.index');
        Route::post('courses/{course}/groups', [LmsGroupController::class, 'store'])
            ->middleware('lms.permission:lms.group.manage')->name('courses.groups.store');
        Route::match(['put', 'patch'], 'groups/{group}', [LmsGroupController::class, 'update'])
            ->middleware('lms.permission:lms.group.manage')->name('groups.update');
        Route::delete('groups/{group}', [LmsGroupController::class, 'destroy'])
            ->middleware('lms.permission:lms.group.manage')->name('groups.destroy');
        Route::post('groups/{group}/members', [LmsGroupController::class, 'addMembers'])
            ->middleware('lms.permission:lms.group.manage')->name('groups.members.store');
        Route::delete('groups/{group}/members', [LmsGroupController::class, 'removeMembers'])
            ->middleware('lms.permission:lms.group.manage')->name('groups.members.destroy');

        // ---------------- Kalender (§25) ----------------
        Route::get('calendar', [LmsDashboardController::class, 'calendar'])
            ->middleware('lms.permission:lms.view')->name('calendar');

        // ---------------- Bookmark (§32) ----------------
        Route::get('bookmarks', [LmsBookmarkController::class, 'index'])
            ->middleware('lms.permission:lms.bookmark.manage,lms.view')->name('bookmarks.index');
        Route::post('courses/{course}/bookmark', [LmsBookmarkController::class, 'toggle'])
            ->middleware('lms.permission:lms.bookmark.manage,lms.view')->name('courses.bookmark');
        Route::delete('bookmarks/{bookmark}', [LmsBookmarkController::class, 'destroy'])
            ->middleware('lms.permission:lms.bookmark.manage,lms.view')->name('bookmarks.destroy');

        // ---------------- Pencarian (§31) ----------------
        Route::get('search', [LmsSearchController::class, 'index'])
            ->middleware('lms.permission:lms.view')->name('search');
        Route::get('search/suggestions', [LmsSearchController::class, 'suggestions'])
            ->middleware('lms.permission:lms.view')->name('search.suggestions');

        // ==================================================================
        // PHASE 2 — AKTIVITAS PEMBELAJARAN (§1–§53)
        // ==================================================================

        // ---------------- Aktivitas (§2, §42–§45) ----------------
        Route::get('activity-types', [LmsActivityController::class, 'types'])
            ->middleware('lms.permission:lms.activity.view')->name('activity-types');
        Route::get('courses/{course}/activities', [LmsActivityController::class, 'index'])
            ->middleware('lms.permission:lms.activity.view')->name('courses.activities.index');
        Route::post('sections/{section}/activities', [LmsActivityController::class, 'store'])
            ->middleware('lms.permission:lms.activity.create')->name('sections.activities.store');
        Route::get('activities/{activity}', [LmsActivityController::class, 'show'])
            ->middleware('lms.permission:lms.activity.view')->name('activities.show');
        Route::match(['put', 'patch'], 'activities/{activity}', [LmsActivityController::class, 'update'])
            ->middleware('lms.permission:lms.activity.update')->name('activities.update');
        Route::delete('activities/{activity}', [LmsActivityController::class, 'destroy'])
            ->middleware('lms.permission:lms.activity.delete')->name('activities.destroy');
        Route::post('activities/{activity}/publish', [LmsActivityController::class, 'publish'])
            ->middleware('lms.permission:lms.activity.publish')->name('activities.publish');
        Route::post('activities/{activity}/unpublish', [LmsActivityController::class, 'unpublish'])
            ->middleware('lms.permission:lms.activity.publish')->name('activities.unpublish');
        Route::post('activities/{activity}/archive', [LmsActivityController::class, 'archive'])
            ->middleware('lms.permission:lms.activity.update')->name('activities.archive');
        Route::post('activities/{activity}/duplicate', [LmsActivityController::class, 'duplicate'])
            ->middleware('lms.permission:lms.activity.create')->name('activities.duplicate');
        Route::get('activities/{activity}/preview', [LmsActivityController::class, 'preview'])
            ->middleware('lms.permission:lms.activity.preview,lms.activity.update')->name('activities.preview');

        // ---------------- Assignment (§3–§11) ----------------
        Route::get('activities/{activity}/assignment', [LmsAssignmentController::class, 'show'])
            ->middleware('lms.permission:lms.assignment.view')->name('assignments.show');
        Route::match(['put', 'patch'], 'activities/{activity}/assignment', [LmsAssignmentController::class, 'updateConfig'])
            ->middleware('lms.permission:lms.assignment.update')->name('assignments.update');
        Route::get('activities/{activity}/assignment/overview', [LmsAssignmentController::class, 'overview'])
            ->middleware('lms.permission:lms.assignment.grade')->name('assignments.overview');
        Route::get('activities/{activity}/assignment/statistics', [LmsAssignmentController::class, 'statistics'])
            ->middleware('lms.permission:lms.assignment.grade')->name('assignments.statistics');
        Route::post('activities/{activity}/assignment/bulk-grade', [LmsAssignmentController::class, 'bulkGrade'])
            ->middleware('lms.permission:lms.assignment.grade')->name('assignments.bulk-grade');
        Route::get('assignments/{assignment}', [LmsAssignmentController::class, 'showByAssignment'])
            ->middleware('lms.permission:lms.assignment.view')->name('assignments.by-model');
        Route::get('assignments/{assignment}/submissions', [LmsAssignmentController::class, 'submissionsByAssignment'])
            ->middleware('lms.permission:lms.submission.view')->name('assignments.submissions');
        Route::post('assignments/{assignment}/submit', [LmsAssignmentController::class, 'submitByAssignment'])
            ->middleware('lms.permission:lms.assignment.submit')->name('assignments.submit');

        // ---------------- Submission & penilaian (§5–§9) ----------------
        Route::get('activities/{activity}/submission-status', [LmsSubmissionController::class, 'status'])
            ->middleware('lms.permission:lms.submission.view')->name('submissions.status');
        Route::post('activities/{activity}/submissions/draft', [LmsSubmissionController::class, 'saveDraft'])
            ->middleware('lms.permission:lms.assignment.submit')->name('submissions.draft');
        Route::post('activities/{activity}/submissions/submit', [LmsSubmissionController::class, 'submit'])
            ->middleware('lms.permission:lms.assignment.submit')->name('submissions.submit');
        Route::get('activities/{activity}/submissions', [LmsSubmissionController::class, 'index'])
            ->middleware('lms.permission:lms.submission.view')->name('submissions.index');
        Route::get('submissions/{submission}', [LmsSubmissionController::class, 'show'])
            ->middleware('lms.permission:lms.submission.view')->name('submissions.show');
        Route::post('submissions/{submission}/files', [LmsSubmissionController::class, 'upload'])
            ->middleware('lms.permission:lms.submission.upload')->name('submissions.files.store');
        Route::delete('submission-files/{file}', [LmsSubmissionController::class, 'deleteFile'])
            ->middleware('lms.permission:lms.submission.upload')->name('submission-files.destroy');
        Route::get('submission-files/{file}', [LmsSubmissionController::class, 'download'])
            ->middleware('lms.permission:lms.submission.view')->name('submission-files.download');
        Route::post('submissions/{submission}/grade', [LmsSubmissionController::class, 'grade'])
            ->middleware('lms.permission:lms.submission.grade')->name('submissions.grade');
        Route::post('submissions/{submission}/return', [LmsSubmissionController::class, 'returnToStudent'])
            ->middleware('lms.permission:lms.submission.grade')->name('submissions.return');
        Route::post('submissions/{submission}/reject', [LmsSubmissionController::class, 'reject'])
            ->middleware('lms.permission:lms.submission.grade')->name('submissions.reject');

        // ---------------- Question bank & soal (§14–§22) ----------------
        Route::get('question-banks', [LmsQuestionBankController::class, 'index'])
            ->middleware('lms.permission:lms.question.view,lms.questionbank.manage')->name('question-banks.index');
        Route::post('question-banks', [LmsQuestionBankController::class, 'store'])
            ->middleware('lms.permission:lms.questionbank.manage')->name('question-banks.store');
        Route::get('question-banks/{bank}', [LmsQuestionBankController::class, 'show'])
            ->middleware('lms.permission:lms.question.view,lms.questionbank.manage')->name('question-banks.show');
        Route::match(['put', 'patch'], 'question-banks/{bank}', [LmsQuestionBankController::class, 'update'])
            ->middleware('lms.permission:lms.questionbank.manage')->name('question-banks.update');
        Route::delete('question-banks/{bank}', [LmsQuestionBankController::class, 'destroy'])
            ->middleware('lms.permission:lms.questionbank.manage')->name('question-banks.destroy');
        Route::post('question-banks/{bank}/categories', [LmsQuestionBankController::class, 'storeCategory'])
            ->middleware('lms.permission:lms.questionbank.manage')->name('question-banks.categories.store');
        Route::get('question-banks/{bank}/questions', [LmsQuestionBankController::class, 'questions'])
            ->middleware('lms.permission:lms.question.view,lms.questionbank.manage')->name('question-banks.questions');
        Route::match(['put', 'patch'], 'question-categories/{category}', [LmsQuestionBankController::class, 'updateCategory'])
            ->middleware('lms.permission:lms.questionbank.manage')->name('question-categories.update');
        Route::delete('question-categories/{category}', [LmsQuestionBankController::class, 'destroyCategory'])
            ->middleware('lms.permission:lms.questionbank.manage')->name('question-categories.destroy');

        Route::get('questions', [LmsQuestionController::class, 'index'])
            ->middleware('lms.permission:lms.question.view,lms.activity.view')->name('questions.index');
        Route::post('questions', [LmsQuestionController::class, 'store'])
            ->middleware('lms.permission:lms.question.create,lms.activity.create')->name('questions.store');
        Route::get('questions/{question}', [LmsQuestionController::class, 'show'])
            ->middleware('lms.permission:lms.question.view,lms.activity.view')->name('questions.show');
        Route::match(['put', 'patch'], 'questions/{question}', [LmsQuestionController::class, 'update'])
            ->middleware('lms.permission:lms.question.update,lms.activity.update')->name('questions.update');
        Route::delete('questions/{question}', [LmsQuestionController::class, 'destroy'])
            ->middleware('lms.permission:lms.question.delete,lms.activity.delete')->name('questions.destroy');
        Route::post('questions/{question}/duplicate', [LmsQuestionController::class, 'duplicate'])
            ->middleware('lms.permission:lms.question.create,lms.activity.create')->name('questions.duplicate');

        // ---------------- Quiz & percobaan (§12–§13, §23–§31) ----------------
        Route::get('activities/{activity}/quiz', [LmsQuizController::class, 'show'])
            ->middleware('lms.permission:lms.quiz.view')->name('quizzes.show');
        Route::get('activities/{activity}/quiz/builder', [LmsQuizController::class, 'builder'])
            ->middleware('lms.permission:lms.quiz.update,lms.quiz.create')->name('quizzes.builder');
        Route::match(['put', 'patch'], 'activities/{activity}/quiz', [LmsQuizController::class, 'updateConfig'])
            ->middleware('lms.permission:lms.quiz.update')->name('quizzes.update');
        Route::post('activities/{activity}/quiz/questions', [LmsQuizController::class, 'addQuestions'])
            ->middleware('lms.permission:lms.quiz.update')->name('quizzes.questions.store');
        Route::post('activities/{activity}/quiz/attempts', [LmsQuizAttemptController::class, 'store'])
            ->middleware('lms.permission:lms.quiz.attempt')->name('quizzes.attempts.store');
        Route::get('activities/{activity}/quiz/statistics', [LmsQuizController::class, 'statistics'])
            ->middleware('lms.permission:lms.quiz.grade,lms.quiz.update')->name('quizzes.statistics');
        Route::get('activities/{activity}/quiz/attempts', [LmsQuizController::class, 'attempts'])
            ->middleware('lms.permission:lms.quiz.grade,lms.quiz.update')->name('quizzes.attempts.index');
        Route::get('activities/{activity}/quiz/student-attempts/{user_id}', [LmsQuizAttemptController::class, 'forStudent'])
            ->middleware('lms.permission:lms.quiz.view')->name('quizzes.attempts.student');
        Route::get('quizzes/{quiz}', [LmsQuizController::class, 'showByQuiz'])
            ->middleware('lms.permission:lms.quiz.view')->name('quizzes.by-model');
        Route::post('quizzes/{quiz}/attempts', [LmsQuizController::class, 'startAttempt'])
            ->middleware('lms.permission:lms.quiz.attempt')->name('quizzes.attempts.start');
        Route::get('quizzes/{quiz}/statistics', [LmsQuizController::class, 'statisticsByQuiz'])
            ->middleware('lms.permission:lms.quiz.grade,lms.quiz.update')->name('quizzes.statistics.by-model');

        Route::get('quiz-slots/{slot}/remove', [LmsQuizController::class, 'removeQuestion'])
            ->middleware('lms.permission:lms.quiz.update')->name('quiz-slots.remove.get');
        Route::delete('quiz-slots/{slot}', [LmsQuizController::class, 'removeQuestion'])
            ->middleware('lms.permission:lms.quiz.update')->name('quiz-slots.remove');
        Route::match(['put', 'patch'], 'quiz-slots/{slot}', [LmsQuizController::class, 'updateSlot'])
            ->middleware('lms.permission:lms.quiz.update')->name('quiz-slots.update');

        Route::get('attempts/{attempt}', [LmsQuizAttemptController::class, 'show'])
            ->middleware('lms.permission:lms.quiz.view')->name('attempts.show');
        Route::post('attempts/{attempt}/answers', [LmsQuizAttemptController::class, 'saveAnswer'])
            ->middleware('lms.permission:lms.quiz.attempt')->name('attempts.answers.store');
        Route::post('attempts/{attempt}/submit', [LmsQuizAttemptController::class, 'submit'])
            ->middleware('lms.permission:lms.quiz.attempt')->name('attempts.submit');
        Route::get('attempts/{attempt}/result', [LmsQuizAttemptController::class, 'result'])
            ->middleware('lms.permission:lms.quiz.view')->name('attempts.result');
        Route::get('attempts/{attempt}/detail', [LmsQuizController::class, 'attemptDetail'])
            ->middleware('lms.permission:lms.quiz.grade,lms.quiz.update')->name('attempts.detail');
        Route::post('quiz-answers/{answer}/grade', [LmsQuizAttemptController::class, 'gradeAnswer'])
            ->middleware('lms.permission:lms.quiz.grade')->name('quiz-answers.grade');

        // ---------------- Forum (§32–§36) ----------------
        Route::get('activities/{activity}/forum', [LmsForumController::class, 'show'])
            ->middleware('lms.permission:lms.forum.view')->name('forums.show');
        Route::post('activities/{activity}/forum/discussions', [LmsForumController::class, 'storeDiscussion'])
            ->middleware('lms.permission:lms.forum.reply')->name('forums.discussions.store');
        Route::match(['put', 'patch'], 'activities/{activity}/forum', [LmsForumController::class, 'updateConfig'])
            ->middleware('lms.permission:lms.forum.create')->name('forums.update');
        Route::get('activities/{activity}/forum/moderation', [LmsForumController::class, 'moderationQueue'])
            ->middleware('lms.permission:lms.forum.moderate')->name('forums.moderation');
        Route::get('activities/{activity}/forum/statistics', [LmsForumController::class, 'statistics'])
            ->middleware('lms.permission:lms.forum.moderate')->name('forums.statistics');
        Route::post('activities/{activity}/forum/subscribe', [LmsForumController::class, 'subscribe'])
            ->middleware('lms.permission:lms.forum.view')->name('forums.subscribe');
        Route::delete('activities/{activity}/forum/subscribe', [LmsForumController::class, 'unsubscribe'])
            ->middleware('lms.permission:lms.forum.view')->name('forums.unsubscribe');
        Route::get('forums/{forum}', [LmsForumController::class, 'showByForum'])
            ->middleware('lms.permission:lms.forum.view')->name('forums.by-model');
        Route::post('forums/{forum}/discussions', [LmsForumController::class, 'storeDiscussionByForum'])
            ->middleware('lms.permission:lms.forum.reply')->name('forums.discussions.by-model');
        Route::get('discussions/{discussion}', [LmsForumController::class, 'showDiscussion'])
            ->middleware('lms.permission:lms.forum.view')->name('discussions.show');
        Route::post('discussions/{discussion}/posts', [LmsForumController::class, 'storePost'])
            ->middleware('lms.permission:lms.forum.reply')->name('discussions.posts.store');
        Route::match(['put', 'patch'], 'forum-posts/{post}', [LmsForumController::class, 'updatePost'])
            ->middleware('lms.permission:lms.forum.reply')->name('forum-posts.update');
        Route::delete('forum-posts/{post}', [LmsForumController::class, 'destroyPost'])
            ->middleware('lms.permission:lms.forum.reply')->name('forum-posts.destroy');
        Route::post('discussions/{discussion}/pin', [LmsForumController::class, 'pin'])
            ->middleware('lms.permission:lms.forum.moderate')->name('discussions.pin');
        Route::post('discussions/{discussion}/lock', [LmsForumController::class, 'lock'])
            ->middleware('lms.permission:lms.forum.moderate')->name('discussions.lock');
        Route::post('forum-moderation/{type}/{id}/approve', [LmsForumController::class, 'approve'])
            ->middleware('lms.permission:lms.forum.moderate')->name('forums.moderate.approve');
        Route::post('forum-moderation/{type}/{id}/hide', [LmsForumController::class, 'hide'])
            ->middleware('lms.permission:lms.forum.moderate')->name('forums.moderate.hide');
        Route::delete('forum-moderation/{type}/{id}', [LmsForumController::class, 'destroyModerated'])
            ->middleware('lms.permission:lms.forum.moderate')->name('forums.moderate.destroy');

        // ---------------- Lesson (§37) ----------------
        Route::get('activities/{activity}/lesson', [LmsLessonController::class, 'show'])
            ->middleware('lms.permission:lms.lesson.view')->name('lessons.show');
        Route::post('activities/{activity}/lesson/progress', [LmsLessonController::class, 'progress'])
            ->middleware('lms.permission:lms.lesson.view')->name('lessons.progress');
        Route::get('activities/{activity}/lesson/builder', [LmsLessonController::class, 'builder'])
            ->middleware('lms.permission:lms.lesson.update,lms.lesson.create')->name('lessons.builder');
        Route::match(['put', 'patch'], 'activities/{activity}/lesson', [LmsLessonController::class, 'updateConfig'])
            ->middleware('lms.permission:lms.lesson.update')->name('lessons.update');
        Route::post('activities/{activity}/lesson/pages', [LmsLessonController::class, 'storePage'])
            ->middleware('lms.permission:lms.lesson.create')->name('lessons.pages.store');
        Route::match(['put', 'patch'], 'lesson-pages/{page}', [LmsLessonController::class, 'updatePage'])
            ->middleware('lms.permission:lms.lesson.update')->name('lesson-pages.update');
        Route::delete('lesson-pages/{page}', [LmsLessonController::class, 'destroyPage'])
            ->middleware('lms.permission:lms.lesson.update')->name('lesson-pages.destroy');
        Route::get('activities/{activity}/lesson/statistics', [LmsLessonController::class, 'statistics'])
            ->middleware('lms.permission:lms.lesson.update,lms.lesson.create')->name('lessons.statistics');
        Route::get('lessons/{lesson}', [LmsLessonController::class, 'showByLesson'])
            ->middleware('lms.permission:lms.lesson.view')->name('lessons.by-model');
        Route::post('lessons/{lesson}/progress', [LmsLessonController::class, 'progressByLesson'])
            ->middleware('lms.permission:lms.lesson.view')->name('lessons.progress.by-model');

        // ---------------- Choice (§38) ----------------
        Route::get('activities/{activity}/choice', [LmsChoiceController::class, 'show'])
            ->middleware('lms.permission:lms.choice.view')->name('choices.show');
        Route::match(['put', 'patch'], 'activities/{activity}/choice', [LmsChoiceController::class, 'updateConfig'])
            ->middleware('lms.permission:lms.choice.create')->name('choices.update');
        Route::post('activities/{activity}/choice/respond', [LmsChoiceController::class, 'respond'])
            ->middleware('lms.permission:lms.choice.respond')->name('choices.respond');
        Route::get('activities/{activity}/choice/results', [LmsChoiceController::class, 'results'])
            ->middleware('lms.permission:lms.choice.create')->name('choices.results');
        Route::get('choices/{choice}', [LmsChoiceController::class, 'showByChoice'])
            ->middleware('lms.permission:lms.choice.view')->name('choices.by-model');
        Route::post('choices/{choice}/respond', [LmsChoiceController::class, 'respondByChoice'])
            ->middleware('lms.permission:lms.choice.respond')->name('choices.respond.by-model');

        // ---------------- Survey (§39) ----------------
        Route::get('activities/{activity}/survey', [LmsSurveyController::class, 'show'])
            ->middleware('lms.permission:lms.survey.view')->name('surveys.show');
        Route::match(['put', 'patch'], 'activities/{activity}/survey', [LmsSurveyController::class, 'updateConfig'])
            ->middleware('lms.permission:lms.survey.create')->name('surveys.update');
        Route::post('activities/{activity}/survey/submit', [LmsSurveyController::class, 'submit'])
            ->middleware('lms.permission:lms.survey.respond')->name('surveys.submit');
        Route::get('activities/{activity}/survey/results', [LmsSurveyController::class, 'results'])
            ->middleware('lms.permission:lms.survey.create')->name('surveys.results');
        Route::delete('survey-questions/{question}', [LmsSurveyController::class, 'destroyQuestion'])
            ->middleware('lms.permission:lms.survey.create')->name('survey-questions.destroy');
        Route::get('surveys/{survey}', [LmsSurveyController::class, 'showBySurvey'])
            ->middleware('lms.permission:lms.survey.view')->name('surveys.by-model');
        Route::post('surveys/{survey}/submit', [LmsSurveyController::class, 'submitBySurvey'])
            ->middleware('lms.permission:lms.survey.respond')->name('surveys.submit.by-model');

        // ---------------- Nilai aktivitas (§46–§47) ----------------
        Route::get('courses/{course}/grades', [LmsGradeController::class, 'courseGrades'])
            ->middleware('lms.permission:lms.grade.view')->name('courses.grades');
        Route::get('activities/{activity}/grades', [LmsGradeController::class, 'activityGrades'])
            ->middleware('lms.permission:lms.grade.view')->name('activities.grades');
        Route::get('activities/{activity}/grade', [LmsGradeController::class, 'activitySummary'])
            ->middleware('lms.permission:lms.grade.view')->name('activities.grade');
        Route::post('activities/{activity}/grades', [LmsGradeController::class, 'store'])
            ->middleware('lms.permission:lms.grade.manage')->name('activities.grades.store');
        Route::post('activities/{activity}/grades/push', [LmsGradeController::class, 'push'])
            ->middleware('lms.permission:lms.grade.manage')->name('activities.grades.push');
        Route::delete('activity-grades/{grade}', [LmsGradeController::class, 'destroy'])
            ->middleware('lms.permission:lms.grade.manage')->name('activity-grades.destroy');

        // ---------------- Pengaturan & integrasi (§35) ----------------
        Route::get('settings', [LmsSettingsController::class, 'show'])
            ->middleware('lms.permission:lms.settings.manage')->name('settings.show');
        Route::match(['put', 'patch'], 'settings', [LmsSettingsController::class, 'update'])
            ->middleware('lms.permission:lms.settings.manage')->name('settings.update');

        /*
        |------------------------------------------------------------------
        | Phase 3 — Gradebook, Assessment, Rubric, Outcome & Competency
        |------------------------------------------------------------------
        |
        | Aktivitas Phase 2 tetap menjadi sumber evidence; nilai akhir
        | dihitung gradebook. SIAKAD tidak pernah ditulis langsung dari sini —
        | hanya lewat `grades/sync` yang berizin.
        |
        */

        // Gradebook: konfigurasi, kategori, komponen, tabel nilai, ekspor.
        Route::get('courses/{course}/gradebook', [LmsGradebookController::class, 'show'])
            ->middleware('lms.permission:lms.gradebook.view,lms.grade.view')->name('gradebook.show');
        Route::put('courses/{course}/gradebook', [LmsGradebookController::class, 'update'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('gradebook.update');
        Route::post('courses/{course}/gradebook/freeze', [LmsGradebookController::class, 'freeze'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('gradebook.freeze');
        Route::post('courses/{course}/gradebook/unfreeze', [LmsGradebookController::class, 'unfreeze'])
            ->middleware('lms.permission:lms.grade.lock,lms.gradebook.manage')->name('gradebook.unfreeze');
        Route::get('courses/{course}/gradebook/sheet', [LmsGradebookController::class, 'sheet'])
            ->middleware('lms.permission:lms.gradebook.view,lms.grade.view')->name('gradebook.sheet');
        Route::post('courses/{course}/gradebook/calculate', [LmsGradebookController::class, 'calculate'])
            ->middleware('lms.permission:lms.gradebook.view,lms.grade.view')->name('gradebook.calculate');
        Route::get('courses/{course}/gradebook/statistics', [LmsGradebookController::class, 'statistics'])
            ->middleware('lms.permission:lms.gradebook.view,lms.report.view')->name('gradebook.statistics');
        Route::get('courses/{course}/gradebook/export', [LmsGradebookController::class, 'export'])
            ->middleware('lms.permission:lms.report.view,lms.gradebook.view')->name('gradebook.export');
        Route::post('courses/{course}/gradebook/sync-items', [LmsGradebookController::class, 'syncItems'])
            ->middleware('lms.permission:lms.gradebook.manage,lms.grade.edit')->name('gradebook.sync-items');
        Route::get('courses/{course}/gradebook/items', [LmsGradebookController::class, 'items'])
            ->middleware('lms.permission:lms.gradebook.view,lms.grade.view')->name('gradebook.items');
        Route::post('courses/{course}/gradebook/items', [LmsGradebookController::class, 'storeItem'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('gradebook.items.store');
        Route::post('courses/{course}/gradebook/categories', [LmsGradebookController::class, 'storeCategory'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('gradebook.categories.store');
        Route::put('grade-categories/{category}', [LmsGradebookController::class, 'updateCategory'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('grade-categories.update');
        Route::delete('grade-categories/{category}', [LmsGradebookController::class, 'deleteCategory'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('grade-categories.delete');
        Route::put('grade-items/{item}', [LmsGradebookController::class, 'updateItem'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('grade-items.update');
        Route::delete('grade-items/{item}', [LmsGradebookController::class, 'deleteItem'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('grade-items.delete');
        Route::get('courses/{course}/grade-scales', [LmsGradebookController::class, 'scales'])
            ->middleware('lms.permission:lms.grade.view,lms.gradebook.view')->name('grade-scales.index');
        Route::post('grade-scales', [LmsGradebookController::class, 'storeScale'])
            ->middleware('lms.permission:lms.gradebook.manage')->name('grade-scales.store');

        // Nilai per komponen (§34) — daftar, simpan, koreksi, bebas, kembalikan.
        Route::get('grade-items/{item}/grades', [LmsAssessmentResultController::class, 'index'])
            ->middleware('lms.permission:lms.grade.view,lms.gradebook.view')->name('grade-items.grades.index');
        Route::put('grade-items/{item}/grades/{student}', [LmsAssessmentResultController::class, 'update'])
            ->middleware('lms.permission:lms.grade.edit')->name('grade-items.grades.update');
        Route::post('grade-items/{item}/grades/bulk', [LmsAssessmentResultController::class, 'bulk'])
            ->middleware('lms.permission:lms.grade.edit')->name('grade-items.grades.bulk');
        Route::post('grade-items/{item}/grades/{student}/exempt', [LmsAssessmentResultController::class, 'exempt'])
            ->middleware('lms.permission:lms.grade.edit')->name('grade-items.grades.exempt');
        Route::delete('grade-items/{item}/grades/{student}/exempt', [LmsAssessmentResultController::class, 'unexempt'])
            ->middleware('lms.permission:lms.grade.edit')->name('grade-items.grades.unexempt');
        Route::post('grade-items/{item}/grades/return', [LmsAssessmentResultController::class, 'return'])
            ->middleware('lms.permission:lms.grade.release')->name('grade-items.grades.return');
        Route::post('assessment-results/{result}/correct', [LmsAssessmentResultController::class, 'correct'])
            ->middleware('lms.permission:lms.grade.correct')->name('assessment-results.correct');
        Route::get('assessment-results/{result}/history', [LmsAssessmentResultController::class, 'history'])
            ->middleware('lms.permission:lms.grade.view,lms.gradebook.view')->name('assessment-results.history');

        // Rilis & kunci nilai (§16–§18) + sinkronisasi akademik (§41).
        Route::post('courses/{course}/gradebook/release', [LmsGradeReleaseController::class, 'release'])
            ->middleware('lms.permission:lms.grade.release')->name('gradebook.release');
        Route::get('courses/{course}/gradebook/releases', [LmsGradeReleaseController::class, 'index'])
            ->middleware('lms.permission:lms.gradebook.view,lms.grade.view')->name('gradebook.releases');
        Route::post('grade-releases/{release}/revoke', [LmsGradeReleaseController::class, 'revoke'])
            ->middleware('lms.permission:lms.grade.release')->name('grade-releases.revoke');
        Route::post('grade-releases/{release}/lock', [LmsGradeReleaseController::class, 'lock'])
            ->middleware('lms.permission:lms.grade.lock')->name('grade-releases.lock');
        Route::post('grade-releases/{release}/unlock', [LmsGradeReleaseController::class, 'unlock'])
            ->middleware('lms.permission:lms.grade.correct')->name('grade-releases.unlock');
        Route::post('courses/{course}/grades/sync', [LmsGradeReleaseController::class, 'sync'])
            ->middleware('lms.permission:lms.grade.sync')->name('grades.sync');
        Route::get('integration/academic', [LmsGradeReleaseController::class, 'integration'])
            ->name('integration.academic');
        Route::get('courses/{course}/grades/payload/{student}', [LmsGradeReleaseController::class, 'payload'])
            ->middleware('lms.permission:lms.gradebook.view,lms.grade.view')->name('grades.payload');

        // Tampilan nilai: siswa (§32), wali (§33), performa belajar (§47).
        Route::get('my/grades', [LmsGradeViewController::class, 'myGrades'])->name('my.grades');
        Route::get('my/performance', [LmsGradeViewController::class, 'myPerformance'])->name('my.performance');
        Route::get('my/outcomes', [LmsGradeViewController::class, 'myOutcomes'])->name('my.outcomes');
        Route::get('my/competencies', [LmsGradeViewController::class, 'myCompetencies'])->name('my.competencies');
        Route::get('courses/{course}/grades', [LmsGradeViewController::class, 'courseGrades'])
            ->middleware('lms.permission:lms.grade.view')->name('courses.grades');
        Route::get('children/{child}/grades', [LmsGradeViewController::class, 'childGrades'])
            ->middleware('lms.permission:lms.grade.view_child,lms.grade.view_child')->name('children.grades');

        // Rubric (§22–§25, §58).
        Route::get('courses/{course}/rubrics', [LmsRubricController::class, 'index'])
            ->middleware('lms.permission:lms.rubric.create,lms.course.view,lms.content.view')->name('rubrics.index');
        Route::get('rubric-templates', [LmsRubricController::class, 'templates'])
            ->middleware('lms.permission:lms.rubric.create,lms.course.view')->name('rubrics.templates');
        Route::post('rubrics', [LmsRubricController::class, 'store'])
            ->middleware('lms.permission:lms.rubric.create')->name('rubrics.store');
        Route::put('rubrics/{rubric}', [LmsRubricController::class, 'update'])
            ->middleware('lms.permission:lms.rubric.manage')->name('rubrics.update');
        Route::post('rubrics/{rubric}/archive', [LmsRubricController::class, 'archive'])
            ->middleware('lms.permission:lms.rubric.manage')->name('rubrics.archive');
        Route::post('rubrics/{rubric}/duplicate', [LmsRubricController::class, 'duplicate'])
            ->middleware('lms.permission:lms.rubric.create')->name('rubrics.duplicate');
        Route::delete('rubrics/{rubric}', [LmsRubricController::class, 'destroy'])
            ->middleware('lms.permission:lms.rubric.manage')->name('rubrics.destroy');
        Route::put('grade-items/{item}/rubric', [LmsRubricController::class, 'attach'])
            ->middleware('lms.permission:lms.gradebook.manage,lms.rubric.manage')->name('grade-items.rubric.attach');
        Route::get('grade-items/{item}/rubric/{student}', [LmsRubricController::class, 'studentAssessment'])
            ->middleware('lms.permission:lms.grade.view,lms.rubric.assess')->name('grade-items.rubric.show');
        Route::post('grade-items/{item}/rubric/{student}', [LmsRubricController::class, 'assess'])
            ->middleware('lms.permission:lms.rubric.assess,lms.grade.edit')->name('grade-items.rubric.assess');

        // Learning outcome (§26–§28).
        Route::get('courses/{course}/outcomes', [LmsOutcomeController::class, 'index'])
            ->middleware('lms.permission:lms.outcome.manage,lms.course.view,lms.content.view')->name('outcomes.index');
        Route::post('courses/{course}/outcomes', [LmsOutcomeController::class, 'store'])
            ->middleware('lms.permission:lms.outcome.manage')->name('outcomes.store');
        Route::put('outcomes/{outcome}', [LmsOutcomeController::class, 'update'])
            ->middleware('lms.permission:lms.outcome.manage')->name('outcomes.update');
        Route::delete('outcomes/{outcome}', [LmsOutcomeController::class, 'destroy'])
            ->middleware('lms.permission:lms.outcome.manage')->name('outcomes.destroy');
        Route::post('outcomes/{outcome}/map', [LmsOutcomeController::class, 'map'])
            ->middleware('lms.permission:lms.outcome.manage')->name('outcomes.map');
        Route::post('courses/{course}/outcomes/recalculate', [LmsOutcomeController::class, 'recalculate'])
            ->middleware('lms.permission:lms.outcome.assess,lms.outcome.manage')->name('outcomes.recalculate');
        Route::get('courses/{course}/outcomes/students/{student}', [LmsOutcomeController::class, 'studentOutcomes'])
            ->middleware('lms.permission:lms.outcome.manage,lms.grade.view')->name('outcomes.student');

        // Competency (§29–§31).
        Route::get('competency-frameworks', [LmsCompetencyController::class, 'frameworks'])
            ->middleware('lms.permission:lms.competency.manage,lms.course.view')->name('competencies.frameworks');
        Route::post('competency-frameworks', [LmsCompetencyController::class, 'storeFramework'])
            ->middleware('lms.permission:lms.competency.manage')->name('competencies.frameworks.store');
        Route::post('competency-frameworks/{framework}/competencies', [LmsCompetencyController::class, 'storeCompetency'])
            ->middleware('lms.permission:lms.competency.manage')->name('competencies.store');
        Route::put('competencies/{competency}', [LmsCompetencyController::class, 'updateCompetency'])
            ->middleware('lms.permission:lms.competency.manage')->name('competencies.update');
        Route::get('courses/{course}/competencies', [LmsCompetencyController::class, 'courseCompetencies'])
            ->middleware('lms.permission:lms.competency.manage,lms.course.view,lms.content.view')->name('competencies.course');
        Route::post('courses/{course}/competencies/map', [LmsCompetencyController::class, 'mapCourse'])
            ->middleware('lms.permission:lms.competency.manage')->name('competencies.course.map');
        Route::post('activities/{activity}/competencies/map', [LmsCompetencyController::class, 'mapActivity'])
            ->middleware('lms.permission:lms.competency.manage')->name('competencies.activity.map');
        Route::post('competencies/{competency}/students/{student}', [LmsCompetencyController::class, 'assess'])
            ->middleware('lms.permission:lms.competency.assess')->name('competencies.assess');
        Route::post('competencies/{competency}/students/{student}/evidence', [LmsCompetencyController::class, 'evidence'])
            ->middleware('lms.permission:lms.competency.assess')->name('competencies.evidence');
        Route::get('courses/{course}/competencies/students/{student}', [LmsCompetencyController::class, 'studentCompetencies'])
            ->middleware('lms.permission:lms.competency.manage,lms.grade.view')->name('competencies.student');

        // Banding nilai (§20, §59).
        Route::get('grade-reviews', [LmsGradeReviewController::class, 'index'])->name('grade-reviews.index');
        Route::post('grade-items/{item}/grade-reviews', [LmsGradeReviewController::class, 'store'])->name('grade-reviews.store');
        Route::get('grade-reviews/{review}', [LmsGradeReviewController::class, 'show'])->name('grade-reviews.show');
        Route::post('grade-reviews/{review}/start', [LmsGradeReviewController::class, 'start'])
            ->middleware('lms.permission:lms.grade.review')->name('grade-reviews.start');
        Route::post('grade-reviews/{review}/approve', [LmsGradeReviewController::class, 'approve'])
            ->middleware('lms.permission:lms.grade.approve')->name('grade-reviews.approve');
        Route::post('grade-reviews/{review}/reject', [LmsGradeReviewController::class, 'reject'])
            ->middleware('lms.permission:lms.grade.approve')->name('grade-reviews.reject');
        Route::post('grade-reviews/{review}/close', [LmsGradeReviewController::class, 'close'])
            ->middleware('lms.permission:lms.grade.review')->name('grade-reviews.close');
        Route::post('grade-reviews/{review}/comments', [LmsGradeReviewController::class, 'comment'])->name('grade-reviews.comment');

    });
});
