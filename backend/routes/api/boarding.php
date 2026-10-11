<?php

use App\Http\Controllers\Api\Boarding\BoardingAchievementController;
use App\Http\Controllers\Api\Boarding\BoardingActivityController;
use App\Http\Controllers\Api\Boarding\BoardingActivityScheduleController;
use App\Http\Controllers\Api\Boarding\BoardingAttendanceController;
use App\Http\Controllers\Api\Boarding\BoardingAttendanceTypeController;
use App\Http\Controllers\Api\Boarding\BoardingAuditController;
use App\Http\Controllers\Api\Boarding\BoardingBedController;
use App\Http\Controllers\Api\Boarding\BoardingBuildingController;
use App\Http\Controllers\Api\Boarding\BoardingDashboardController;
use App\Http\Controllers\Api\Boarding\BoardingDisciplinaryActionController;
use App\Http\Controllers\Api\Boarding\BoardingDormitoryController;
use App\Http\Controllers\Api\Boarding\BoardingFloorController;
use App\Http\Controllers\Api\Boarding\BoardingGroupController;
use App\Http\Controllers\Api\Boarding\BoardingHealthController;
use App\Http\Controllers\Api\Boarding\BoardingHomecomingController;
use App\Http\Controllers\Api\Boarding\BoardingItemDepositController;
use App\Http\Controllers\Api\Boarding\BoardingMonitoringController;
use App\Http\Controllers\Api\Boarding\BoardingMusyrifController;
use App\Http\Controllers\Api\Boarding\BoardingPermissionController;
use App\Http\Controllers\Api\Boarding\BoardingPlacementController;
use App\Http\Controllers\Api\Boarding\BoardingPortalController;
use App\Http\Controllers\Api\Boarding\BoardingReportController;
use App\Http\Controllers\Api\Boarding\BoardingRoomController;
use App\Http\Controllers\Api\Boarding\BoardingSettingsController;
use App\Http\Controllers\Api\Boarding\BoardingViolationController;
use App\Http\Controllers\Api\Boarding\BoardingViolationTypeController;
use App\Http\Controllers\Api\Boarding\BoardingVisitorController;
use Illuminate\Support\Facades\Route;

/*
 * Boarding / Pesantren — struktur asrama, penempatan, pengasuh, kelompok.
 *
 * Dimuat otomatis oleh `routes/api.php` di dalam grup `v1` + `auth:sanctum`,
 * jadi tidak ada prefix/middleware auth di sini (pola yang sama dengan
 * `wallet_topups.php` dan `library.php`).
 *
 * Otorisasi: satu `permission:` per route — tidak ada pemeriksaan role di
 * controller. Pembatasan area binaan musyrif tetap ada di service via
 * `BoardingScopeService` (permission tidak tahu apa-apa soal area).
 */
