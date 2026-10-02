<?php

use App\Modules\SchoolOperations\Religious\Http\Controllers\{
    AchievementController, ActivityController, AnalyticsController, AttendanceController, DashboardController,
    FridayController, ImportController, MetaController, MosqueController, PersonnelController, PrayerController,
    RamadanController
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Religious & Mosque Management API (v1)
|--------------------------------------------------------------------------
| Semua route memakai Sanctum + izin `religious.*` dan batas laju modul.
| Otorisasi baris (wali hanya anaknya, siswa hanya dirinya, personel hanya
| jadwalnya) ditegakkan di service/controller, bukan hanya di middleware (§94).
*/

// ── Meta & dashboard ────────────────────────────────────────────────────────
Route::get('meta', [MetaController::class, 'index']);
Route::get('integrations', [MetaController::class, 'integrations']);

Route::get('dashboard', [DashboardController::class, 'index']);
Route::get('mosques/{mosqueId}/display', [DashboardController::class, 'display']);
Route::get('mobile', [DashboardController::class, 'mobile']);
Route::get('students/{studentId}/dashboard', [DashboardController::class, 'student']);
Route::get('parent/dashboard', [DashboardController::class, 'parent']);
Route::get('boarding/dashboard', [DashboardController::class, 'boarding']);
Route::get('attendance/dashboard', [DashboardController::class, 'attendance']);

// ── Masjid (§5-§9) ──────────────────────────────────────────────────────────
Route::get('mosques', [MosqueController::class, 'index']);
Route::post('mosques', [MosqueController::class, 'store'])->middleware('throttle:religious-write');
Route::get('mosques/{id}', [MosqueController::class, 'show']);
Route::put('mosques/{id}', [MosqueController::class, 'update']);
Route::get('mosques/{id}/areas', [MosqueController::class, 'areas']);
Route::post('mosques/{id}/areas', [MosqueController::class, 'storeArea'])->middleware('throttle:religious-write');
Route::post('mosques/{id}/access-rules', [MosqueController::class, 'storeAccessRule']);
Route::post('mosques/{id}/access-check', [MosqueController::class, 'accessCheck']);
Route::post('mosques/{id}/equipment', [MosqueController::class, 'storeEquipment']);
Route::post('mosques/{id}/assets', [MosqueController::class, 'assignAsset']);
Route::post('mosque-assets/{assignmentId}/release', [MosqueController::class, 'releaseAsset']);
Route::post('mosques/{id}/display-settings', [MosqueController::class, 'saveDisplay']);
Route::get('mosques/{id}/operational-summary', [MosqueController::class, 'operationalSummary']);

// ── Pemesanan & operasional (§59-§63) ───────────────────────────────────────
Route::get('bookings', [MosqueController::class, 'bookings']);
Route::post('bookings', [MosqueController::class, 'storeBooking'])->middleware('throttle:religious-write');
Route::post('bookings/{id}/approve', [MosqueController::class, 'approveBooking']);
Route::post('bookings/{id}/reject', [MosqueController::class, 'rejectBooking']);
Route::get('booking-availability', [MosqueController::class, 'bookingAvailability']);
Route::post('mosques/{id}/cleaning-tasks', [MosqueController::class, 'storeCleaningTask']);
Route::post('cleaning-tasks/{taskId}/complete', [MosqueController::class, 'completeCleaningTask']);
Route::post('mosques/{id}/maintenance', [MosqueController::class, 'storeMaintenance']);
Route::get('maintenance', [MosqueController::class, 'openMaintenance']);
Route::post('maintenance/{requestId}/resolve', [MosqueController::class, 'resolveMaintenance']);
Route::post('mosques/{id}/donations', [MosqueController::class, 'storeDonation']);
Route::post('donations/{collectionId}/close', [MosqueController::class, 'closeDonation']);

// ── Jadwal shalat (§10-§14) ─────────────────────────────────────────────────
Route::get('prayer-types', [PrayerController::class, 'prayerTypes']);
Route::get('prayer-time-sources', [PrayerController::class, 'sources']);
Route::get('prayer-schedules', [PrayerController::class, 'index']);
Route::post('prayer-schedules', [PrayerController::class, 'store'])->middleware('throttle:religious-write');
Route::post('prayer-schedules/generate', [PrayerController::class, 'generate'])->middleware('throttle:religious-write');
Route::post('prayer-schedules/generate-range', [PrayerController::class, 'generateRange'])->middleware('throttle:religious-write');
Route::get('prayer-schedules/{id}/versions', [PrayerController::class, 'versions']);
Route::post('prayer-schedules/override', [PrayerController::class, 'override']);

// ── Shalat berjamaah (§15-§21) ──────────────────────────────────────────────
Route::get('congregations', [PrayerController::class, 'congregations']);
Route::post('congregations', [PrayerController::class, 'storeCongregation']);
Route::post('congregations/{id}/assign', [PrayerController::class, 'assignCongregation']);
Route::post('congregations/{id}/substitute', [PrayerController::class, 'substituteCongregation']);
Route::post('congregations/{id}/complete', [PrayerController::class, 'completeCongregation']);

// ── Roster tugas (§19, §20, §54) ────────────────────────────────────────────
Route::get('duty-rosters', [PrayerController::class, 'rosters']);
Route::post('duty-rosters', [PrayerController::class, 'storeRoster'])->middleware('throttle:religious-write');
Route::post('duty-rosters/{id}/assignments', [PrayerController::class, 'storeRosterAssignment']);
Route::post('duty-rosters/{id}/generate', [PrayerController::class, 'generateRoster']);
Route::post('duty-rosters/{id}/publish', [PrayerController::class, 'publishRoster']);
Route::post('duty-rosters/{id}/student-duty', [PrayerController::class, 'studentDuty']);
Route::get('duty-of-day', [PrayerController::class, 'dutyOfDay']);

// ── Personel (§16-§21, §28) ─────────────────────────────────────────────────
Route::get('imams', [PersonnelController::class, 'imams']);
Route::post('imams', [PersonnelController::class, 'storeImam'])->middleware('throttle:religious-write');
Route::post('imams/{id}/assignments', [PersonnelController::class, 'assignImam']);
Route::get('muadzins', [PersonnelController::class, 'muadzins']);
Route::post('muadzins', [PersonnelController::class, 'storeMuadzin'])->middleware('throttle:religious-write');
Route::post('muadzins/{id}/assignments', [PersonnelController::class, 'assignMuadzin']);
Route::get('khatibs', [PersonnelController::class, 'khatibs']);
Route::post('khatibs', [PersonnelController::class, 'storeKhatib'])->middleware('throttle:religious-write');
Route::post('khatibs/{id}/assignments', [PersonnelController::class, 'assignKhatib']);
Route::get('personnel/{id}/availability', [PersonnelController::class, 'availability']);
Route::post('personnel/{id}/availability', [PersonnelController::class, 'setAvailability']);
Route::post('duty-assignments/{assignmentId}/substitute', [PersonnelController::class, 'requestSubstitute']);
Route::post('substitutions/{id}/approve', [PersonnelController::class, 'approveSubstitute']);
Route::post('religious-teachers', [PersonnelController::class, 'registerTeacher']);
Route::get('personnel-dashboard', [PersonnelController::class, 'dashboard']);

// ── Presensi shalat (§22-§26, §70, §90) ─────────────────────────────────────
Route::post('prayer-attendance', [AttendanceController::class, 'store'])->middleware('throttle:religious-attendance');
Route::post('prayer-attendance/bulk', [AttendanceController::class, 'bulk'])->middleware('throttle:religious-attendance');
Route::get('prayer-attendance/window', [AttendanceController::class, 'window']);
Route::get('prayer-attendance/statistics', [AttendanceController::class, 'statistics']);
Route::get('attendance-policies', [AttendanceController::class, 'policies']);
Route::post('attendance-policies', [AttendanceController::class, 'storePolicy']);
Route::get('students/{studentId}/boarding-prayer-requirements', [AttendanceController::class, 'boardingRules']);
Route::post('boarding-prayer-rules', [AttendanceController::class, 'storeBoardingRule']);
Route::post('prayer-attendance/adjustments', [AttendanceController::class, 'requestAdjustment']);
Route::post('prayer-attendance/adjustments/{id}/approve', [AttendanceController::class, 'approveAdjustment']);
Route::post('attendance/close-day', [AttendanceController::class, 'close']);
Route::post('attendance/forward-discipline', [AttendanceController::class, 'forwardDiscipline']);

// ── Jumat & khutbah (§27-§31, §92) ──────────────────────────────────────────
Route::get('friday', [FridayController::class, 'index']);
Route::post('friday', [FridayController::class, 'store'])->middleware('throttle:religious-write');
Route::post('friday/{id}/assign', [FridayController::class, 'assign']);
Route::post('friday/{id}/attendance', [FridayController::class, 'attendance']);
Route::get('khutbahs', [FridayController::class, 'khutbahs']);
Route::post('khutbahs', [FridayController::class, 'storeKhutbah'])->middleware('throttle:religious-write');
Route::get('khutbahs/{id}', [FridayController::class, 'showKhutbah']);
Route::post('khutbahs/{id}/versions', [FridayController::class, 'newKhutbahVersion']);
Route::post('khutbahs/{id}/document', [FridayController::class, 'attachKhutbahDocument']);
Route::post('khutbahs/{id}/transition', [FridayController::class, 'transitionKhutbah']);
Route::get('khutbah-categories', [FridayController::class, 'categories']);

// ── Kegiatan keagamaan (§32-§42, §91) ───────────────────────────────────────
Route::get('activities', [ActivityController::class, 'index']);
Route::post('activities', [ActivityController::class, 'store'])->middleware('throttle:religious-write');
Route::get('activities/today', [ActivityController::class, 'today']);
Route::get('activities/{id}/sessions', [ActivityController::class, 'sessions']);
Route::post('activities/{id}/sessions', [ActivityController::class, 'storeSession']);
Route::post('activities/{id}/publish', [ActivityController::class, 'publish']);
Route::post('activities/{id}/register', [ActivityController::class, 'register']);
Route::post('registrations/{id}/approve', [ActivityController::class, 'approveRegistration']);
Route::get('activities/{id}/participants', [ActivityController::class, 'participants']);
Route::post('activities/{id}/participants', [ActivityController::class, 'storeParticipant']);
Route::post('participants/{participantId}/attendance', [ActivityController::class, 'recordAttendance']);
Route::post('activities/{id}/complete', [ActivityController::class, 'complete']);

Route::get('study-sessions', [ActivityController::class, 'studySessions']);
Route::post('study-sessions', [ActivityController::class, 'storeStudySession']);
Route::get('study-references', [ActivityController::class, 'references']);
Route::post('study-references', [ActivityController::class, 'storeReference']);
Route::get('assessment-types', [ActivityController::class, 'assessmentTypes']);
Route::post('assessments', [ActivityController::class, 'assess']);
Route::get('students/{studentId}/assessments', [ActivityController::class, 'studentAssessments']);

// ── Ramadan & hari raya (§44-§52, §93) ──────────────────────────────────────
Route::get('ramadan', [RamadanController::class, 'index']);
Route::post('ramadan/programs', [RamadanController::class, 'storeProgram'])->middleware('throttle:religious-write');
Route::post('ramadan/programs/{id}/days', [RamadanController::class, 'generateDays']);
Route::post('ramadan/programs/{id}/activities', [RamadanController::class, 'storeActivity']);
Route::post('ramadan/programs/{id}/tarawih', [RamadanController::class, 'storeTarawih']);
Route::post('ramadan/tahajjud', [RamadanController::class, 'storeTahajjud']);
Route::post('ramadan/programs/{id}/iftar', [RamadanController::class, 'storeIftar']);
Route::post('ramadan/programs/{id}/suhoor', [RamadanController::class, 'storeSuhoor']);
Route::post('ramadan/itikaf', [RamadanController::class, 'storeItikaf']);
Route::post('ramadan/itikaf/{id}/register', [RamadanController::class, 'registerItikaf']);
Route::get('ramadan/dashboard', [RamadanController::class, 'dashboard']);
Route::get('ramadan/programs/{id}/report', [RamadanController::class, 'report']);

Route::post('eid', [RamadanController::class, 'storeEid']);
Route::post('eid/{id}/attendance', [RamadanController::class, 'recordEidAttendance']);
Route::post('qurban/programs', [RamadanController::class, 'storeQurbanProgram']);
Route::post('qurban/programs/{id}/animals', [RamadanController::class, 'storeQurbanAnimal']);
Route::post('qurban/animals/{id}/sacrifice', [RamadanController::class, 'sacrificeAnimal']);
Route::post('qurban/programs/{id}/distribute', [RamadanController::class, 'distributeQurban']);
Route::get('qurban/programs/{id}/report', [RamadanController::class, 'qurbanReport']);

// ── Prestasi & sertifikat (§55-§57) ─────────────────────────────────────────
Route::post('achievements', [AchievementController::class, 'store'])->middleware('throttle:religious-write');
Route::post('certificates', [AchievementController::class, 'storeCertificate']);
Route::get('students/{studentId}/portfolio', [AchievementController::class, 'portfolio']);
Route::get('achievements/report', [AchievementController::class, 'report']);

// ── Analitik & laporan (§71-§74) ────────────────────────────────────────────
Route::get('analytics', [AnalyticsController::class, 'index']);
Route::get('analytics/prayer', [AnalyticsController::class, 'prayerReport']);
Route::get('analytics/friday', [AnalyticsController::class, 'fridayReport']);
Route::get('analytics/activities', [AnalyticsController::class, 'activityReport']);
Route::get('analytics/ramadan/{programId}', [AnalyticsController::class, 'ramadanReport']);

// ── Impor/ekspor (§87) ──────────────────────────────────────────────────────
Route::post('imports/preview', [ImportController::class, 'preview'])->middleware('throttle:religious-write');
Route::post('imports/{id}/commit', [ImportController::class, 'commit']);
Route::post('imports/{id}/rollback', [ImportController::class, 'rollback']);
Route::get('imports', [ImportController::class, 'history']);
Route::get('exports/{entity}', [ImportController::class, 'export']);
