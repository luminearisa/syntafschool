<?php

use App\Modules\SchoolOperations\Transportation\Http\Controllers\{
    AnalyticsController, DriverAppController, DriverController, FleetController, IncidentController,
    InspectionController, LostFoundController, PortalController, RouteController, ScheduleController,
    StudentTransportationController, TripController
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Transportation Management API (v1)
|--------------------------------------------------------------------------
| Semua route memakai Sanctum + izin `transportation.*` dan batas laju modul.
| Otorisasi baris (wali hanya anaknya, pengemudi hanya tripnya) ditegakkan di
| service, bukan hanya di middleware.
*/

// ── Dashboard & operasi harian ─────────────────────────────────────────────
Route::get('dashboard', [AnalyticsController::class, 'dashboard']);
Route::get('dispatch-board', [TripController::class, 'dispatchBoard']);
Route::get('alerts', [AnalyticsController::class, 'alerts']);

// ── Armada ─────────────────────────────────────────────────────────────────
Route::get('vehicles', [FleetController::class, 'index']);
Route::post('vehicles', [FleetController::class, 'store'])->middleware('throttle:transportation-write');
Route::get('vehicles/available', [FleetController::class, 'available']);
Route::get('vehicles/expiring-documents', [FleetController::class, 'expiringDocuments']);
Route::get('vehicles/{id}', [FleetController::class, 'show']);
Route::put('vehicles/{id}', [FleetController::class, 'update']);
Route::post('vehicles/{id}/status', [FleetController::class, 'setStatus']);
Route::post('vehicles/{id}/equipment', [FleetController::class, 'syncEquipment']);
Route::get('vehicles/{id}/fuel', [FleetController::class, 'showFuelLogs']);
Route::get('vehicles/{id}/fuel-metrics', [FleetController::class, 'fuelMetrics']);
Route::get('vehicles/{id}/inspections', [FleetController::class, 'inspections']);
Route::get('vehicles/{id}/analytics', [FleetController::class, 'analytics']);
Route::post('vehicles/{id}/documents', [FleetController::class, 'addDocument']);
Route::post('documents/{documentId}/verify', [FleetController::class, 'verifyDocument']);
Route::post('fuel-logs', [FleetController::class, 'logFuel'])->middleware('throttle:transportation-driver');
Route::post('inspections', [FleetController::class, 'recordInspection']);
Route::get('rentals', [FleetController::class, 'rentals']);
Route::post('rentals', [FleetController::class, 'createRental']);

// ── Pengemudi ──────────────────────────────────────────────────────────────
Route::get('drivers', [DriverController::class, 'index']);
Route::post('drivers/sync-from-employee', [DriverController::class, 'syncFromEmployee']);
Route::get('drivers/{id}', [DriverController::class, 'show']);
Route::post('drivers/{id}/status', [DriverController::class, 'setStatus']);
Route::post('drivers/{id}/licenses', [DriverController::class, 'addLicense']);
Route::post('licenses/{licenseId}/verify', [DriverController::class, 'verifyLicense']);
Route::post('drivers/{id}/qualifications', [DriverController::class, 'syncQualifications']);
Route::post('drivers/{id}/trainings', [DriverController::class, 'addTraining']);
Route::post('drivers/{id}/availability', [DriverController::class, 'addAvailability']);
Route::get('drivers-analytics', [DriverController::class, 'analytics']);

// ── Rute & halte ───────────────────────────────────────────────────────────
Route::get('routes', [RouteController::class, 'index']);
Route::post('routes', [RouteController::class, 'store'])->middleware('throttle:transportation-write');
Route::get('routes/{id}', [RouteController::class, 'show']);
Route::put('routes/{id}', [RouteController::class, 'update']);
Route::get('routes/{id}/stops', [RouteController::class, 'stops']);
Route::post('routes/{id}/stops', [RouteController::class, 'addStop']);
Route::get('routes/{id}/capacity', [RouteController::class, 'capacity']);
Route::get('routes/{id}/optimization-data', [RouteController::class, 'optimizationData']);
Route::get('routes/{id}/analytics', [RouteController::class, 'analytics']);
Route::put('stops/{stopId}', [RouteController::class, 'updateStop']);
Route::delete('stops/{stopId}', [RouteController::class, 'removeStop']);
Route::post('route-versions/{versionId}/approve', [RouteController::class, 'approveVersion']);
Route::get('route-capacity', [RouteController::class, 'capacityOverview']);

// ── Jadwal ─────────────────────────────────────────────────────────────────
Route::get('schedules', [ScheduleController::class, 'index']);
Route::post('schedules', [ScheduleController::class, 'store']);
Route::put('schedules/{id}', [ScheduleController::class, 'update']);
Route::get('schedule-exceptions', [ScheduleController::class, 'exceptions']);
Route::post('schedule-exceptions', [ScheduleController::class, 'addException']);
Route::post('schedules/sync-academic-calendar', [ScheduleController::class, 'syncAcademicCalendar']);
Route::post('schedules/generate-trips', [ScheduleController::class, 'generateTrips']);
Route::get('trips/calendar', [ScheduleController::class, 'calendar']);

// ── Siswa, wali, penjemput, daftar tunggu ──────────────────────────────────
Route::get('requests', [StudentTransportationController::class, 'requests']);
Route::post('requests', [StudentTransportationController::class, 'storeRequest'])->middleware('throttle:transportation-write');
Route::post('requests/{id}/approve', [StudentTransportationController::class, 'approveRequest']);
Route::post('requests/{id}/reject', [StudentTransportationController::class, 'rejectRequest']);
Route::get('assignments', [StudentTransportationController::class, 'assignments']);
Route::post('assignments', [StudentTransportationController::class, 'storeAssignment'])->middleware('throttle:transportation-write');
Route::post('assignments/{id}/suspend', [StudentTransportationController::class, 'suspendAssignment']);
Route::post('assignments/{id}/resume', [StudentTransportationController::class, 'resumeAssignment']);
Route::post('assignments/{id}/change-route', [StudentTransportationController::class, 'changeAssignmentRoute']);
Route::get('students/{studentId}/profile', [StudentTransportationController::class, 'profile']);
Route::put('students/{studentId}/profile', [StudentTransportationController::class, 'upsertProfile']);
Route::post('students/{studentId}/authorized-pickups', [StudentTransportationController::class, 'addAuthorizedPickup']);
Route::post('authorized-pickups/{personId}/revoke', [StudentTransportationController::class, 'revokeAuthorizedPickup']);
Route::post('students/{studentId}/parent-authorizations', [StudentTransportationController::class, 'createParentAuthorization']);
Route::post('students/{studentId}/boarding-token', [StudentTransportationController::class, 'issueBoardingToken']);
Route::get('waitlist', [StudentTransportationController::class, 'waitlist']);
Route::post('waitlist/evaluate', [StudentTransportationController::class, 'evaluateWaitlist']);
Route::post('waitlist/{waitlistId}/accept', [StudentTransportationController::class, 'acceptOffer']);
Route::post('waitlist/{waitlistId}/decline', [StudentTransportationController::class, 'declineOffer']);

// ── Trip ───────────────────────────────────────────────────────────────────
Route::get('trips', [TripController::class, 'index']);
Route::post('trips', [TripController::class, 'store'])->middleware('throttle:transportation-write');
Route::get('trips/history', [TripController::class, 'history']);
Route::get('trips/{id}', [TripController::class, 'show']);
Route::put('trips/{id}', [TripController::class, 'update']);
Route::get('trips/{id}/manifest', [TripController::class, 'manifest']);
Route::post('trips/{id}/manifest/regenerate', [TripController::class, 'regenerateManifest']);
Route::post('trips/{id}/manifest/passengers', [TripController::class, 'addPassenger']);
Route::get('trips/{id}/stops', [TripController::class, 'stops']);
Route::get('trips/{id}/dispatch-validation', [TripController::class, 'validateDispatch']);
Route::post('trips/{id}/assign-vehicle', [TripController::class, 'assignVehicle']);
Route::post('trips/{id}/replace-vehicle', [TripController::class, 'replaceVehicle']);
Route::post('trips/{id}/dispatch', [TripController::class, 'dispatch']);
Route::post('trips/{id}/start', [TripController::class, 'start'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/arrive-stop', [TripController::class, 'arriveStop'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/depart-stop', [TripController::class, 'departStop'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/board', [TripController::class, 'board'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/dropoff', [TripController::class, 'dropoff'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/no-show', [TripController::class, 'noShow'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/delay', [TripController::class, 'delay']);
Route::post('trips/{id}/complete', [TripController::class, 'complete'])->middleware('throttle:transportation-driver');
Route::post('trips/{id}/cancel', [TripController::class, 'cancel']);
Route::post('trips/{id}/assign-driver', [DriverController::class, 'assignToTrip']);
Route::post('trips/{id}/replace-driver', [DriverController::class, 'replaceDriver']);
Route::post('trips/{id}/breakdown', [IncidentController::class, 'breakdown']);
Route::get('passengers/{passengerId}', [TripController::class, 'passenger']);

// ── Insiden ────────────────────────────────────────────────────────────────
Route::get('incidents', [IncidentController::class, 'index']);
Route::post('incidents', [IncidentController::class, 'store'])->middleware('throttle:transportation-driver');
Route::get('incidents/{id}', [IncidentController::class, 'show']);
Route::post('incidents/{id}/acknowledge', [IncidentController::class, 'acknowledge']);
Route::post('incidents/{id}/assign', [IncidentController::class, 'assign']);
Route::post('incidents/{id}/escalate', [IncidentController::class, 'escalate']);
Route::post('incidents/{id}/actions', [IncidentController::class, 'action']);
Route::post('incidents/{id}/attachments', [IncidentController::class, 'attach']);
Route::post('incidents/{id}/resolve', [IncidentController::class, 'resolve']);
Route::post('incidents/{id}/review', [IncidentController::class, 'review']);
Route::post('incidents/{id}/close', [IncidentController::class, 'close']);

// ── Inspeksi & sinkronisasi lapangan ───────────────────────────────────────
Route::get('inspection-template', [InspectionController::class, 'template']);
Route::post('pre-trip-inspections', [InspectionController::class, 'preTrip'])->middleware('throttle:transportation-driver');
Route::post('post-trip-inspections', [InspectionController::class, 'postTrip'])->middleware('throttle:transportation-driver');
Route::get('trips/{tripId}/inspection-summary', [InspectionController::class, 'summary']);
Route::post('sync/offline-events', [InspectionController::class, 'syncOffline'])->middleware('throttle:transportation-driver');
Route::post('access/scan', [InspectionController::class, 'scan'])->middleware('throttle:transportation-driver');
Route::post('access/verify-token', [InspectionController::class, 'verifyToken']);

// ── Barang hilang ──────────────────────────────────────────────────────────
Route::get('lost-found', [LostFoundController::class, 'index']);
Route::post('lost-found', [LostFoundController::class, 'store']);
Route::post('lost-found/{id}/identify', [LostFoundController::class, 'identify']);
Route::post('lost-found/{id}/notify-owner', [LostFoundController::class, 'notifyOwner']);
Route::post('lost-found/{id}/return', [LostFoundController::class, 'returnItem']);
Route::post('lost-found/{id}/close', [LostFoundController::class, 'close']);

// ── Aplikasi pengemudi ─────────────────────────────────────────────────────
Route::prefix('driver')->group(function (): void {
    Route::get('today', [DriverAppController::class, 'today']);
    Route::get('current-trip', [DriverAppController::class, 'current']);
    Route::get('trips/{tripId}/stops', [DriverAppController::class, 'stops']);
    Route::get('trips/{tripId}/offline-manifest', [DriverAppController::class, 'offlineManifest']);
});

// ── Portal wali & siswa ────────────────────────────────────────────────────
Route::prefix('portal')->group(function (): void {
    Route::get('children', [PortalController::class, 'children']);
    Route::get('children/{studentId}', [PortalController::class, 'childOverview']);
    Route::get('children/{studentId}/today', [PortalController::class, 'today']);
    Route::get('children/{studentId}/history', [PortalController::class, 'history']);
    Route::get('children/{studentId}/schedules', [PortalController::class, 'schedules']);
    Route::post('requests', [PortalController::class, 'requestChange']);
    Route::get('requests', [PortalController::class, 'myRequests']);
});

// ── Analitik, laporan, biaya, kapasitas, penutupan ─────────────────────────
Route::get('analytics/metrics', [AnalyticsController::class, 'metrics']);
Route::get('analytics/routes', [AnalyticsController::class, 'routeAnalytics']);
Route::get('analytics/vehicles', [AnalyticsController::class, 'vehicleAnalytics']);
Route::get('analytics/drivers', [AnalyticsController::class, 'driverAnalytics']);
Route::get('analytics/plan-capacity', [AnalyticsController::class, 'planCapacity']);
Route::post('analytics/plan-event', [AnalyticsController::class, 'planEvent']);
Route::get('reports/daily', [AnalyticsController::class, 'dailyReport']);
Route::get('reports/monthly', [AnalyticsController::class, 'monthlyReport']);
Route::get('reports/management', [AnalyticsController::class, 'managementReport']);
Route::get('costs', [AnalyticsController::class, 'costs']);
Route::post('costs', [AnalyticsController::class, 'storeCost']);
Route::post('costs/post-pending', [AnalyticsController::class, 'postPendingCosts']);
Route::get('daily-closings', [AnalyticsController::class, 'closings']);
Route::get('daily-closings/preflight', [AnalyticsController::class, 'preflight']);
Route::post('daily-closings', [AnalyticsController::class, 'closeDay']);
Route::post('daily-closings/{id}/reopen', [AnalyticsController::class, 'reopenDay']);
Route::post('adjustments', [AnalyticsController::class, 'adjust']);
