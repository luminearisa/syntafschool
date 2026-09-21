<?php

use App\Http\Controllers\Api\TahfizAttendanceController;
use App\Http\Controllers\Api\TahfizCertificateController;
use App\Http\Controllers\Api\TahfizDashboardController;
use App\Http\Controllers\Api\TahfizEnrollmentController;
use App\Http\Controllers\Api\TahfizExamController;
use App\Http\Controllers\Api\TahfizHalaqahController;
use App\Http\Controllers\Api\TahfizIndividualTargetController;
use App\Http\Controllers\Api\TahfizInstructorController;
use App\Http\Controllers\Api\TahfizInterventionController;
use App\Http\Controllers\Api\TahfizLevelController;
use App\Http\Controllers\Api\TahfizMemorizationRecordController;
use App\Http\Controllers\Api\TahfizMistakeTypeController;
use App\Http\Controllers\Api\TahfizMutabaahController;
use App\Http\Controllers\Api\TahfizMutabaahItemController;
use App\Http\Controllers\Api\TahfizNotificationController;
use App\Http\Controllers\Api\TahfizParticipantController;
use App\Http\Controllers\Api\TahfizProgramController;
use App\Http\Controllers\Api\TahfizProgressController;
use App\Http\Controllers\Api\TahfizRegistrationController;
use App\Http\Controllers\Api\TahfizReportController;
use App\Http\Controllers\Api\TahfizRevisionRecordController;
use App\Http\Controllers\Api\TahfizRubricController;
use App\Http\Controllers\Api\TahfizScheduleController;
use App\Http\Controllers\Api\TahfizSessionController;
use App\Http\Controllers\Api\TahfizSurahController;
use App\Http\Controllers\Api\TahfizTahsinRecordController;
use App\Http\Controllers\Api\TahfizTargetController;
use App\Http\Controllers\Api\TahfizTasmiRecordController;
use App\Http\Controllers\Api\TahfizTilawahRecordController;
use Illuminate\Support\Facades\Route;

/*
 * Modul Tahfiz / Al-Quran Management System.
 *
 * Pembagian permission:
 * - tahfiz.view    → melihat seluruh data Tahfiz (peserta, halaqah, setoran, laporan)
 * - tahfiz.create  → membuat data baru (peserta, enrollment, halaqah, jadwal)
 * - tahfiz.update  → mengubah konfigurasi & data
 * - tahfiz.delete  → menghapus data
 * - tahfiz.setoran → mencatat setoran, murojaah, tilawah, tahsin, tasmi, kehadiran
 * - tahfiz.approve → menyetujui pendaftaran, mengaktifkan enrollment,
 *                    menerbitkan & mencabut sertifikat, memutuskan ujian
 * - tahfiz.assess  → menilai setoran (memberi skor & status), menilai ujian
 * - tahfiz.report  → laporan & rapor Tahfiz
 *
 * Catatan: peserta umum mendaftar sendiri lewat endpoint publik (tanpa login),
 * lihat `tahfiz/registrations/store`.
 */

