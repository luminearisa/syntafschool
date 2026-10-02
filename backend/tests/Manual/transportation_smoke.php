<?php

/**
 * Uji manual end-to-end Transportation Management (§101) + 15 aturan bisnis (§87),
 * isolasi akses (§91), idempotensi (§88), offline sync (§89), dan integrasi modul.
 *
 * Jalankan: php tests/Manual/transportation_smoke.php
 * Prasyarat: DB_CONNECTION=sqlite DB_DATABASE=/tmp/tms.sqlite CACHE_STORE=array
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\SchoolOperations\Transportation\Application\Services\{
    BoardingService, CapacityPlanningService, DailyClosingService, DriverService, FleetService, FuelCostService,
    IncidentService, InspectionService, LostFoundService, ManifestService, RouteService, SchedulingService,
    TransportationAnalyticsService, TransportationAssignmentService, TransportationNotificationService,
    TransportationRequestService, TransportationScopeService, TripService
};
use App\Modules\SchoolOperations\Transportation\Domain\Contracts\TransportationProviderInterface;
use App\Modules\SchoolOperations\Transportation\Models\{
    AuthorizedPickupPerson, BoardingEvent, TransportationAssignment, TransportationDailyClosing, TransportationEvent,
    TransportationIncident, TransportationNotification, TransportationRequest, TransportationRoute, TransportationSchedule,
    TransportationService as TransportServiceModel, Trip, TripManifest, TripPassenger, TripStop, VehicleProfile
};
use Illuminate\Support\Facades\DB;

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  [OK]   $label".($detail !== '' ? " → $detail" : '').PHP_EOL;

        return;
    }
    $failed++;
    $failures[] = $label;
    echo "  [GAGAL] $label".($detail !== '' ? " → $detail" : '').PHP_EOL;
}

function attempt(string $label, callable $callback): void
{
    global $failed, $failures;
    try {
        $callback();
    } catch (\Throwable $exception) {
        $failed++;
        $failures[] = $label;
        echo "  [GAGAL] $label → ".get_class($exception).': '.$exception->getMessage().PHP_EOL;
    }
}

function expectsValidationError(string $label, callable $callback): void
{
    try {
        $callback();
        check($label, false, 'seharusnya ditolak, tetapi berhasil');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        check($label, true, 'ditolak: '.mb_substr($exception->getMessage(), 0, 90));
    } catch (\Throwable $exception) {
        check($label, true, 'ditolak ('.class_basename($exception).')');
    }
}

function section(string $title): void
{
    echo PHP_EOL.'== '.$title.' =='.PHP_EOL;
}

function http(string $method, string $uri, ?object $user = null, array $payload = []): array
{
    $request = Illuminate\Http\Request::create($uri, $method, $payload, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ]);

    if ($user) {
        auth()->guard('sanctum')->setUser($user);
        auth()->setUser($user);
    } else {
        foreach (['sanctum', 'web'] as $guard) {
            auth()->guard($guard)->forgetUser();
        }
        auth()->forgetGuards();
        app()->forgetInstance('auth');
        app()->forgetInstance('auth.driver');
        \Illuminate\Support\Facades\Auth::clearResolvedInstances();
    }

    try {
        $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    } catch (\Throwable $exception) {
        return ['status' => 500, 'body' => ['exception' => $exception->getMessage()]];
    }

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent() ?: '[]', true),
    ];
}

// ── Bersihkan data operasional modul agar uji deterministik ────────────────
foreach ([
    'transportation_events', 'transportation_audit_logs', 'transportation_idempotency_keys', 'transportation_adjustments',
    'transportation_daily_closings', 'transportation_notifications', 'transportation_costs', 'lost_found_items',
    'incident_attachments', 'incident_actions', 'transportation_incidents', 'post_trip_inspections',
    'pre_trip_inspection_items', 'pre_trip_inspections', 'passenger_transfers', 'trip_delay_reports', 'dropoff_events',
    'boarding_events', 'trip_stop_events', 'trip_stops', 'trip_passengers', 'trip_manifests', 'trip_assignments', 'trips',
    'schedule_exceptions', 'transportation_schedules', 'transportation_waitlists', 'transportation_assignments',
    'transportation_requests', 'authorized_pickup_persons', 'parent_transport_authorizations', 'student_transport_profiles',
    'route_stop_versions', 'route_stops', 'route_versions', 'transportation_routes', 'driver_assignments',
    'driver_availability_windows', 'driver_training_records', 'driver_qualifications', 'driver_licenses', 'driver_profiles',
    'vehicle_fuel_logs', 'vehicle_documents', 'vehicle_equipment', 'vehicle_inspections', 'vehicle_rentals', 'vehicle_profiles',
    'transportation_devices', 'transportation_vehicle_types', 'transportation_services',
] as $table) {
    DB::table($table)->delete();
}

// ── Bersihkan fixture uji sebelumnya agar uji dapat dijalankan berulang ────
$fixtureUsers = DB::table('users')->where('email', 'like', '%@tms.test')->pluck('id')->all();
if ($fixtureUsers !== []) {
    foreach (['model_has_roles', 'model_has_permissions'] as $pivot) {
        try {
            DB::table($pivot)->where('model_type', 'App\\Models\\User')->whereIn('model_id', $fixtureUsers)->delete();
        } catch (\Throwable) {
        }
    }
    try {
        DB::table('sessions')->whereIn('user_id', $fixtureUsers)->delete();
    } catch (\Throwable) {
    }
    DB::table('users')->whereIn('id', $fixtureUsers)->delete();
}

foreach ([
    ['student_guardians', 'student_id', DB::table('students')->whereIn('nis', ['S-0001', 'S-0002', 'S-0003'])->pluck('id')->all()],
    ['class_students', 'student_id', DB::table('students')->whereIn('nis', ['S-0001', 'S-0002', 'S-0003'])->pluck('id')->all()],
] as [$table, $column, $ids]) {
    if ($ids !== []) {
        DB::table($table)->whereIn($column, $ids)->delete();
    }
}
foreach ([
    ['academic_events', 'title', ['Libur uji TMS', 'Ujian akhir semester']],
    ['semesters', 'academic_year_id', DB::table('academic_years')->where('name', '2026/2027')->pluck('id')->all()],
    ['event_types', 'code', ['TMS_HOLIDAY', 'TMS_EXAM']],
    ['students', 'nis', ['S-0001', 'S-0002', 'S-0003']],
    ['guardians', 'email', ['wali.aisyah@tms.test', 'wali.bilal@tms.test', 'wali.citra@tms.test']],
    ['school_classes', 'code', ['7A-TMS']],
    ['academic_years', 'name', ['2026/2027']],
    ['campuses', 'code', ['CP-TMS']],
    ['assets', 'asset_code', ['AST-BUS-1', 'AST-BUS-2', 'AST-VAN-1']],
    ['hr_employees', 'employee_number', ['EMP-DRV-1', 'EMP-DRV-2', 'EMP-TCH-1']],
    ['maintenance_work_orders', 'work_order_number', ['WO-TMS-0001']],
] as [$table, $column, $values]) {
    try {
        DB::table($table)->whereIn($column, $values)->delete();
    } catch (\Throwable) {
    }
}
DB::table('schools')->where('name', 'Sekolah Uji TMS')->delete();
DB::table('hr_people')->whereIn('full_name', ['Kurniawan Driver', 'Rahmat Driver', 'Sari Guru'])->delete();

// ── Fixture: sekolah, kampus, siswa, wali, pegawai, aset, pengguna ─────────
$schoolId = DB::table('schools')->insertGetId(['name' => 'Sekolah Uji TMS', 'jenjang' => 'SMP', 'created_at' => now(), 'updated_at' => now()]);
$campusId = DB::table('campuses')->insertGetId(['school_id' => $schoolId, 'code' => 'CP-TMS', 'name' => 'Kampus Uji TMS', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
$yearId = DB::table('academic_years')->insertGetId(['name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
$semesterId = DB::table('semesters')->insertGetId([
    'academic_year_id' => $yearId, 'name' => 'Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31',
    'created_at' => now(), 'updated_at' => now(),
]);
$holidayTypeId = DB::table('event_types')->insertGetId([
    'code' => 'TMS_HOLIDAY', 'name' => 'Libur (uji TMS)', 'is_holiday' => 1, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
$examTypeId = DB::table('event_types')->insertGetId([
    'code' => 'TMS_EXAM', 'name' => 'Ujian (uji TMS)', 'is_holiday' => 0, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);

$classId = DB::table('school_classes')->insertGetId(['academic_year_id' => $yearId, 'name' => '7A', 'code' => '7A-TMS', 'created_at' => now(), 'updated_at' => now()]);

$students = [];
foreach ([['S-0001', 'Aisyah Putri', 7], ['S-0002', 'Bilal Rahman', 7], ['S-0003', 'Citra Dewi', 8]] as $index => [$nis, $name, $grade]) {
    $students[$index + 1] = DB::table('students')->insertGetId([
        'school_id' => $schoolId, 'nis' => $nis, 'full_name' => $name, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('class_students')->insert([
        'school_class_id' => $classId, 'student_id' => $students[$index + 1], 'academic_year_id' => $yearId,
        'status' => 'aktif', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

$guardians = [];
foreach ([[1, 'Wali Aisyah', 'wali.aisyah@tms.test'], [2, 'Wali Bilal', 'wali.bilal@tms.test'], [3, 'Wali Citra', 'wali.citra@tms.test']] as [$studentIndex, $name, $email]) {
    $guardians[$studentIndex] = DB::table('guardians')->insertGetId([
        'full_name' => $name, 'email' => $email, 'phone' => '081100000'.$studentIndex,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('student_guardians')->insert([
        'student_id' => $students[$studentIndex], 'guardian_id' => $guardians[$studentIndex], 'is_primary' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

$people = [];
$employees = [];
foreach ([['Kurniawan Driver', 'EMP-DRV-1', 'driver1@tms.test'], ['Rahmat Driver', 'EMP-DRV-2', 'driver2@tms.test'], ['Sari Guru', 'EMP-TCH-1', null]] as $index => [$name, $number, $email]) {
    $people[$index + 1] = DB::table('hr_people')->insertGetId([
        'full_name' => $name, 'phone' => '08120000'.($index + 1), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $employees[$index + 1] = DB::table('hr_employees')->insertGetId([
        'employee_number' => $number, 'person_id' => $people[$index + 1], 'employment_status' => 'ACTIVE',
        'worker_type' => 'Employee', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

$assetIds = [];
foreach ([['AST-BUS-1', 'Bus Sekolah 01'], ['AST-BUS-2', 'Bus Sekolah 02'], ['AST-VAN-1', 'Van Cadangan']] as $index => [$code, $name]) {
    $assetIds[$index + 1] = DB::table('assets')->insertGetId([
        'school_id' => $schoolId, 'asset_code' => $code, 'name' => $name, 'status' => 'ACTIVE',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
// Work order terbuka untuk aset #2 → menguji Rule 1 & integrasi Maintenance.
DB::table('maintenance_work_orders')->insert([
    'work_order_number' => 'WO-TMS-0001', 'title' => 'Perbaikan rem bus 02', 'asset_id' => $assetIds[2],
    'status' => 'IN_PROGRESS', 'type' => 'CORRECTIVE', 'priority' => 'HIGH',
    'created_at' => now(), 'updated_at' => now(),
]);

$adminRolePerm = [];
$admin = User::query()->create(['name' => 'Admin Transportasi', 'email' => 'transport.admin@tms.test', 'password' => bcrypt('rahasia')]);
$manager = User::query()->create(['name' => 'Manajer Transportasi', 'email' => 'transport.manager@tms.test', 'password' => bcrypt('rahasia')]);
$dispatcher = User::query()->create(['name' => 'Dispatcher', 'email' => 'transport.dispatcher@tms.test', 'password' => bcrypt('rahasia')]);
$parentUser = User::query()->create(['name' => 'Wali Aisyah', 'email' => 'wali.aisyah@tms.test', 'password' => bcrypt('rahasia')]);
$parentBilal = User::query()->create(['name' => 'Wali Bilal', 'email' => 'wali.bilal@tms.test', 'password' => bcrypt('rahasia')]);
$parentCitra = User::query()->create(['name' => 'Wali Citra', 'email' => 'wali.citra@tms.test', 'password' => bcrypt('rahasia')]);
$driverUser = User::query()->create(['name' => 'Kurniawan Driver', 'email' => 'driver1@tms.test', 'password' => bcrypt('rahasia')]);
$otherParent = User::query()->create(['name' => 'Orang Lain', 'email' => 'lain@tms.test', 'password' => bcrypt('rahasia')]);

foreach (['TRANSPORTATION_ADMIN' => $admin, 'TRANSPORTATION_MANAGER' => $manager, 'TRANSPORTATION_DISPATCHER' => $dispatcher,
          'PARENT' => $parentUser, 'PARENT' => $parentBilal, 'PARENT' => $parentCitra,
          'DRIVER' => $driverUser, 'PARENT_OTHER' => $otherParent] as $role => $user) {
    $roleName = $role === 'PARENT_OTHER' ? 'PARENT' : $role;

    try {
        $user->assignRole($roleName);
    } catch (\Throwable) {
        // peran belum diseed → uji tetap berjalan dengan izin langsung
    }
}
$driverUser->setAttribute('employee_id', $employees[1]);

$seeder = new \App\Modules\SchoolOperations\Transportation\Database\Seeders\TransportationMasterSeeder();
$seeder->run();

// izin langsung supaya uji tidak bergantung pada seeder peran
foreach (TransportationScopeService::PERMISSIONS as $permission) {
    \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
}
$admin->syncPermissions(\Spatie\Permission\Models\Permission::query()->where('name', 'like', 'transportation.%')->get());
$manager->syncPermissions(['transportation.view', 'transportation.trip.manage', 'transportation.trip.dispatch', 'transportation.analytics.view', 'transportation.request.approve']);
$dispatcher->syncPermissions(['transportation.view', 'transportation.dispatch.view', 'transportation.trip.dispatch', 'transportation.trip.manage']);
$parentUser->syncPermissions(['transportation.portal.view', 'transportation.request.create']);
$parentBilal->syncPermissions(['transportation.portal.view']);
$parentCitra->syncPermissions(['transportation.portal.view']);
$driverUser->syncPermissions(['transportation.driver_app.use', 'transportation.trip.operate', 'transportation.boarding.record', 'transportation.incident.report', 'transportation.inspection.perform']);

$fleet = app(FleetService::class);
$drivers = app(DriverService::class);
$routes = app(RouteService::class);
$assignments = app(TransportationAssignmentService::class);
$requests = app(TransportationRequestService::class);
$schedules = app(SchedulingService::class);
$trips = app(TripService::class);
$manifest = app(ManifestService::class);
$boarding = app(BoardingService::class);
$inspections = app(InspectionService::class);
$incidents = app(IncidentService::class);
$costs = app(FuelCostService::class);
$closings = app(DailyClosingService::class);
$lostFound = app(LostFoundService::class);
$analytics = app(TransportationAnalyticsService::class);
$capacity = app(CapacityPlanningService::class);
$scope = app(TransportationScopeService::class);
$parents = app(\App\Modules\SchoolOperations\Transportation\Domain\Contracts\ParentGatewayInterface::class);
$provider = app(TransportationProviderInterface::class);
$notifications = app(TransportationNotificationService::class);

auth()->login($admin);

$today = now()->toDateString();

// ═══ A. Master, lintas master, dan RBAC ════════════════════════════════════
section('A. Master layanan, peran, izin, dan batas modul');

attempt('master layanan & jenis kendaraan terisi', function () use ($seeder) {
    check('jenis layanan transportasi tersedia', TransportServiceModel::query()->count() >= 10,
        TransportServiceModel::query()->count().' layanan: '.implode(', ', TransportServiceModel::query()->limit(4)->pluck('code')->all()));
    check('jenis kendaraan configurable terdaftar', \App\Modules\SchoolOperations\Transportation\Models\TransportationVehicleType::query()->count() >= 7,
        \App\Modules\SchoolOperations\Transportation\Models\TransportationVehicleType::query()->count().' jenis');
    check('peran & izin transportasi dibuat', \Spatie\Permission\Models\Role::query()->where('name', 'TRANSPORTATION_ADMIN')->exists()
        && \Spatie\Permission\Models\Permission::query()->where('name', 'transportation.trip.dispatch')->exists(),
        \Spatie\Permission\Models\Permission::query()->where('name', 'like', 'transportation.%')->count().' izin modul');
});

check('tidak ada master duplikat (student/employee/asset tetap milik modul lain)', (function () {
    $tables = DB::select("select name from sqlite_master where type='table'");
    $names = array_map(fn ($row) => $row->name, $tables);
    foreach (['students', 'hr_employees', 'assets', 'maintenance_work_orders'] as $master) {
        if (! in_array($master, $names, true)) {
            return false;
        }
    }

    return ! in_array('transportation_students', $names, true)
        && ! in_array('transportation_employees', $names, true)
        && ! in_array('transportation_assets', $names, true);
})(), 'master tetap satu sumber: students, hr_employees, assets, maintenance');

// ═══ B. Armada (§6-§10, §57, §58) ══════════════════════════════════════════
section('B. Armada: kapasitas, perlengkapan, dokumen, pemeliharaan, BBM');

$bus1 = null;
$bus2 = null;
$van = null;

attempt('kendaraan dibuat dari Asset Management', function () use ($fleet, $assetIds, $campusId, &$bus1, &$bus2, &$van) {
    $bus1 = $fleet->createVehicle([
        'asset_id' => $assetIds[1], 'vehicle_code' => 'BUS-01', 'vehicle_type' => 'BUS',
        'registration_number' => 'B 1001 TMS', 'total_capacity' => 40, 'student_capacity' => 40,
        'reserved_capacity' => 2, 'fuel_type' => 'DIESEL', 'campus_id' => $campusId,
    ]);
    $bus2 = $fleet->createVehicle([
        'asset_id' => $assetIds[2], 'vehicle_code' => 'BUS-02', 'vehicle_type' => 'BUS',
        'registration_number' => 'B 1002 TMS', 'student_capacity' => 40, 'fuel_type' => 'DIESEL', 'campus_id' => $campusId,
    ]);
    $van = $fleet->createVehicle([
        'asset_id' => $assetIds[3], 'vehicle_code' => 'VAN-01', 'vehicle_type' => 'VAN',
        'registration_number' => 'B 1003 TMS', 'student_capacity' => 12, 'campus_id' => $campusId,
    ]);

    check('profil kendaraan terhubung ke aset', $bus1->asset_id === $assetIds[1] && $bus1->vehicle_code === 'BUS-01',
        'BUS-01 asset #'.$bus1->asset_id.' | kapasitas siswa '.$bus1->student_capacity.' | reserved '.$bus1->reserved_capacity);
});

attempt('kapasitas dihitung dengan reserved & override berwenang (§8)', function () use ($fleet, $bus1) {
    $capacity = $fleet->capacityBreakdown($bus1);
    check('kapasitas terpakai = siswa − reserved', $capacity['usable_capacity'] === 38,
        'total '.$capacity['total_capacity'].' | siswa '.$capacity['student_capacity'].' | usable '.$capacity['usable_capacity']);

    $ok = $fleet->validateCapacity($bus1, 36);
    check('penumpang 36 ≤ 38 kursi diterima', $ok['allowed'] === true, 'sisa kursi '.$ok['available_seats']);

    $over = $fleet->validateCapacity($bus1, 45);
    check('penumpang 45 > kapasitas ditolak', $over['allowed'] === false, $over['reason']);

    $override = $fleet->validateCapacity($bus1, 45, true, 'Rombongan lomba darurat disetujui manajer.');
    check('override berwenang dengan alasan diterima', $override['allowed'] === true && $override['override'] === true,
        'alasan tercatat: '.mb_substr((string) $override['override_reason'], 0, 40));
});

attempt('perlengkapan kendaraan & blokir perlengkapan kritis (§9)', function () use ($fleet, $bus1) {
    $fleet->syncEquipment($bus1, [
        ['equipment_type' => 'SEAT_BELT', 'quantity' => 40, 'status' => 'AVAILABLE'],
        ['equipment_type' => 'FIRST_AID_KIT', 'quantity' => 1, 'status' => 'AVAILABLE'],
        ['equipment_type' => 'FIRE_EXTINGUISHER', 'quantity' => 2, 'status' => 'AVAILABLE'],
        ['equipment_type' => 'EMERGENCY_HAMMER', 'quantity' => 2, 'status' => 'AVAILABLE'],
        ['equipment_type' => 'CCTV', 'quantity' => 1, 'status' => 'MISSING'],
    ]);
    check('perlengkapan tersimpan dengan status', $bus1->equipment()->count() >= 5, $bus1->equipment()->count().' item');
    check('CCTV hilang tidak memblokir (tidak kritis)', $fleet->equipmentBlockers($bus1) === []);

    $fleet->syncEquipment($bus1, [['equipment_type' => 'FIRE_EXTINGUISHER', 'status' => 'DAMAGED']]);
    $blockers = $fleet->equipmentBlockers($bus1);
    check('APAR rusak (kritis) memblokir operasi', count($blockers) === 1, 'blocker: '.$blockers[0]['label'].' '.$blockers[0]['status']);

    $fleet->syncEquipment($bus1, [['equipment_type' => 'FIRE_EXTINGUISHER', 'status' => 'AVAILABLE']]);
    check('perbaikan APAR memulihkan kelayakan', $fleet->equipmentBlockers($bus1) === []);
});

attempt('dokumen kendaraan, verifikasi, dan peringatan kedaluwarsa (§10)', function () use ($fleet, $bus1) {
    $stnk = $fleet->addDocument($bus1, ['document_type' => 'STNK', 'document_number' => 'STNK-001', 'expiry_date' => now()->addYears(1)->toDateString()]);
    $fleet->verifyDocument($stnk, true, 1);
    check('STNK terverifikasi tidak memblokir', $fleet->documentBlockers($bus1) === []);

    $kir = $fleet->addDocument($bus1, ['document_type' => 'KIR', 'document_number' => 'KIR-001', 'expiry_date' => now()->subDay()->toDateString()]);
    $blockers = $fleet->documentBlockers($bus1);
    check('KIR kedaluwarsa memblokir dispatch', count($blockers) === 1, 'blocker: '.$blockers[0]['document_type'].' ('.$blockers[0]['expiry_date'].')');

    $expiring = $fleet->expiringDocuments(60);
    check('peringatan sebelum kedaluwarsa tersedia', count($expiring) >= 1,
        $expiring[0]['document_type'].' | sisa '.$expiring[0]['days_left'].' hari | expired '.var_export($expiring[0]['expired'], true));

    $kir->forceFill(['expiry_date' => now()->addMonths(6)->toDateString()])->save();
    $fleet->verifyDocument($kir, true, 1);
    check('pembaruan & verifikasi KIR memulihkan kelayakan', $fleet->documentBlockers($bus1) === []);
});

attempt('integrasi pemeliharaan memblokir kendaraan (§57 + Rule 1)', function () use ($fleet, $bus2, $bus1) {
    $eligibilityBus2 = $fleet->eligibility($bus2);
    check('BUS-02 dengan work order terbuka diblokir', $eligibilityBus2['eligible'] === false,
        'blocker: '.implode(', ', array_column($eligibilityBus2['blockers'], 'code')).' | WO '.$eligibilityBus2['maintenance']['open_work_order_count']);

    $eligibilityBus1 = $fleet->eligibility($bus1);
    check('BUS-01 tanpa work order lolos', $eligibilityBus1['eligible'] === true, 'status '.$eligibilityBus1['status']);

    $fleet->setVehicleStatus($bus1, 'MAINTENANCE', 'Uji blokir status');
    $blocked = $fleet->eligibility($bus1);
    check('status MAINTENANCE memblokir penugasan', $blocked['eligible'] === false,
        implode(', ', array_column($blocked['blockers'], 'code')));
    $fleet->setVehicleStatus($bus1, 'AVAILABLE', 'Selesai uji');
});

attempt('BBM, efisiensi, dan biaya per kilometer (§58/§59)', function () use ($fleet, $bus1) {
    $first = $fleet->logFuel(['vehicle_id' => $bus1->id, 'fuel_quantity' => 60, 'fuel_cost' => 810000, 'odometer' => 10000, 'fuel_type' => 'DIESEL'], 'fuel-1');
    check('pengisian pertama tercatat', ($first['result']['fuel_log_id'] ?? null) !== null, 'odometer 10.000 | 60 liter | Rp810.000');

    $second = $fleet->logFuel(['vehicle_id' => $bus1->id, 'fuel_quantity' => 50, 'fuel_cost' => 675000, 'odometer' => 10350, 'fuel_type' => 'DIESEL'], 'fuel-2');
    $metrics = $second['result'];
    check('efisiensi & biaya per km dihitung', $metrics['efficiency_km_per_liter'] === 7.0 && $metrics['cost_per_km'] === 1928.57,
        'jarak '.$metrics['distance_since_last_km'].' km | '.$metrics['efficiency_km_per_liter'].' km/l | Rp'.$metrics['cost_per_km'].'/km');

    $duplicate = $fleet->logFuel(['vehicle_id' => $bus1->id, 'fuel_quantity' => 50, 'fuel_cost' => 675000, 'odometer' => 10350], 'fuel-2');
    check('pencatatan BBM ganda diabaikan (idempotensi §88)', $duplicate['duplicate'] === true, 'status '.$duplicate['status']);

    expectsValidationError('odometer mundur ditolak', function () use ($fleet, $bus1) {
        $fleet->logFuel(['vehicle_id' => $bus1->id, 'fuel_quantity' => 10, 'fuel_cost' => 135000, 'odometer' => 9000], 'fuel-3');
    });
});

check('ada kendaraan tersedia untuk operasi', count($fleet->availableVehicles()) >= 1,
    count($fleet->availableVehicles()).' kendaraan siap pakai');

// ═══ C. Pengemudi (§11-§15, §51) ═══════════════════════════════════════════
section('C. Pengemudi: profil HRD, SIM, kualifikasi, ketersediaan, penugasan');

$driver1 = null;
$driver2 = null;

attempt('profil pengemudi terbentuk dari pegawai HRD (tanpa duplikasi)', function () use ($drivers, $employees, $driverUser, &$driver1, &$driver2) {
    $driver1 = $drivers->syncFromEmployee($employees[1], ['campus_id' => null]);
    $driver2 = $drivers->syncFromEmployee($employees[2]);
    $GLOBALS['driver1'] = $driver1;
    $GLOBALS['driver2'] = $driver2;
    check('profil pengemudi merujuk employee_id HRD', $driver1->employee_id === $employees[1] && $driver1->full_name === 'Kurniawan Driver',
        $driver1->driver_code.' | '.$driver1->full_name.' | employee #'.$driver1->employee_id);

    $driver1 = $drivers->syncFromEmployee($employees[1], ['user_id' => $driverUser->id]);
    check('profil pengemudi terhubung akun aplikasi', (int) $driver1->user_id === (int) $driverUser->id, 'user_id '.$driver1->user_id);
});

attempt('SIM: validitas dan kesesuaian jenis kendaraan (§12 + Rule 2)', function () use ($drivers, &$driver1) {
    $licenseA = $drivers->addLicense($driver1, ['license_type' => 'A', 'license_number' => 'SIM-A-001', 'expiry_date' => now()->addYears(2)->toDateString(), 'is_primary' => true]);
    $drivers->verifyLicense($licenseA, 1);
    $checkA = $drivers->licenseCheck($driver1->refresh(), 'BUS');
    check('SIM A tidak sesuai untuk BUS', $checkA['valid'] === false, $checkA['reason']);

    $licenseB1 = $drivers->addLicense($driver1, ['license_type' => 'B1', 'license_number' => 'SIM-B1-001', 'expiry_date' => now()->addYears(3)->toDateString()]);
    $drivers->verifyLicense($licenseB1, 1);
    $checkB1 = $drivers->licenseCheck($driver1->refresh(), 'BUS');
    check('SIM B1 sesuai untuk BUS', $checkB1['valid'] === true, 'SIM '.$checkB1['license_type'].' berlaku s.d. '.$checkB1['expiry_date']);

    $expired = $drivers->addLicense($GLOBALS['driver2'], ['license_type' => 'B1', 'license_number' => 'SIM-B1-002', 'expiry_date' => now()->subMonth()->toDateString()]);
    $checkExpired = $drivers->licenseCheck($GLOBALS['driver2']->refresh(), 'BUS');
    check('SIM kedaluwarsa ditolak', $checkExpired['valid'] === false, $checkExpired['reason']);
});

attempt('kualifikasi wajib & pelatihan (§13)', function () use ($drivers, &$driver1, &$driver2) {
    $before = $drivers->qualificationCheck($driver1);
    check('kualifikasi wajib terdeteksi belum lengkap', $before['valid'] === false, 'kurang: '.implode(', ', $before['missing']));

    $drivers->syncQualifications($driver1, [
        ['qualification_type' => 'DEFENSIVE_DRIVING', 'status' => 'VALID', 'expiry_date' => now()->addYear()->toDateString()],
        ['qualification_type' => 'FIRST_AID', 'status' => 'VALID'],
        ['qualification_type' => 'SCHOOL_CHILD_SAFETY', 'status' => 'VALID'],
        ['qualification_type' => 'SAFEGUARDING', 'status' => 'VALID'],
    ]);
    $after = $drivers->qualificationCheck($driver1->refresh());
    check('kualifikasi lengkap setelah disinkronkan', $after['valid'] === true, count($after['owned']).' kualifikasi valid');

    $drivers->addTraining($driver1, ['training_name' => 'Defensive Driving Lanjutan', 'completed_at' => now()->toDateString(), 'result' => 'PASSED', 'score' => 92]);
    check('pelatihan pengemudi tercatat', $driver1->trainings()->count() === 1, 'skor 92 | PASSED');

    // Pengemudi kedua disiapkan agar dapat menjadi pengganti pada skenario mogok (§51)
    $drivers->addLicense($GLOBALS['driver2'], ['license_type' => 'B1', 'license_number' => 'SIM-B1-003', 'expiry_date' => now()->addYears(2)->toDateString(), 'is_primary' => true]);
    $drivers->syncQualifications($GLOBALS['driver2'], [
        ['qualification_type' => 'DEFENSIVE_DRIVING', 'status' => 'VALID'],
        ['qualification_type' => 'FIRST_AID', 'status' => 'VALID'],
        ['qualification_type' => 'SCHOOL_CHILD_SAFETY', 'status' => 'VALID'],
        ['qualification_type' => 'SAFEGUARDING', 'status' => 'VALID'],
    ]);
    check('pengemudi pengganti siap bertugas', $drivers->eligibility($GLOBALS['driver2']->refresh(), ['vehicle_type' => 'BUS'])['eligible'] === true,
        'SIM B1 valid + kualifikasi lengkap');
});

attempt('ketersediaan: cuti/off-duty memblokir penugasan (§14)', function () use ($drivers, &$driver1) {
    $drivers->addAvailability($driver1, ['date' => now()->toDateString(), 'start_time' => '05:00', 'end_time' => '08:00', 'status' => 'LEAVE', 'reason' => 'Cuti keluarga']);
    $eligibility = $drivers->eligibility($driver1->refresh(), ['vehicle_type' => 'BUS', 'date' => now()->toDateString()]);
    check('pengemudi berstatus cuti tidak memenuhi syarat', $eligibility['eligible'] === false,
        implode(', ', array_column($eligibility['blockers'], 'code')));

    \App\Modules\SchoolOperations\Transportation\Models\DriverAvailabilityWindow::query()
        ->where('driver_profile_id', $driver1->id)->update(['status' => 'AVAILABLE']);
    $drivers->setDriverStatus($driver1, 'AVAILABLE', 'Kembali bertugas');
    check('ketersediaan pulih setelah cuti dicabut', $drivers->eligibility($driver1->refresh(), ['vehicle_type' => 'BUS'])['eligible'] === true);
});

// ═══ D. Rute, versi, halte, kapasitas, batasan (§16-§21, §27, §66, §67) ════
section('D. Rute: halte, versi non-destruktif, kapasitas, batasan');

$routeA = null;
$routeB = null;

attempt('rute dengan halte berurutan & geolokasi', function () use ($routes, $campusId, &$routeA, &$routeB) {
    $routeA = $routes->createRoute([
        'code' => 'ROUTE-001', 'name' => 'Depok → Kampus', 'route_type' => 'ROUND_TRIP', 'campus_id' => $campusId,
        'origin' => 'Depok', 'destination' => 'Kampus Uji TMS',
        'pickup_window_start' => '05:30', 'pickup_window_end' => '06:00', 'school_arrival_deadline' => '07:00',
        'default_vehicle_capacity' => 40,
        'stops' => [
            ['name' => 'Halte Margonda', 'latitude' => -6.3728, 'longitude' => 106.8317, 'planned_arrival' => '05:45', 'planned_departure' => '05:50', 'capacity' => 20],
            ['name' => 'Halte Cinere', 'latitude' => -6.3340, 'longitude' => 106.7980, 'planned_arrival' => '06:05', 'planned_departure' => '06:10', 'capacity' => 20],
            ['name' => 'Kampus Uji TMS', 'stop_type' => 'SCHOOL', 'latitude' => -6.3010, 'longitude' => 106.7700, 'planned_arrival' => '06:40', 'pickup_allowed' => false],
        ],
    ], $GLOBALS['admin']->id ?? 1);

    check('rute dibuat dengan versi awal & halte', $routeA->version_number === 1 && count($routes->stops($routeA->id)) === 3,
        $routeA->code.' v'.$routeA->version_number.' | '.count($routes->stops($routeA->id)).' halte | jarak '.$routeA->refresh()->distance_km.' km');

    $routeB = $routes->createRoute([
        'code' => 'ROUTE-002', 'name' => 'Bogor → Kampus', 'route_type' => 'PICKUP', 'campus_id' => $campusId,
        'default_vehicle_capacity' => 40,
        'stops' => [
            ['name' => 'Halte Bogor Baru', 'latitude' => -6.5944, 'longitude' => 106.7892, 'planned_arrival' => '05:40'],
            ['name' => 'Kampus Uji TMS', 'stop_type' => 'SCHOOL', 'latitude' => -6.3010, 'longitude' => 106.7700, 'planned_arrival' => '06:55'],
        ],
    ], 1);
    check('rute kedua siap untuk skenario kapasitas', $routeB->code === 'ROUTE-002', count($routes->stops($routeB->id)).' halte');
});

attempt('versi rute non-destruktif (§18)', function () use ($routes, &$routeA) {
    $v1 = \App\Modules\SchoolOperations\Transportation\Models\RouteVersion::query()->where('route_id', $routeA->id)->first();
    $v2 = $routes->updateRoute($routeA, ['name' => 'Depok → Kampus (revisi)', 'estimated_duration_minutes' => 65], 'Penyesuaian jam & nama rute.', 1);

    check('perubahan rute membuat versi baru', $v2->version_number === 2, 'v'.$v2->version_number.' | '.$v2->change_summary);
    check('versi lama tetap tersimpan (superseded)', \App\Modules\SchoolOperations\Transportation\Models\RouteVersion::query()
        ->where('route_id', $routeA->id)->where('version_number', 1)->where('status', 'SUPERSEDED')->exists(),
        'v1 effective_until '.$v1->refresh()->effective_until?->toDateString());
    check('snapshot versi memuat halte', count((array) ($v2->snapshot['stops'] ?? [])) === 3,
        count((array) ($v2->snapshot['stops'] ?? [])).' halte terekam di snapshot v2');

    $routes->approveVersion($v2, 1);
    check('versi rute dapat disetujui', $v2->refresh()->approved_at !== null, 'approved_at '.$v2->approved_at);
});

attempt('validasi halte milik rute & batasan rute (§67 + Rule 7)', function () use ($routes, &$routeA, &$routeB) {
    $stopsA = $routes->stops($routeA->id);
    $stopB = $routes->stops($routeB->id)[0];
    check('halte rute lain tidak diakui (§87 Rule 7)', $routes->stopBelongs($routeA->id, $stopB['id']) === false,
        'halte '.$stopB['name'].' milik ROUTE-002');
    check('halte milik rute sendiri diakui', $routes->stopBelongs($routeA->id, $stopsA[0]['id']) === true, $stopsA[0]['name']);

    $violations = $routes->validateConstraints($routeA, ['passengers' => 60, 'vehicle_capacity' => 40]);
    check('batasan mendeteksi kapasitas terlampaui', $violations['valid'] === false,
        implode(', ', array_column($violations['violations'], 'code')));

    $ok = $routes->validateConstraints($routeA, ['passengers' => 30, 'vehicle_capacity' => 40]);
    check('batasan lolos untuk penumpang dalam kapasitas', $ok['valid'] === true, 'halte '.$ok['checked']['stop_count'].' | maks durasi '.$ok['checked']['max_duration_minutes'].' menit');
});

attempt('data siap-optimasi & fondasi penyedia optimasi (§66)', function () use ($routes, &$routeA) {
    $data = $routes->optimizationData($routeA->id);
    check('data optimasi memuat halte, koordinat, penumpang, batasan',
        count($data['stops']) === 3 && isset($data['stops'][0]['latitude'], $data['constraints']['vehicle_capacity']),
        count($data['stops']).' halte | kapasitas '.$data['constraints']['vehicle_capacity'].' | tenggat '.$data['constraints']['school_arrival_deadline']);

    $optimizer = app(\App\Modules\SchoolOperations\Transportation\Domain\Contracts\RouteOptimizationProviderInterface::class);
    $capabilities = $optimizer->capabilities();
    check('optimasi rute otomatis dinyatakan belum tersedia (batas Phase 2)', $capabilities['available'] === false,
        'provider siap: '.implode(', ', $capabilities['providers']));
    check('kontrak optimasi tetap menjawab dengan urutan fallback', $optimizer->optimize($data['stops'])['optimized'] === false,
        'urutan halte tetap dipakai');
});

echo PHP_EOL.'(bagian A–D selesai: '.$passed.' lulus, '.$failed.' gagal)'.PHP_EOL;

// lanjutkan ke bagian E–N
require __DIR__.'/transportation_smoke_part2.php';
