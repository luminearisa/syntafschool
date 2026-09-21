<?php

use App\Http\Controllers\Api\Ppdb\PpdbAnnouncementController;
use App\Http\Controllers\Api\Ppdb\PpdbApplicantController;
use App\Http\Controllers\Api\Ppdb\PpdbApplicantDocumentController;
use App\Http\Controllers\Api\Ppdb\PpdbApplicantConversionController;
use App\Http\Controllers\Api\Ppdb\PpdbAppealController;
use App\Http\Controllers\Api\Ppdb\PpdbDashboardController;
use App\Http\Controllers\Api\Ppdb\PpdbDocumentRequirementController;
use App\Http\Controllers\Api\Ppdb\PpdbFeeController;
use App\Http\Controllers\Api\Ppdb\PpdbFormFieldController;
use App\Http\Controllers\Api\Ppdb\PpdbInvoiceController;
use App\Http\Controllers\Api\Ppdb\PpdbLookupController;
use App\Http\Controllers\Api\Ppdb\PpdbMidtransController;
use App\Http\Controllers\Api\Ppdb\PpdbNotificationController;
use App\Http\Controllers\Api\Ppdb\PpdbParentAccountController;
use App\Http\Controllers\Api\Ppdb\PpdbParentAuthController;
use App\Http\Controllers\Api\Ppdb\PpdbPaymentController;
use App\Http\Controllers\Api\Ppdb\PpdbPeriodController;
use App\Http\Controllers\Api\Ppdb\PpdbPortalController;
use App\Http\Controllers\Api\Ppdb\PpdbProgramController;
use App\Http\Controllers\Api\Ppdb\PpdbPublicController;
use App\Http\Controllers\Api\Ppdb\PpdbQuestionController;
use App\Http\Controllers\Api\Ppdb\PpdbReRegistrationController;
use App\Http\Controllers\Api\Ppdb\PpdbRegistrationController;
use App\Http\Controllers\Api\Ppdb\PpdbReportController;
use App\Http\Controllers\Api\Ppdb\PpdbSelectionController;
use App\Http\Controllers\Api\Ppdb\PpdbWaveController;
use Illuminate\Support\Facades\Route;

/*
 * PPDB — Penerimaan Peserta Didik Baru.
 *
 * Dimuat otomatis oleh `routes/api.php` di dalam grup `auth:sanctum` + prefix
 * `v1`; rute publik menonaktifkan middleware auth secara eksplisit.
 *
 * Tiga area:
 * 1. PUBLIK   — informasi PPDB, pendaftaran akun orang tua, callback Midtrans.
 * 2. PORTAL   — area orang tua (login, TANPA permission modul; data dibatasi
 *               per akun sehingga satu orang tua tidak dapat melihat data
 *               orang tua lain).
 * 3. ADMIN    — konfigurasi, pendaftaran, pembayaran, seleksi, laporan
 *               (permission granular `ppdb.*`).
 *
 * Struktur data dipertahankan: User → Parent/Guardian → Applicant → Registration.
 */