Route::prefix('boarding')->name('boarding.')->group(function (): void {
    // ---------------- Struktur: asrama → gedung → lantai → kamar → bed ----
    Route::get('dormitories', [BoardingDormitoryController::class, 'index'])
        ->middleware('permission:boarding.dorm.manage')->name('dormitories.index');
    Route::get('dormitories/{dormitory}', [BoardingDormitoryController::class, 'show'])
        ->middleware('permission:boarding.dorm.manage')->name('dormitories.show');
    Route::post('dormitories', [BoardingDormitoryController::class, 'store'])
        ->middleware('permission:boarding.dorm.manage')->name('dormitories.store');
    Route::match(['put', 'patch'], 'dormitories/{dormitory}', [BoardingDormitoryController::class, 'update'])
        ->middleware('permission:boarding.dorm.manage')->name('dormitories.update');
    Route::delete('dormitories/{dormitory}', [BoardingDormitoryController::class, 'destroy'])
        ->middleware('permission:boarding.dorm.manage')->name('dormitories.destroy');

    Route::get('buildings', [BoardingBuildingController::class, 'index'])
        ->middleware('permission:boarding.building.manage')->name('buildings.index');
    Route::get('buildings/{building}', [BoardingBuildingController::class, 'show'])
        ->middleware('permission:boarding.building.manage')->name('buildings.show');
    Route::post('buildings', [BoardingBuildingController::class, 'store'])
        ->middleware('permission:boarding.building.manage')->name('buildings.store');
    Route::match(['put', 'patch'], 'buildings/{building}', [BoardingBuildingController::class, 'update'])
        ->middleware('permission:boarding.building.manage')->name('buildings.update');
    Route::delete('buildings/{building}', [BoardingBuildingController::class, 'destroy'])
        ->middleware('permission:boarding.building.manage')->name('buildings.destroy');

    Route::get('floors', [BoardingFloorController::class, 'index'])
        ->middleware('permission:boarding.floor.manage')->name('floors.index');
    Route::get('floors/{floor}', [BoardingFloorController::class, 'show'])
        ->middleware('permission:boarding.floor.manage')->name('floors.show');
    Route::post('floors', [BoardingFloorController::class, 'store'])
        ->middleware('permission:boarding.floor.manage')->name('floors.store');
    Route::match(['put', 'patch'], 'floors/{floor}', [BoardingFloorController::class, 'update'])
        ->middleware('permission:boarding.floor.manage')->name('floors.update');
    Route::delete('floors/{floor}', [BoardingFloorController::class, 'destroy'])
        ->middleware('permission:boarding.floor.manage')->name('floors.destroy');

    Route::get('rooms', [BoardingRoomController::class, 'index'])
        ->middleware('permission:boarding.room.manage')->name('rooms.index');
    Route::get('rooms/available', [BoardingRoomController::class, 'available'])
        ->middleware('permission:boarding.room.manage')->name('rooms.available');
    Route::get('rooms/{room}', [BoardingRoomController::class, 'show'])
        ->middleware('permission:boarding.room.manage')->name('rooms.show');
    Route::post('rooms', [BoardingRoomController::class, 'store'])
        ->middleware('permission:boarding.room.manage')->name('rooms.store');
    Route::match(['put', 'patch'], 'rooms/{room}', [BoardingRoomController::class, 'update'])
        ->middleware('permission:boarding.room.manage')->name('rooms.update');
    Route::delete('rooms/{room}', [BoardingRoomController::class, 'destroy'])
        ->middleware('permission:boarding.room.manage')->name('rooms.destroy');
    Route::get('rooms/{room}/occupants', [BoardingRoomController::class, 'occupants'])
        ->middleware('permission:boarding.room.manage')->name('rooms.occupants');

    Route::get('beds', [BoardingBedController::class, 'index'])
        ->middleware('permission:boarding.bed.manage')->name('beds.index');
    Route::get('beds/available', [BoardingBedController::class, 'available'])
        ->middleware('permission:boarding.bed.manage')->name('beds.available');
    Route::get('beds/{bed}', [BoardingBedController::class, 'show'])
        ->middleware('permission:boarding.bed.manage')->name('beds.show');
    Route::post('beds', [BoardingBedController::class, 'store'])
        ->middleware('permission:boarding.bed.manage')->name('beds.store');
    Route::post('beds/bulk', [BoardingBedController::class, 'bulk'])
        ->middleware('permission:boarding.bed.manage')->name('beds.bulk');
    Route::match(['put', 'patch'], 'beds/{bed}', [BoardingBedController::class, 'update'])
        ->middleware('permission:boarding.bed.manage')->name('beds.update');
    Route::delete('beds/{bed}', [BoardingBedController::class, 'destroy'])
        ->middleware('permission:boarding.bed.manage')->name('beds.destroy');

    // ---------------- Penempatan & mutasi santri --------------------------
    Route::get('placements', [BoardingPlacementController::class, 'index'])
        ->middleware('permission:boarding.placement.view')->name('placements.index');
    Route::get('placements/occupancy', [BoardingPlacementController::class, 'occupancy'])
        ->middleware('permission:boarding.placement.view')->name('placements.occupancy');
    Route::get('placements/{placement}', [BoardingPlacementController::class, 'show'])
        ->middleware('permission:boarding.placement.view')->name('placements.show');
    Route::post('placements', [BoardingPlacementController::class, 'store'])
        ->middleware('permission:boarding.placement.manage')->name('placements.store');
    Route::post('placements/{placement}/transfer', [BoardingPlacementController::class, 'transfer'])
        ->middleware('permission:boarding.mutation.manage')->name('placements.transfer');
    Route::post('placements/{placement}/end', [BoardingPlacementController::class, 'end'])
        ->middleware('permission:boarding.placement.manage')->name('placements.end');
    Route::get('students/{student}/placements', [BoardingPlacementController::class, 'history'])
        ->middleware('permission:boarding.placement.view')->name('placements.history');
    Route::get('students/{student}/placement', [BoardingPlacementController::class, 'current'])
        ->middleware('permission:boarding.placement.view')->name('placements.current');

    // ---------------- Pengasuh & kelompok binaan --------------------------
    Route::get('musyrifs', [BoardingMusyrifController::class, 'index'])
        ->middleware('permission:boarding.musyrif.view')->name('musyrifs.index');
    Route::get('musyrifs/{musyrif}', [BoardingMusyrifController::class, 'show'])
        ->middleware('permission:boarding.musyrif.view')->name('musyrifs.show');
    Route::post('musyrifs', [BoardingMusyrifController::class, 'store'])
        ->middleware('permission:boarding.musyrif.manage')->name('musyrifs.store');
    Route::match(['put', 'patch'], 'musyrifs/{musyrif}', [BoardingMusyrifController::class, 'update'])
        ->middleware('permission:boarding.musyrif.manage')->name('musyrifs.update');
    Route::delete('musyrifs/{musyrif}', [BoardingMusyrifController::class, 'destroy'])
        ->middleware('permission:boarding.musyrif.manage')->name('musyrifs.destroy');
    Route::get('employees/{employeeId}/musyrif-assignments', [BoardingMusyrifController::class, 'byEmployee'])
        ->middleware('permission:boarding.musyrif.view')->name('musyrifs.by-employee');

    Route::get('groups', [BoardingGroupController::class, 'index'])
        ->middleware('permission:boarding.group.view')->name('groups.index');
    Route::get('groups/{group}', [BoardingGroupController::class, 'show'])
        ->middleware('permission:boarding.group.view')->name('groups.show');
    Route::post('groups', [BoardingGroupController::class, 'store'])
        ->middleware('permission:boarding.group.manage')->name('groups.store');
    Route::match(['put', 'patch'], 'groups/{group}', [BoardingGroupController::class, 'update'])
        ->middleware('permission:boarding.group.manage')->name('groups.update');
    Route::delete('groups/{group}', [BoardingGroupController::class, 'destroy'])
        ->middleware('permission:boarding.group.manage')->name('groups.destroy');
    Route::get('groups/{group}/members', [BoardingGroupController::class, 'members'])
        ->middleware('permission:boarding.group.view')->name('groups.members');
    Route::post('groups/{group}/members', [BoardingGroupController::class, 'attachMember'])
        ->middleware('permission:boarding.group.manage')->name('groups.members.attach');
    Route::delete('groups/{group}/members/{member}', [BoardingGroupController::class, 'detachMember'])
        ->middleware('permission:boarding.group.manage')->name('groups.members.detach');

    // ---------------- Kegiatan, tipe absensi & jadwal ---------------------
    Route::get('attendance-types', [BoardingAttendanceTypeController::class, 'index'])
        ->middleware('permission:boarding.activity.view')->name('attendance-types.index');
    Route::get('attendance-types/{attendanceType}', [BoardingAttendanceTypeController::class, 'show'])
        ->middleware('permission:boarding.activity.view')->name('attendance-types.show');
    Route::post('attendance-types', [BoardingAttendanceTypeController::class, 'store'])
        ->middleware('permission:boarding.activity.manage')->name('attendance-types.store');
    Route::match(['put', 'patch'], 'attendance-types/{attendanceType}', [BoardingAttendanceTypeController::class, 'update'])
        ->middleware('permission:boarding.activity.manage')->name('attendance-types.update');
    Route::delete('attendance-types/{attendanceType}', [BoardingAttendanceTypeController::class, 'destroy'])
        ->middleware('permission:boarding.activity.manage')->name('attendance-types.destroy');

    Route::get('activities', [BoardingActivityController::class, 'index'])
        ->middleware('permission:boarding.activity.view')->name('activities.index');
    Route::get('activities/{activity}', [BoardingActivityController::class, 'show'])
        ->middleware('permission:boarding.activity.view')->name('activities.show');
    Route::post('activities', [BoardingActivityController::class, 'store'])
        ->middleware('permission:boarding.activity.manage')->name('activities.store');
    Route::match(['put', 'patch'], 'activities/{activity}', [BoardingActivityController::class, 'update'])
        ->middleware('permission:boarding.activity.manage')->name('activities.update');
    Route::delete('activities/{activity}', [BoardingActivityController::class, 'destroy'])
        ->middleware('permission:boarding.activity.manage')->name('activities.destroy');

    Route::get('schedules/weekly', [BoardingActivityScheduleController::class, 'weekly'])
        ->middleware('permission:boarding.schedule.view')->name('schedules.weekly');
    Route::get('schedules', [BoardingActivityScheduleController::class, 'index'])
        ->middleware('permission:boarding.schedule.view')->name('schedules.index');
    Route::get('schedules/{schedule}', [BoardingActivityScheduleController::class, 'show'])
        ->middleware('permission:boarding.schedule.view')->name('schedules.show');
    Route::post('schedules', [BoardingActivityScheduleController::class, 'store'])
        ->middleware('permission:boarding.schedule.manage')->name('schedules.store');
    Route::match(['put', 'patch'], 'schedules/{schedule}', [BoardingActivityScheduleController::class, 'update'])
        ->middleware('permission:boarding.schedule.manage')->name('schedules.update');
    Route::delete('schedules/{schedule}', [BoardingActivityScheduleController::class, 'destroy'])
        ->middleware('permission:boarding.schedule.manage')->name('schedules.destroy');

    // ---------------- Absensi (§17, §23–§26) -------------------------------
    Route::get('attendances/roster', [BoardingAttendanceController::class, 'roster'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.roster');
    Route::get('attendances/recap', [BoardingAttendanceController::class, 'recap'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.recap');
    Route::get('attendances/monitoring', [BoardingAttendanceController::class, 'monitoring'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.monitoring');
    Route::get('attendances/statistics', [BoardingAttendanceController::class, 'statistics'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.statistics');
    Route::get('attendances', [BoardingAttendanceController::class, 'index'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.index');
    Route::get('attendances/{attendance}', [BoardingAttendanceController::class, 'show'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.show');
    Route::post('attendances', [BoardingAttendanceController::class, 'store'])
        ->middleware('permission:boarding.attendance.manage')->name('attendances.store');
    Route::post('attendances/bulk', [BoardingAttendanceController::class, 'bulk'])
        ->middleware('permission:boarding.attendance.manage')->name('attendances.bulk');
    Route::post('attendances/scan', [BoardingAttendanceController::class, 'scan'])
        ->middleware('permission:boarding.attendance.manage')->name('attendances.scan');
    Route::match(['put', 'patch'], 'attendances/{attendance}/revise', [BoardingAttendanceController::class, 'revise'])
        ->middleware('permission:boarding.attendance.manage')->name('attendances.revise');
    Route::get('students/{student}/attendances', [BoardingAttendanceController::class, 'history'])
        ->middleware('permission:boarding.attendance.view')->name('attendances.history');

    // ---------------- Titipan barang santri ---------------------------------
    // Barang bersarang di bawah titipan; transisi pinjam/kembali dicatat
    // sebagai riwayat pinjaman per barang.
    Route::get('item-deposits', [BoardingItemDepositController::class, 'index'])
        ->middleware('permission:boarding.deposit.view')->name('item-deposits.index');
    Route::post('item-deposits', [BoardingItemDepositController::class, 'store'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.store');
    Route::get('item-deposits/{deposit}', [BoardingItemDepositController::class, 'show'])
        ->middleware('permission:boarding.deposit.view')->name('item-deposits.show');
    Route::put('item-deposits/{deposit}', [BoardingItemDepositController::class, 'update'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.update');
    Route::delete('item-deposits/{deposit}', [BoardingItemDepositController::class, 'destroy'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.destroy');
    Route::post('item-deposits/{deposit}/finish', [BoardingItemDepositController::class, 'finish'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.finish');
    Route::post('item-deposits/{deposit}/items', [BoardingItemDepositController::class, 'storeItem'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.items.store');
    Route::put('item-deposits/{deposit}/items/{item}', [BoardingItemDepositController::class, 'updateItem'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.items.update');
    Route::delete('item-deposits/{deposit}/items/{item}', [BoardingItemDepositController::class, 'destroyItem'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.items.destroy');
    Route::post('item-deposits/{deposit}/items/{item}/borrow', [BoardingItemDepositController::class, 'borrow'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.items.borrow');
    Route::post('item-deposits/{deposit}/items/{item}/return', [BoardingItemDepositController::class, 'returnItem'])
        ->middleware('permission:boarding.deposit.manage')->name('item-deposits.items.return');

    // ---------------- Izin keluar (§27–§29) --------------------------------
    Route::get('permissions', [BoardingPermissionController::class, 'index'])
        ->middleware('permission:boarding.permission.view')->name('permissions.index');
    Route::get('permissions/{permission}', [BoardingPermissionController::class, 'show'])
        ->middleware('permission:boarding.permission.view')->name('permissions.show');
    Route::post('permissions', [BoardingPermissionController::class, 'store'])
        ->middleware('permission:boarding.permission.manage')->name('permissions.store');
    Route::post('permissions/{permission}/submit', [BoardingPermissionController::class, 'submit'])
        ->middleware('permission:boarding.permission.manage')->name('permissions.submit');
    Route::post('permissions/{permission}/approve', [BoardingPermissionController::class, 'approve'])
        ->middleware('permission:boarding.permission.approve')->name('permissions.approve');
    Route::post('permissions/{permission}/reject', [BoardingPermissionController::class, 'reject'])
        ->middleware('permission:boarding.permission.approve')->name('permissions.reject');
    Route::post('permissions/{permission}/check-out', [BoardingPermissionController::class, 'checkOut'])
        ->middleware('permission:boarding.permission.manage')->name('permissions.check-out');
    Route::post('permissions/{permission}/check-in', [BoardingPermissionController::class, 'checkIn'])
        ->middleware('permission:boarding.permission.manage')->name('permissions.check-in');
    Route::post('permissions/{permission}/cancel', [BoardingPermissionController::class, 'cancel'])
        ->middleware('permission:boarding.permission.manage')->name('permissions.cancel');

    // ---------------- Kepulangan (§30–§31) ---------------------------------
    Route::get('homecomings', [BoardingHomecomingController::class, 'index'])
        ->middleware('permission:boarding.homecoming.view')->name('homecomings.index');
    Route::get('homecomings/{homecoming}', [BoardingHomecomingController::class, 'show'])
        ->middleware('permission:boarding.homecoming.view')->name('homecomings.show');
    Route::post('homecomings', [BoardingHomecomingController::class, 'store'])
        ->middleware('permission:boarding.homecoming.manage')->name('homecomings.store');
    Route::post('homecomings/{homecoming}/submit', [BoardingHomecomingController::class, 'submit'])
        ->middleware('permission:boarding.homecoming.manage')->name('homecomings.submit');
    Route::post('homecomings/{homecoming}/approve', [BoardingHomecomingController::class, 'approve'])
        ->middleware('permission:boarding.homecoming.approve')->name('homecomings.approve');
    Route::post('homecomings/{homecoming}/reject', [BoardingHomecomingController::class, 'reject'])
        ->middleware('permission:boarding.homecoming.approve')->name('homecomings.reject');
    Route::post('homecomings/{homecoming}/check-out', [BoardingHomecomingController::class, 'checkOut'])
        ->middleware('permission:boarding.homecoming.manage')->name('homecomings.check-out');
    Route::post('homecomings/{homecoming}/check-in', [BoardingHomecomingController::class, 'checkIn'])
        ->middleware('permission:boarding.homecoming.manage')->name('homecomings.check-in');
    Route::post('homecomings/{homecoming}/cancel', [BoardingHomecomingController::class, 'cancel'])
        ->middleware('permission:boarding.homecoming.manage')->name('homecomings.cancel');

    // ---------------- Kunjungan (§40) --------------------------------------
    Route::get('visitors/present', [BoardingVisitorController::class, 'present'])
        ->middleware('permission:boarding.visitor.view')->name('visitors.present');
    Route::get('visitors', [BoardingVisitorController::class, 'index'])
        ->middleware('permission:boarding.visitor.view')->name('visitors.index');
    Route::get('visitors/{visitor}', [BoardingVisitorController::class, 'show'])
        ->middleware('permission:boarding.visitor.view')->name('visitors.show');
    Route::post('visitors', [BoardingVisitorController::class, 'store'])
        ->middleware('permission:boarding.visitor.manage')->name('visitors.store');
    Route::post('visitors/{visitor}/check-in', [BoardingVisitorController::class, 'checkIn'])
        ->middleware('permission:boarding.visitor.manage')->name('visitors.check-in');
    Route::post('visitors/{visitor}/check-out', [BoardingVisitorController::class, 'checkOut'])
        ->middleware('permission:boarding.visitor.manage')->name('visitors.check-out');
    Route::post('visitors/{visitor}/cancel', [BoardingVisitorController::class, 'cancel'])
        ->middleware('permission:boarding.visitor.manage')->name('visitors.cancel');

    // ---------------- Kedisiplinan: master jenis (§35) ---------------------
    Route::get('violation-types', [BoardingViolationTypeController::class, 'index'])
        ->middleware('permission:boarding.violation.view')->name('violation-types.index');
    Route::get('violation-types/{violationType}', [BoardingViolationTypeController::class, 'show'])
        ->middleware('permission:boarding.violation.view')->name('violation-types.show');
    Route::post('violation-types', [BoardingViolationTypeController::class, 'store'])
        ->middleware('permission:boarding.violation.manage')->name('violation-types.store');
    Route::match(['put', 'patch'], 'violation-types/{violationType}', [BoardingViolationTypeController::class, 'update'])
        ->middleware('permission:boarding.violation.manage')->name('violation-types.update');
    Route::delete('violation-types/{violationType}', [BoardingViolationTypeController::class, 'destroy'])
        ->middleware('permission:boarding.violation.manage')->name('violation-types.destroy');

    // ---------------- Pelanggaran & poin (§36–§37) -------------------------
    Route::get('violations', [BoardingViolationController::class, 'index'])
        ->middleware('permission:boarding.violation.view')->name('violations.index');
    Route::get('violations/{violation}', [BoardingViolationController::class, 'show'])
        ->middleware('permission:boarding.violation.view')->name('violations.show');
    Route::post('violations', [BoardingViolationController::class, 'store'])
        ->middleware('permission:boarding.violation.manage')->name('violations.store');
    Route::match(['put', 'patch'], 'violations/{violation}', [BoardingViolationController::class, 'update'])
        ->middleware('permission:boarding.violation.manage')->name('violations.update');
    Route::delete('violations/{violation}', [BoardingViolationController::class, 'destroy'])
        ->middleware('permission:boarding.violation.manage')->name('violations.destroy');
    Route::get('students/{student}/points', [BoardingViolationController::class, 'points'])
        ->middleware('permission:boarding.point.view')->name('students.points');

    // ---------------- Tindakan pendisiplinan (§38) -------------------------
    Route::get('disciplinary-actions', [BoardingDisciplinaryActionController::class, 'index'])
        ->middleware('permission:boarding.violation.view')->name('disciplinary-actions.index');
    Route::get('disciplinary-actions/{action}', [BoardingDisciplinaryActionController::class, 'show'])
        ->middleware('permission:boarding.violation.view')->name('disciplinary-actions.show');
    Route::post('disciplinary-actions', [BoardingDisciplinaryActionController::class, 'store'])
        ->middleware('permission:boarding.violation.manage')->name('disciplinary-actions.store');
    Route::match(['put', 'patch'], 'disciplinary-actions/{action}', [BoardingDisciplinaryActionController::class, 'update'])
        ->middleware('permission:boarding.violation.manage')->name('disciplinary-actions.update');
    Route::post('disciplinary-actions/{action}/status', [BoardingDisciplinaryActionController::class, 'status'])
        ->middleware('permission:boarding.violation.manage')->name('disciplinary-actions.status');
    Route::delete('disciplinary-actions/{action}', [BoardingDisciplinaryActionController::class, 'destroy'])
        ->middleware('permission:boarding.violation.manage')->name('disciplinary-actions.destroy');

    // ---------------- Prestasi (§41) ---------------------------------------
    Route::get('achievements/options', [BoardingAchievementController::class, 'options'])
        ->middleware('permission:boarding.achievement.view')->name('achievements.options');
    Route::get('achievements/summary', [BoardingAchievementController::class, 'summary'])
        ->middleware('permission:boarding.achievement.view')->name('achievements.summary');
    Route::get('achievements', [BoardingAchievementController::class, 'index'])
        ->middleware('permission:boarding.achievement.view')->name('achievements.index');
    Route::get('achievements/{achievement}', [BoardingAchievementController::class, 'show'])
        ->middleware('permission:boarding.achievement.view')->name('achievements.show');
    Route::post('achievements', [BoardingAchievementController::class, 'store'])
        ->middleware('permission:boarding.achievement.manage')->name('achievements.store');
    Route::match(['put', 'patch'], 'achievements/{achievement}', [BoardingAchievementController::class, 'update'])
        ->middleware('permission:boarding.achievement.manage')->name('achievements.update');
    Route::delete('achievements/{achievement}', [BoardingAchievementController::class, 'destroy'])
        ->middleware('permission:boarding.achievement.manage')->name('achievements.destroy');

    // ---------------- Kesehatan & isolasi (§39) ----------------------------
    Route::get('health-records/isolations', [BoardingHealthController::class, 'isolations'])
        ->middleware('permission:boarding.health.view')->name('health.isolations');
    Route::get('health-records', [BoardingHealthController::class, 'index'])
        ->middleware('permission:boarding.health.view')->name('health-records.index');
    Route::get('health-records/{healthRecord}', [BoardingHealthController::class, 'show'])
        ->middleware('permission:boarding.health.view')->name('health-records.show');
    Route::post('health-records', [BoardingHealthController::class, 'store'])
        ->middleware('permission:boarding.health.manage')->name('health-records.store');
    Route::match(['put', 'patch'], 'health-records/{healthRecord}', [BoardingHealthController::class, 'update'])
        ->middleware('permission:boarding.health.manage')->name('health-records.update');
    Route::post('health-records/{healthRecord}/status', [BoardingHealthController::class, 'status'])
        ->middleware('permission:boarding.health.manage')->name('health-records.status');
    Route::delete('health-records/{healthRecord}', [BoardingHealthController::class, 'destroy'])
        ->middleware('permission:boarding.health.manage')->name('health-records.destroy');
    Route::post('isolation', [BoardingHealthController::class, 'isolate'])
        ->middleware('permission:boarding.health.manage')->name('isolation.store');
    Route::post('isolation/{placement}/release', [BoardingHealthController::class, 'releaseIsolation'])
        ->middleware('permission:boarding.health.manage')->name('isolation.release');

    // ---------------- Monitoring status santri (§42) -----------------------
    Route::get('monitoring/options', [BoardingMonitoringController::class, 'options'])
        ->middleware('permission:boarding.dashboard.view')->name('monitoring.options');
    Route::get('monitoring/bulk', [BoardingMonitoringController::class, 'bulk'])
        ->middleware('permission:boarding.dashboard.view')->name('monitoring.bulk');
    Route::get('monitoring/students/{student}', [BoardingMonitoringController::class, 'student'])
        ->middleware('permission:boarding.dashboard.view')->name('monitoring.students.show');
    Route::get('monitoring', [BoardingMonitoringController::class, 'index'])
        ->middleware('permission:boarding.dashboard.view')->name('monitoring.index');

    // ---------------- Dashboard (§43) --------------------------------------
    Route::get('dashboard', [BoardingDashboardController::class, 'index'])
        ->middleware('permission:boarding.dashboard.view')->name('dashboard.index');

    // ---------------- Laporan (§43–§44) ------------------------------------
    Route::get('reports', [BoardingReportController::class, 'kinds'])
        ->middleware('permission:boarding.report.view')->name('reports.index');
    Route::get('reports/{kind}', [BoardingReportController::class, 'show'])
        ->middleware('permission:boarding.report.view')->name('reports.show');
    Route::get('reports/{kind}/export', [BoardingReportController::class, 'export'])
        ->middleware('permission:boarding.report.export')->name('reports.export');

    // ---------------- Jejak audit (baca saja) ------------------------------
    Route::get('audit-logs', [BoardingAuditController::class, 'index'])
        ->middleware('permission:boarding.audit.view')->name('audit-logs.index');

    // ---------------- Portal orang tua (§44–§45) ---------------------------
    // Tanpa permission boarding: orang tualogin lewat alur PPDB yang sudah ada
    // dan tidak memegang permission admin. Keamanannya kepemilikan anak,
    // ditegakkan BoardingPortalService::assertChild() di setiap endpoint.
    Route::prefix('portal')->name('portal.')->group(function (): void {
        Route::get('children', [BoardingPortalController::class, 'children'])->name('children');
        Route::get('children/{student}/summary', [BoardingPortalController::class, 'summary'])->name('children.summary');
        Route::get('children/{student}/points', [BoardingPortalController::class, 'points'])->name('children.points');
        Route::get('children/{student}/violations', [BoardingPortalController::class, 'violations'])->name('children.violations');
        Route::get('children/{student}/achievements', [BoardingPortalController::class, 'achievements'])->name('children.achievements');
        Route::get('children/{student}/health', [BoardingPortalController::class, 'health'])->name('children.health');
        Route::get('children/{student}/attendances', [BoardingPortalController::class, 'attendances'])->name('children.attendances');
        Route::get('children/{student}/permissions', [BoardingPortalController::class, 'permissions'])->name('children.permissions');
        Route::get('children/{student}/homecomings', [BoardingPortalController::class, 'homecomings'])->name('children.homecomings');
        Route::get('children/{student}/visitors', [BoardingPortalController::class, 'visitors'])->name('children.visitors');
        Route::post('children/{student}/permissions', [BoardingPortalController::class, 'storePermission'])->middleware('throttle:20,1')->name('children.permissions.store');
        Route::post('children/{student}/permissions/{permission}/cancel', [BoardingPortalController::class, 'cancelPermission'])->name('children.permissions.cancel');
        Route::post('children/{student}/homecomings', [BoardingPortalController::class, 'storeHomecoming'])->middleware('throttle:20,1')->name('children.homecomings.store');
        Route::post('children/{student}/homecomings/{homecoming}/cancel', [BoardingPortalController::class, 'cancelHomecoming'])->name('children.homecomings.cancel');
    });

    // ---------------- Pengaturan ------------------------------------------
    Route::get('settings', [BoardingSettingsController::class, 'show'])
        ->middleware('permission:boarding.settings.view')->name('settings.show');
    Route::match(['put', 'patch'], 'settings', [BoardingSettingsController::class, 'update'])
        ->middleware('permission:boarding.settings.manage')->name('settings.update');
});
