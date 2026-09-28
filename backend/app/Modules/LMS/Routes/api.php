<?php

use App\Modules\LMS\Http\Controllers\LmsAnnouncementController;
use App\Modules\LMS\Http\Controllers\LmsAuthController;
use App\Modules\LMS\Http\Controllers\LmsBookmarkController;
use App\Modules\LMS\Http\Controllers\LmsCategoryController;
use App\Modules\LMS\Http\Controllers\LmsCourseController;
use App\Modules\LMS\Http\Controllers\LmsDashboardController;
use App\Modules\LMS\Http\Controllers\LmsEnrollmentController;
use App\Modules\LMS\Http\Controllers\LmsGroupController;
use App\Modules\LMS\Http\Controllers\LmsLearningController;
use App\Modules\LMS\Http\Controllers\LmsLearningItemController;
use App\Modules\LMS\Http\Controllers\LmsProgressController;
use App\Modules\LMS\Http\Controllers\LmsPublicController;
use App\Modules\LMS\Http\Controllers\LmsSearchController;
use App\Modules\LMS\Http\Controllers\LmsSectionController;
use App\Modules\LMS\Http\Controllers\LmsSettingsController;
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

        // ---------------- Pengaturan & integrasi (§35) ----------------
        Route::get('settings', [LmsSettingsController::class, 'show'])
            ->middleware('lms.permission:lms.settings.manage')->name('settings.show');
        Route::match(['put', 'patch'], 'settings', [LmsSettingsController::class, 'update'])
            ->middleware('lms.permission:lms.settings.manage')->name('settings.update');
    });
});