Route::prefix('ppdb')->group(function (): void {
    // ================= PUBLIK =================
    Route::get('public', [PpdbPublicController::class, 'index'])
        ->withoutMiddleware(['auth:sanctum'])->name('ppdb.public.index');
    Route::get('public/{slug}', [PpdbPublicController::class, 'show'])
        ->withoutMiddleware(['auth:sanctum'])->name('ppdb.public.show');

    Route::post('auth/register', [PpdbParentAuthController::class, 'register'])
        ->withoutMiddleware(['auth:sanctum'])->middleware('throttle:10,1')
        ->name('ppdb.auth.register');

    // Callback penyedia pembayaran (dipanggil server Midtrans, bukan pengguna).
    // Satu-satunya penjaga pintu adalah tanda tangan SHA-512, jadi dibatasi agar
    // tebakan tanda tangan dari pihak anonim tidak bisa digencarkan.
    Route::post('payments/midtrans/callback', [PpdbMidtransController::class, 'callback'])
        ->withoutMiddleware(['auth:sanctum'])->middleware('throttle:60,1')
        ->name('ppdb.payments.midtrans.callback');

    // ================= PORTAL ORANG TUA =================
    Route::middleware('auth:sanctum')->prefix('portal')->group(function (): void {
        Route::get('dashboard', [PpdbPortalController::class, 'dashboard'])->name('ppdb.portal.dashboard');
        Route::get('profile', [PpdbPortalController::class, 'profile'])->name('ppdb.portal.profile');
        Route::put('profile', [PpdbPortalController::class, 'updateProfile'])->name('ppdb.portal.profile.update');

        Route::get('applicants', [PpdbApplicantController::class, 'index'])->name('ppdb.portal.applicants.index');
        Route::post('applicants', [PpdbApplicantController::class, 'store'])->name('ppdb.portal.applicants.store');
        Route::get('applicants/{applicant}', [PpdbApplicantController::class, 'show'])->name('ppdb.portal.applicants.show');
        Route::put('applicants/{applicant}', [PpdbApplicantController::class, 'update'])->name('ppdb.portal.applicants.update');

        Route::get('registrations', [PpdbRegistrationController::class, 'index'])->name('ppdb.portal.registrations.index');
        Route::post('registrations', [PpdbRegistrationController::class, 'store'])->name('ppdb.portal.registrations.store');
        Route::get('registrations/{registration}', [PpdbRegistrationController::class, 'show'])->name('ppdb.portal.registrations.show');
        Route::put('registrations/{registration}', [PpdbRegistrationController::class, 'update'])->name('ppdb.portal.registrations.update');
        Route::post('registrations/{registration}/submit', [PpdbRegistrationController::class, 'submit'])->name('ppdb.portal.registrations.submit');

        Route::get('registrations/{registration}/documents', [PpdbApplicantDocumentController::class, 'index'])->name('ppdb.portal.documents.index');
        Route::post('registrations/{registration}/documents', [PpdbApplicantDocumentController::class, 'store'])->name('ppdb.portal.documents.store');

        Route::get('registrations/{registration}/payment', [PpdbInvoiceController::class, 'forRegistration'])->name('ppdb.portal.payment.show');
        Route::post('invoices/{invoice}/pay', [PpdbPaymentController::class, 'store'])->name('ppdb.portal.payment.store');

        Route::get('registrations/{registration}/selection', [PpdbSelectionController::class, 'forRegistration'])->name('ppdb.portal.selection.show');

        Route::get('registrations/{registration}/appeals', [PpdbAppealController::class, 'forRegistration'])->name('ppdb.portal.appeals.index');
        Route::post('registrations/{registration}/appeals', [PpdbAppealController::class, 'store'])->name('ppdb.portal.appeals.store');

        Route::get('notifications', [PpdbNotificationController::class, 'index'])->name('ppdb.portal.notifications.index');
        Route::get('notifications/unread-count', [PpdbNotificationController::class, 'unreadCount'])->name('ppdb.portal.notifications.unread-count');
        Route::post('notifications/read-all', [PpdbNotificationController::class, 'readAll'])->name('ppdb.portal.notifications.read-all');
        Route::post('notifications/{notification}/read', [PpdbNotificationController::class, 'markAsRead'])->name('ppdb.portal.notifications.read');
    });

    // ================= ADMIN — OPSI REFERENSI =================
    // Dropdown panitia terisi dari sini, bukan dari `/master/*`: panitia
    // penerimaan memegang `ppdb.*` saja dan tidak boleh membaca seluruh master
    // data sekolah hanya untuk mengisi pilihan jenis kelamin atau tahun ajaran.
    Route::get('lookups', [PpdbLookupController::class, 'index'])
        ->middleware('permission:ppdb.view')->name('ppdb.lookups');

    // ================= ADMIN — DASHBOARD =================
    Route::get('dashboard', [PpdbDashboardController::class, 'admin'])
        ->middleware('permission:ppdb.view')->name('ppdb.dashboard.admin');

    // ================= ADMIN — KONFIGURASI =================
    Route::middleware('permission:ppdb.period.manage')->group(function (): void {
        Route::apiResource('periods', PpdbPeriodController::class)->names('ppdb.periods');
        Route::post('periods/{period}/publish', [PpdbPeriodController::class, 'publish'])->name('ppdb.periods.publish');
    });

    Route::middleware('permission:ppdb.wave.manage')->group(function (): void {
        Route::apiResource('waves', PpdbWaveController::class)->names('ppdb.waves');
    });

    Route::middleware('permission:ppdb.program.manage')->group(function (): void {
        Route::apiResource('programs', PpdbProgramController::class)->names('ppdb.programs');
    });

    Route::middleware('permission:ppdb.form.manage')->group(function (): void {
        Route::apiResource('form-fields', PpdbFormFieldController::class)->names('ppdb.form-fields');
        Route::post('form-fields/reorder', [PpdbFormFieldController::class, 'reorder'])->name('ppdb.form-fields.reorder');
    });

    Route::middleware('permission:ppdb.question.manage')->group(function (): void {
        Route::apiResource('questions', PpdbQuestionController::class)->names('ppdb.questions');
    });

    Route::middleware('permission:ppdb.document.manage')->group(function (): void {
        Route::apiResource('document-requirements', PpdbDocumentRequirementController::class)
            ->names('ppdb.document-requirements');
    });

    Route::middleware('permission:ppdb.fee.manage')->group(function (): void {
        Route::apiResource('fees', PpdbFeeController::class)->names('ppdb.fees');
    });

    // ================= ADMIN — AKUN & CALON =================
    Route::middleware('permission:ppdb.account.manage')->group(function (): void {
        Route::get('parent-accounts', [PpdbParentAccountController::class, 'index'])->name('ppdb.parent-accounts.index');
        Route::get('parent-accounts/{parentAccount}', [PpdbParentAccountController::class, 'show'])->name('ppdb.parent-accounts.show');
        Route::post('parent-accounts/{parentAccount}/status', [PpdbParentAccountController::class, 'updateStatus'])->name('ppdb.parent-accounts.status');
        Route::post('parent-accounts/{parentAccount}/reset-password', [PpdbParentAccountController::class, 'resetPassword'])->name('ppdb.parent-accounts.reset-password');
    });

    Route::middleware('permission:ppdb.view')->group(function (): void {
        Route::get('applicants', [PpdbApplicantController::class, 'adminIndex'])->name('ppdb.applicants.index');
        Route::get('applicants/{applicant}', [PpdbApplicantController::class, 'adminShow'])->name('ppdb.applicants.show');
    });
    Route::middleware('permission:ppdb.update')->group(function (): void {
        Route::put('applicants/{applicant}', [PpdbApplicantController::class, 'adminUpdate'])->name('ppdb.applicants.update');
        Route::delete('applicants/{applicant}', [PpdbApplicantController::class, 'destroy'])->name('ppdb.applicants.destroy');
    });

    // ================= ADMIN — PENDAFTARAN & VERIFIKASI =================
    Route::middleware('permission:ppdb.registration.view')->group(function (): void {
        Route::get('registrations', [PpdbRegistrationController::class, 'adminIndex'])->name('ppdb.registrations.index');
        Route::get('registrations/{registration}', [PpdbRegistrationController::class, 'adminShow'])->name('ppdb.registrations.show');
        Route::get('registrations/{registration}/history', [PpdbRegistrationController::class, 'history'])->name('ppdb.registrations.history');
    });

    Route::middleware('permission:ppdb.registration.manage')->group(function (): void {
        Route::post('registrations/{registration}/transition', [PpdbRegistrationController::class, 'transition'])->name('ppdb.registrations.transition');
        Route::post('registrations/{registration}/request-revision', [PpdbRegistrationController::class, 'requestRevision'])->name('ppdb.registrations.request-revision');
        Route::post('registrations/{registration}/cancel', [PpdbRegistrationController::class, 'cancel'])->name('ppdb.registrations.cancel');
    });

    Route::middleware('permission:ppdb.registration.verify')->group(function (): void {
        Route::post('registrations/{registration}/verify', [PpdbRegistrationController::class, 'verify'])->name('ppdb.registrations.verify');
        Route::post('registrations/{registration}/reject', [PpdbRegistrationController::class, 'reject'])->name('ppdb.registrations.reject');
        Route::get('documents', [PpdbApplicantDocumentController::class, 'adminIndex'])->name('ppdb.documents.index');
        Route::post('documents/{document}/review', [PpdbApplicantDocumentController::class, 'review'])->name('ppdb.documents.review');
    });

    // ================= ADMIN — PEMBAYARAN =================
    Route::middleware('permission:ppdb.payment.view')->group(function (): void {
        Route::get('invoices', [PpdbInvoiceController::class, 'index'])->name('ppdb.invoices.index');
        Route::get('invoices/{invoice}', [PpdbInvoiceController::class, 'show'])->name('ppdb.invoices.show');
        Route::get('payments', [PpdbPaymentController::class, 'index'])->name('ppdb.payments.index');
    });

    Route::middleware('permission:ppdb.payment.verify')->group(function (): void {
        Route::post('invoices', [PpdbInvoiceController::class, 'store'])->name('ppdb.invoices.store');
        Route::post('invoices/{invoice}/remind', [PpdbInvoiceController::class, 'remind'])->name('ppdb.invoices.remind');
        // Aksi penutup siklus tagihan. `cancel`/`refund` sengaja memakai hak yang
        // sama dengan verifikasi pembayaran: membatalkan atau mengembalikan dana
        // adalah keputusan bendahara yang sama, dan penjaga statusnya ada di
        // layanan (`PpdbInvoiceService::assertTransitionAllowed()`).
        Route::post('invoices/{invoice}/cancel', [PpdbInvoiceController::class, 'cancel'])->name('ppdb.invoices.cancel');
        Route::post('invoices/{invoice}/refund', [PpdbInvoiceController::class, 'refund'])->name('ppdb.invoices.refund');
        Route::post('payments/{payment}/verify', [PpdbPaymentController::class, 'verify'])->name('ppdb.payments.verify');
        Route::post('payments/{payment}/reject', [PpdbPaymentController::class, 'reject'])->name('ppdb.payments.reject');
    });

    // ================= ADMIN — SELEKSI & PENGUMUMAN =================
    Route::middleware('permission:ppdb.selection.manage')->group(function (): void {
        // Literal `weights` didaftarkan sebelum apiResource: rute `PUT
        // selection-stages/{selection_stage}` berformat sama dan akan
        // menangkapnya lebih dulu (lagi pula 'weights' bukan id yang sah).
        Route::get('selection-stages/weights', [PpdbSelectionController::class, 'weights'])
            ->name('ppdb.selection-stages.weights.show');
        Route::put('selection-stages/weights', [PpdbSelectionController::class, 'syncWeights'])
            ->name('ppdb.selection-stages.weights.sync');

        Route::apiResource('selection-stages', PpdbSelectionController::class)->names('ppdb.selection-stages');
        Route::post('selection-stages/{selectionStage}/participants', [PpdbSelectionController::class, 'registerParticipants'])->name('ppdb.selection-stages.participants.store');
        Route::get('selection-stages/{selectionStage}/participants', [PpdbSelectionController::class, 'participants'])->name('ppdb.selection-stages.participants.index');
        Route::get('selection-stages/{selectionStage}/candidates', [PpdbSelectionController::class, 'candidates'])->name('ppdb.selection-stages.candidates');
        Route::post('selection-participants/{selectionParticipant}/score', [PpdbSelectionController::class, 'score'])->name('ppdb.selection-participants.score');
        Route::post('selection-participants/{selectionParticipant}/attend', [PpdbSelectionController::class, 'attend'])->name('ppdb.selection-participants.attend');
    });

    Route::middleware('permission:ppdb.announcement.manage')->group(function (): void {
        Route::apiResource('announcements', PpdbAnnouncementController::class)->names('ppdb.announcements');
        Route::post('announcements/{announcement}/publish', [PpdbAnnouncementController::class, 'publish'])->name('ppdb.announcements.publish');
        Route::post('announcements/{announcement}/archive', [PpdbAnnouncementController::class, 'archive'])->name('ppdb.announcements.archive');
        Route::post('registrations/{registration}/result', [PpdbAnnouncementController::class, 'setResult'])->name('ppdb.registrations.result');
    });

    // ================= ADMIN — MASA SANGGAH =================
    Route::middleware('permission:ppdb.registration.view')->group(function (): void {
        Route::get('appeals', [PpdbAppealController::class, 'index'])->name('ppdb.appeals.index');
        Route::get('appeals/{appeal}', [PpdbAppealController::class, 'show'])->name('ppdb.appeals.show');
    });

    Route::middleware('permission:ppdb.registration.verify')->group(function (): void {
        Route::post('appeals/{appeal}/decide', [PpdbAppealController::class, 'decide'])->name('ppdb.appeals.decide');
    });

    // ================= ADMIN — DAFTAR ULANG & KONVERSI =================
    Route::middleware('permission:ppdb.registration.manage')->group(function (): void {
        Route::post('registrations/{registration}/re-registration/open', [PpdbReRegistrationController::class, 'open'])->name('ppdb.re-registration.open');
        Route::post('registrations/{registration}/re-registration/complete', [PpdbReRegistrationController::class, 'complete'])->name('ppdb.re-registration.complete');
        Route::post('registrations/{registration}/convert-to-student', [PpdbApplicantConversionController::class, 'store'])
            ->middleware('permission:ppdb.registration.verify')
            ->name('ppdb.registrations.convert');
    });

    // ================= ADMIN — LAPORAN =================
    Route::middleware('permission:ppdb.report.view')->prefix('reports')->group(function (): void {
        Route::get('registration', [PpdbReportController::class, 'registration'])->name('ppdb.reports.registration');
        Route::get('payment', [PpdbReportController::class, 'payment'])->name('ppdb.reports.payment');
        Route::get('selection', [PpdbReportController::class, 'selection'])->name('ppdb.reports.selection');
        Route::get('document', [PpdbReportController::class, 'document'])->name('ppdb.reports.document');
    });

    Route::middleware('permission:ppdb.report.export')->group(function (): void {
        Route::get('export', [PpdbReportController::class, 'export'])->name('ppdb.export');
        Route::get('reports/{type}/pdf', [PpdbReportController::class, 'pdf'])->name('ppdb.reports.pdf');
    });
});