Route::prefix('tahfiz')->group(function (): void {
    // ================= KONFIGURASI =================
    Route::apiResource('programs', TahfizProgramController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.programs');

    Route::apiResource('levels', TahfizLevelController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.levels');

    Route::apiResource('rubrics', TahfizRubricController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.rubrics');

    Route::apiResource('mistake-types', TahfizMistakeTypeController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.mistake-types');

    Route::apiResource('mutabaah-items', TahfizMutabaahItemController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.mutabaah-items');

    Route::apiResource('instructors', TahfizInstructorController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.instructors');

    // ================= REFERENSI AL-QUR'AN =================
    // 114 surah — dibaca saja, dipakai form setoran/target/tahsin.
    Route::get('surahs', [TahfizSurahController::class, 'index'])
        ->middleware('permission:tahfiz.view')
        ->name('tahfiz.surahs.index');

    // ================= PENDAFTARAN MANDIRI =================
    // `store` publik (tanpa login); sisanya butuh login.
    Route::post('registrations', [TahfizRegistrationController::class, 'store'])
        ->withoutMiddleware(['auth:sanctum'])
        ->name('tahfiz.registrations.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('registrations', [TahfizRegistrationController::class, 'index'])
            ->middleware('permission:tahfiz.view')
            ->name('tahfiz.registrations.index');
        Route::post('registrations/{registration}/approve', [TahfizRegistrationController::class, 'approve'])
            ->middleware('permission:tahfiz.approve')
            ->name('tahfiz.registrations.approve');
        Route::post('registrations/{registration}/reject', [TahfizRegistrationController::class, 'reject'])
            ->middleware('permission:tahfiz.approve')
            ->name('tahfiz.registrations.reject');
    });

    // ================= PESERTA =================
    Route::apiResource('participants', TahfizParticipantController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.participants');

    // ================= ENROLLMENT =================
    Route::middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update')->group(function (): void {
        Route::apiResource('enrollments', TahfizEnrollmentController::class)
            ->names('tahfiz.enrollments');

        Route::post('enrollments/{enrollment}/transition', [TahfizEnrollmentController::class, 'transition'])
            ->name('tahfiz.enrollments.transition');
        Route::post('enrollments/{enrollment}/approve', [TahfizEnrollmentController::class, 'approve'])
            ->middleware('permission:tahfiz.approve')
            ->name('tahfiz.enrollments.approve');
    });

    // ================= HALAQAH =================
    Route::apiResource('halaqahs', TahfizHalaqahController::class)
        ->middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')
        ->names('tahfiz.halaqahs');

    // ================= JADWAL & SESI =================
    Route::middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')->group(function (): void {
        Route::apiResource('schedules', TahfizScheduleController::class)->names('tahfiz.schedules');
        Route::apiResource('sessions', TahfizSessionController::class)->names('tahfiz.sessions');

        Route::post('sessions/{session}/transition', [TahfizSessionController::class, 'transition'])
            ->name('tahfiz.sessions.transition');
        Route::post('sessions/generate', [TahfizSessionController::class, 'generate'])
            ->name('tahfiz.sessions.generate');
    });

    // ================= KEHADIRAN =================
    Route::middleware('permission:tahfiz.view|tahfiz.setoran')->group(function (): void {
        Route::apiResource('attendances', TahfizAttendanceController::class)
            ->except(['show'])
            ->names('tahfiz.attendances');
        Route::post('attendances/bulk', [TahfizAttendanceController::class, 'bulk'])
            ->name('tahfiz.attendances.bulk');
    });

    // ================= SETORAN HAFALAN =================
    // Pencatatan oleh guru (`tahfiz.setoran`), penilaian oleh `tahfiz.assess`.
    // Pemisahan ini memastikan status selalu ditetapkan melalui `review`.
    // Baca (`index`/`show`) cukup `tahfiz.view`; tulis butuh `tahfiz.setoran`
    // dan hapus butuh `tahfiz.delete` — `permission:a|b` adalah ATAU, jadi
    // memasukkan semuanya dalam satu grup akan memberi `tahfiz.view` kuasa
    // menulis.
    Route::middleware('permission:tahfiz.view')->group(function (): void {
        Route::get('memorization-records', [TahfizMemorizationRecordController::class, 'index'])
            ->name('tahfiz.memorization-records.index');
        Route::get('memorization-records/{memorizationRecord}', [TahfizMemorizationRecordController::class, 'show'])
            ->name('tahfiz.memorization-records.show');
    });

    Route::middleware('permission:tahfiz.setoran')->group(function (): void {
        Route::post('memorization-records', [TahfizMemorizationRecordController::class, 'store'])
            ->name('tahfiz.memorization-records.store');
        Route::put('memorization-records/{memorizationRecord}', [TahfizMemorizationRecordController::class, 'update'])
            ->name('tahfiz.memorization-records.update');
        Route::post('memorization-records/{memorizationRecord}/review', [TahfizMemorizationRecordController::class, 'review'])
            ->middleware('permission:tahfiz.assess')
            ->name('tahfiz.memorization-records.review');
    });

    Route::delete('memorization-records/{memorizationRecord}', [TahfizMemorizationRecordController::class, 'destroy'])
        ->middleware('permission:tahfiz.delete')
        ->name('tahfiz.memorization-records.destroy');

    // ================= PROGRESS & PETA HAFALAN =================
    Route::middleware('permission:tahfiz.view')->group(function (): void {
        Route::get('participants/{participant}/progress', [TahfizProgressController::class, 'index'])
            ->name('tahfiz.progress.index');
        Route::get('participants/{participant}/progress/map', [TahfizProgressController::class, 'map'])
            ->name('tahfiz.progress.map');
        Route::get('participants/{participant}/progress/target', [TahfizProgressController::class, 'target'])
            ->name('tahfiz.progress.target');
    });

    // ================= AKTIVITAS HARIAN =================
    // Pencatatan (`tahfiz.setoran`), penghapusan (`tahfiz.delete`).
    // Tahsin memakai rubrik; tasmi' & tilawah tidak memengaruhi progress hafalan.
    Route::middleware('permission:tahfiz.view|tahfiz.setoran|tahfiz.delete')->group(function (): void {
        Route::apiResource('revision-records', TahfizRevisionRecordController::class)
            ->names('tahfiz.revision-records');
        Route::apiResource('tilawah-records', TahfizTilawahRecordController::class)
            ->names('tahfiz.tilawah-records');
        Route::apiResource('tasmi-records', TahfizTasmiRecordController::class)
            ->names('tahfiz.tasmi-records');
        Route::apiResource('tahsin-records', TahfizTahsinRecordController::class)
            ->names('tahfiz.tahsin-records');
    });

    // ================= TARGET =================
    Route::middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')->group(function (): void {
        Route::apiResource('targets', TahfizTargetController::class)
            ->names('tahfiz.targets');
        Route::apiResource('individual-targets', TahfizIndividualTargetController::class)
            ->names('tahfiz.individual-targets');
    });

    // ================= MUTABAAH HARIAN =================
    // Item diambil dari `tahfiz_mutabaah_items` (konfigurasi admin), bukan
    // ditulis keras di kode. Satu baris per peserta per tanggal.
    Route::middleware('permission:tahfiz.view|tahfiz.setoran|tahfiz.delete')->group(function (): void {
        Route::apiResource('mutabaah', TahfizMutabaahController::class)
            ->names('tahfiz.mutabaah');
    });

    // ================= UJIAN =================
    // Penilaian butuh `tahfiz.assess`; penerbitan & keputusan butuh `tahfiz.approve`.
    Route::middleware('permission:tahfiz.view|tahfiz.create|tahfiz.update|tahfiz.delete')->group(function (): void {
        Route::apiResource('exams', TahfizExamController::class)
            ->names('tahfiz.exams');

        Route::post('exams/{exam}/transition', [TahfizExamController::class, 'transition'])
            ->middleware('permission:tahfiz.approve')
            ->name('tahfiz.exams.transition');
        Route::post('exams/{exam}/participants', [TahfizExamController::class, 'registerParticipants'])
            ->name('tahfiz.exams.participants.store');
        Route::get('exams/{exam}/participants', [TahfizExamController::class, 'participants'])
            ->name('tahfiz.exams.participants.index');
        Route::post('exams/{exam}/items/generate', [TahfizExamController::class, 'generateItems'])
            ->name('tahfiz.exams.items.generate');
        Route::post('exams/{exam}/items/{item}/score', [TahfizExamController::class, 'scoreItem'])
            ->middleware('permission:tahfiz.assess')
            ->name('tahfiz.exams.items.score');
        // Token URI `{examParticipant}` harus cocok dengan nama parameter di
        // controller — Laravel mengikat binding implisit berdasarkan nama.
        Route::post('exams/{exam}/participants/{examParticipant}/result', [TahfizExamController::class, 'recordResult'])
            ->middleware('permission:tahfiz.assess')
            ->name('tahfiz.exams.participants.result');
    });

    // ================= SERTIFIKAT =================
    // `verify` publik (dipindai dari QR) — tidak butuh login.
    Route::post('certificates/verify', [TahfizCertificateController::class, 'verify'])
        ->withoutMiddleware(['auth:sanctum'])
        ->name('tahfiz.certificates.verify');

    Route::middleware('permission:tahfiz.view|tahfiz.report|tahfiz.approve')->group(function (): void {
        Route::apiResource('certificates', TahfizCertificateController::class)
            ->names('tahfiz.certificates');
        Route::post('certificates/{certificate}/revoke', [TahfizCertificateController::class, 'revoke'])
            ->middleware('permission:tahfiz.approve')
            ->name('tahfiz.certificates.revoke');
    });

    // ================= INTERVENTION =================
    Route::middleware('permission:tahfiz.view|tahfiz.update')->group(function (): void {
        Route::apiResource('interventions', TahfizInterventionController::class)
            ->names('tahfiz.interventions');
        Route::post('interventions/{intervention}/transition', [TahfizInterventionController::class, 'transition'])
            ->name('tahfiz.interventions.transition');
        Route::get('participants/{participant}/interventions', [TahfizInterventionController::class, 'forParticipant'])
            ->name('tahfiz.participants.interventions');
    });

    // ================= NOTIFIKASI =================
    // Notifikasi terlingkup per pengguna: milik sendiri atau milik anak wali.
    // Tidak butuh permission modul — setiap pengguna berhak melihat notifikasinya.
    Route::middleware('auth:sanctum')->prefix('notifications')->group(function (): void {
        Route::get('/', [TahfizNotificationController::class, 'index'])
            ->name('tahfiz.notifications.index');
        Route::get('/unread-count', [TahfizNotificationController::class, 'unreadCount'])
            ->name('tahfiz.notifications.unread-count');
        Route::get('/{notification}', [TahfizNotificationController::class, 'show'])
            ->name('tahfiz.notifications.show');
        Route::post('/{notification}/read', [TahfizNotificationController::class, 'markAsRead'])
            ->name('tahfiz.notifications.read');
        Route::post('/read-all', [TahfizNotificationController::class, 'markAllAsRead'])
            ->name('tahfiz.notifications.read-all');
        Route::delete('/{notification}', [TahfizNotificationController::class, 'destroy'])
            ->name('tahfiz.notifications.destroy');
    });

    // ================= DASHBOARD =================
    // Dashboard tertentu untuk setiap peran. Cakupan data ditentukan
    // server-side; dashboard wali memilih "Saya sebagai Peserta" vs "Anak Saya".
    Route::middleware('auth:sanctum')->prefix('dashboards')->group(function (): void {
        Route::get('/admin', [TahfizDashboardController::class, 'admin'])
            ->middleware('permission:tahfiz.view')
            ->name('tahfiz.dashboards.admin');
        Route::get('/guru', [TahfizDashboardController::class, 'instructor'])
            ->name('tahfiz.dashboards.guru');
        Route::get('/musyrif', [TahfizDashboardController::class, 'musyrif'])
            ->name('tahfiz.dashboards.musyrif');
        Route::get('/peserta', [TahfizDashboardController::class, 'participant'])
            ->name('tahfiz.dashboards.peserta');
        Route::get('/wali', [TahfizDashboardController::class, 'parent'])
            ->name('tahfiz.dashboards.wali');
    });

    // ================= LAPORAN & RAPOR =================
    // Semua laporan dihitung ulang dari data mentah saat diminta.
    Route::middleware('permission:tahfiz.report')->prefix('reports')->group(function (): void {
        Route::get('/individual/{participant}', [TahfizReportController::class, 'individual'])
            ->name('tahfiz.reports.individual');
        Route::get('/halaqah/{halaqah}', [TahfizReportController::class, 'halaqah'])
            ->name('tahfiz.reports.halaqah');
        Route::get('/program/{program}', [TahfizReportController::class, 'program'])
            ->name('tahfiz.reports.program');
        Route::get('/teacher/{employee}', [TahfizReportController::class, 'teacher'])
            ->name('tahfiz.reports.teacher');
        Route::get('/rapor/{participant}', [TahfizReportController::class, 'rapor'])
            ->name('tahfiz.reports.rapor');
    });
});
