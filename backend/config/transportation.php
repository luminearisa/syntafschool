<?php

/**
 * Konfigurasi Transportation Management System (TMS).
 *
 * Seluruh daftar master di bawah dapat diubah tanpa menyentuh kode (configurable),
 * karena kebutuhan tiap institusi berbeda (kelas SIM, jenis armada, jenis dokumen).
 */
return [
    'module' => [
        'code' => 'school-operations.transportation',
        'name' => 'Transportation Management',
        'version' => 2,
    ],

    // §5 jenis layanan transportasi
    'service_types' => [
        'SCHOOL_BUS' => ['name' => 'School Bus', 'billable' => true],
        'SHUTTLE' => ['name' => 'Shuttle', 'billable' => true],
        'BOARDING_TRANSPORT' => ['name' => 'Boarding Transportation', 'billable' => true],
        'STAFF_TRANSPORT' => ['name' => 'Staff Transportation', 'billable' => false],
        'TEACHER_TRANSPORT' => ['name' => 'Teacher Transportation', 'billable' => false],
        'EVENT_TRANSPORT' => ['name' => 'Event Transportation', 'billable' => true],
        'FIELD_TRIP' => ['name' => 'Field Trip', 'billable' => true],
        'COMPETITION' => ['name' => 'Competition Transportation', 'billable' => true],
        'EMERGENCY' => ['name' => 'Emergency Transportation', 'billable' => false],
        'SPECIAL' => ['name' => 'Special Transportation', 'billable' => true],
    ],

    // §7 jenis kendaraan (configurable)
    'vehicle_types' => [
        'BUS' => ['name' => 'Bus', 'license_requirement' => 'B1', 'passenger_capacity' => 40, 'standing_capacity' => 0],
        'MINIBUS' => ['name' => 'Minibus', 'license_requirement' => 'B1', 'passenger_capacity' => 16, 'standing_capacity' => 0],
        'VAN' => ['name' => 'Van', 'license_requirement' => 'A', 'passenger_capacity' => 12, 'standing_capacity' => 0],
        'CAR' => ['name' => 'Mobil', 'license_requirement' => 'A', 'passenger_capacity' => 5, 'standing_capacity' => 0],
        'MOTORCYCLE' => ['name' => 'Sepeda Motor', 'license_requirement' => 'C', 'passenger_capacity' => 1, 'standing_capacity' => 0],
        'SHUTTLE' => ['name' => 'Shuttle', 'license_requirement' => 'A', 'passenger_capacity' => 14, 'standing_capacity' => 0],
        'SPECIAL_VEHICLE' => ['name' => 'Kendaraan Khusus', 'license_requirement' => 'B2', 'passenger_capacity' => 6, 'standing_capacity' => 0],
    ],

    // §12 kelas SIM (configurable per institusi)
    'license_types' => [
        'A' => ['name' => 'SIM A', 'vehicle_types' => ['CAR', 'VAN', 'SHUTTLE'], 'rank' => 1],
        'B1' => ['name' => 'SIM B1', 'vehicle_types' => ['CAR', 'VAN', 'SHUTTLE', 'MINIBUS', 'BUS'], 'rank' => 2],
        'B2' => ['name' => 'SIM B2', 'vehicle_types' => ['CAR', 'VAN', 'SHUTTLE', 'MINIBUS', 'BUS', 'SPECIAL_VEHICLE'], 'rank' => 3],
        'C' => ['name' => 'SIM C', 'vehicle_types' => ['MOTORCYCLE'], 'rank' => 1],
        'OTHER' => ['name' => 'Lainnya', 'vehicle_types' => [], 'rank' => 9],
    ],

    // §9 perlengkapan kendaraan
    'equipment_types' => [
        'SEAT_BELT' => ['label' => 'Sabuk Keselamatan', 'critical' => true, 'mandatory' => true],
        'FIRST_AID_KIT' => ['label' => 'Kotak P3K', 'critical' => true, 'mandatory' => true],
        'FIRE_EXTINGUISHER' => ['label' => 'APAR', 'critical' => true, 'mandatory' => true],
        'EMERGENCY_HAMMER' => ['label' => 'Palu Darurat', 'critical' => true, 'mandatory' => true],
        'GPS' => ['label' => 'GPS', 'critical' => false, 'mandatory' => false],
        'CCTV' => ['label' => 'CCTV', 'critical' => false, 'mandatory' => false],
        'RFID_READER' => ['label' => 'Pembaca RFID', 'critical' => false, 'mandatory' => false],
        'NFC_READER' => ['label' => 'Pembaca NFC', 'critical' => false, 'mandatory' => false],
        'WIFI' => ['label' => 'WiFi', 'critical' => false, 'mandatory' => false],
        'AIR_CONDITIONER' => ['label' => 'Pendingin Udara', 'critical' => false, 'mandatory' => false],
        'WHEELCHAIR_RAMP' => ['label' => 'Ramp Kursi Roda', 'critical' => false, 'mandatory' => false],
        'CHILD_SAFETY_EQUIPMENT' => ['label' => 'Perlengkapan Keselamatan Anak', 'critical' => true, 'mandatory' => false],
    ],

    // §10 jenis dokumen kendaraan
    'document_types' => [
        'STNK' => ['label' => 'STNK', 'blocks_dispatch' => true, 'reminder_days' => 30],
        'KIR' => ['label' => 'KIR / Uji Kelayakan', 'blocks_dispatch' => true, 'reminder_days' => 30],
        'INSURANCE' => ['label' => 'Asuransi', 'blocks_dispatch' => true, 'reminder_days' => 30],
        'VEHICLE_REGISTRATION' => ['label' => 'Registrasi Kendaraan', 'blocks_dispatch' => false, 'reminder_days' => 60],
        'INSPECTION_CERTIFICATE' => ['label' => 'Sertifikat Inspeksi', 'blocks_dispatch' => false, 'reminder_days' => 30],
        'PERMIT' => ['label' => 'Izin Operasional', 'blocks_dispatch' => true, 'reminder_days' => 30],
        'OTHER' => ['label' => 'Lainnya', 'blocks_dispatch' => false, 'reminder_days' => 60],
    ],

    // §13 kualifikasi pengemudi
    'qualification_types' => [
        'DEFENSIVE_DRIVING' => ['label' => 'Defensive Driving', 'required_for_dispatch' => true],
        'FIRST_AID' => ['label' => 'Pertolongan Pertama', 'required_for_dispatch' => true],
        'SCHOOL_CHILD_SAFETY' => ['label' => 'Keselamatan Anak Sekolah', 'required_for_dispatch' => true],
        'EMERGENCY_RESPONSE' => ['label' => 'Tanggap Darurat', 'required_for_dispatch' => false],
        'VEHICLE_TRAINING' => ['label' => 'Pelatihan Kendaraan', 'required_for_dispatch' => false],
        'SAFEGUARDING' => ['label' => 'Safeguarding', 'required_for_dispatch' => true],
        'ROAD_SAFETY' => ['label' => 'Keselamatan Berlalu Lintas', 'required_for_dispatch' => false],
        'INSTITUTION_TRAINING' => ['label' => 'Pelatihan Institusi', 'required_for_dispatch' => false],
    ],

    // §20 jenis halte
    'stop_types' => ['HOME', 'PICKUP_POINT', 'DROPOFF_POINT', 'SCHOOL', 'CAMPUS', 'DORMITORY', 'TRANSIT', 'SPECIAL'],

    // §17 jenis rute
    'route_types' => ['PICKUP', 'DROPOFF', 'ROUND_TRIP', 'SHUTTLE', 'CAMPUS_TRANSFER', 'EVENT', 'SPECIAL'],

    // §31 status trip
    'trip_statuses' => ['PLANNED', 'DISPATCHED', 'DRIVER_ASSIGNED', 'VEHICLE_ASSIGNED', 'BOARDING',
        'IN_TRANSIT', 'ARRIVED', 'COMPLETED', 'CANCELLED', 'DELAYED', 'INCIDENT'],

    // §34 status naik
    'boarding_statuses' => ['EXPECTED', 'BOARDED', 'ABSENT', 'EXCUSED', 'NO_SHOW', 'TRANSFERRED'],

    // §35 status turun
    'dropoff_statuses' => ['EXPECTED', 'DROPPED_OFF', 'NOT_DROPPED_OFF', 'AUTHORIZED_PICKUP', 'TRANSFERRED', 'INCIDENT'],

    // §36 metode presensi transportasi
    'attendance_methods' => ['MANUAL', 'QR', 'NFC', 'RFID', 'SMART_CARD', 'DEVICE', 'GPS_GEOFENCE'],

    // §44 alasan keterlambatan
    'delay_reasons' => ['TRAFFIC', 'VEHICLE_PROBLEM', 'DRIVER_ISSUE', 'WEATHER', 'STUDENT_DELAY',
        'ROAD_CLOSURE', 'ACCIDENT', 'OPERATIONAL', 'OTHER'],

    // §45 jenis insiden
    'incident_types' => ['ACCIDENT', 'BREAKDOWN', 'MEDICAL', 'STUDENT_BEHAVIOR', 'MISSING_PASSENGER',
        'ROAD_BLOCK', 'SECURITY', 'SAFETY', 'DELAY', 'OTHER'],
    'incident_severities' => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'],

    // §69 checklist pra-trip
    'pre_trip_items' => [
        ['code' => 'VEHICLE_CONDITION', 'label' => 'Kondisi Kendaraan', 'category' => 'VEHICLE', 'critical' => false, 'mandatory' => true],
        ['code' => 'FUEL', 'label' => 'Bahan Bakar', 'category' => 'VEHICLE', 'critical' => false, 'mandatory' => true],
        ['code' => 'TIRES', 'label' => 'Ban', 'category' => 'VEHICLE', 'critical' => true, 'mandatory' => true],
        ['code' => 'LIGHTS', 'label' => 'Lampu', 'category' => 'VEHICLE', 'critical' => true, 'mandatory' => true],
        ['code' => 'BRAKES', 'label' => 'Rem', 'category' => 'VEHICLE', 'critical' => true, 'mandatory' => true],
        ['code' => 'DOORS', 'label' => 'Pintu', 'category' => 'VEHICLE', 'critical' => true, 'mandatory' => true],
        ['code' => 'SEAT_BELTS', 'label' => 'Sabuk Keselamatan', 'category' => 'SAFETY', 'critical' => true, 'mandatory' => true],
        ['code' => 'FIRST_AID_KIT', 'label' => 'Kotak P3K', 'category' => 'SAFETY', 'critical' => true, 'mandatory' => true],
        ['code' => 'FIRE_EXTINGUISHER', 'label' => 'APAR', 'category' => 'SAFETY', 'critical' => true, 'mandatory' => true],
        ['code' => 'EMERGENCY_EQUIPMENT', 'label' => 'Perlengkapan Darurat', 'category' => 'SAFETY', 'critical' => true, 'mandatory' => true],
        ['code' => 'CCTV', 'label' => 'CCTV', 'category' => 'EQUIPMENT', 'critical' => false, 'mandatory' => false],
        ['code' => 'GPS', 'label' => 'GPS', 'category' => 'EQUIPMENT', 'critical' => false, 'mandatory' => false],
        ['code' => 'DOCUMENTS', 'label' => 'Dokumen Kendaraan', 'category' => 'DOCUMENT', 'critical' => true, 'mandatory' => true],
    ],

    // §70 checklist pasca-trip
    'post_trip_items' => ['VEHICLE_CONDITION', 'FUEL', 'ODOMETER', 'DAMAGE', 'LOST_ITEMS',
        'PASSENGER_ISSUES', 'INCIDENT', 'CLEANING'],

    // §60 kategori biaya
    'cost_categories' => ['FUEL', 'DRIVER', 'TOLL', 'PARKING', 'MAINTENANCE', 'RENTAL', 'OTHER'],

    // §39/§86 kanal notifikasi & event
    'notification_channels' => ['IN_APP', 'PUSH', 'WHATSAPP', 'EMAIL', 'SMS'],
    'notification_events' => [
        'parent' => ['PassengerBoarded', 'PassengerNoShow', 'PassengerDroppedOff', 'TripDelayed', 'TripCancelled'],
        'admin' => ['VehicleFailure', 'DriverUnavailable', 'RouteCapacityExceeded', 'CriticalIncident', 'DocumentExpiry'],
        'driver' => ['TripAssignment', 'TripChanged', 'VehicleChanged', 'RouteChanged'],
    ],

    // §32/§87 aturan dispatch & validasi
    'dispatch' => [
        'require_driver' => true,
        'require_vehicle' => true,
        'require_route' => true,
        'require_valid_documents' => true,
        'require_driver_license' => true,
        'require_driver_qualification' => true,
        'require_pretrip_inspection' => true,
        'block_on_critical_pretrip_failure' => true,
        'block_capacity_overflow' => true,
        'allow_authorized_override' => true,
        'override_roles' => ['TRANSPORTATION_MANAGER', 'TRANSPORTATION_ADMIN', 'FOUNDATION_ADMIN', 'SUPER_ADMIN'],
    ],

    // §88 idempotensi
    'idempotency' => [
        'scopes' => ['BOARDING', 'DROPOFF', 'TRIP_START', 'TRIP_COMPLETE', 'FUEL_ENTRY', 'INCIDENT',
            'NOTIFICATION', 'ASSIGNMENT', 'DISPATCH', 'PRETRIP', 'POSTTRIP'],
        'ttl_days' => 90,
    ],

    // §38 QR boarding & §28 daftar tunggu
    'qr' => [
        'ttl_minutes' => 120,
        'secret_config' => 'app.key',
    ],
    'waitlist' => [
        'offer_ttl_hours' => 48,
        'priority_weights' => [
            'special_need' => 40,
            'boarding_status' => 20,
            'sibling' => 15,
            'grade_seniority' => 10,
            'waiting_time' => 15,
            'manual_priority' => 50,
        ],
    ],

    // §65 perencanaan kapasitas & §67 batasan rute
    'planning' => [
        'default_vehicle_capacity' => 40,
        'max_route_duration_minutes' => 120,
        'max_stops_per_route' => 30,
        'pickup_window_slack_minutes' => 10,
    ],

    // §89 offline-first driver app
    'offline' => [
        'manifest_cache_hours' => 24,
        'max_queue_size' => 500,
        'conflict_resolution' => 'SERVER_WINS_WITH_AUDIT',
    ],

    // §92 retensi data
    'retention' => [
        'trip_history_years' => 5,
        'manifest_years' => 5,
        'event_years' => 3,
        'gps_events_months' => 6,
        'incident_years' => 10,
    ],

    // Batas laju API
    'rate_limits' => [
        'module' => 180,
        'write' => 90,
        'driver_sync' => 240,
        'public_verify' => 20,
    ],

    // Kontak darurat (§48)
    'emergency' => [
        'contacts' => [
            ['key' => 'SCHOOL_SECURITY', 'label' => 'Keamanan Sekolah', 'phone' => null],
            ['key' => 'CLINIC', 'label' => 'Klinik', 'phone' => null],
            ['key' => 'MANAGEMENT', 'label' => 'Manajemen', 'phone' => null],
            ['key' => 'POLICE', 'label' => 'Kepolisian', 'phone' => '110'],
            ['key' => 'AMBULANCE', 'label' => 'Ambulans', 'phone' => '119'],
        ],
        'critical_escalation_minutes' => 15,
    ],

    // Integrasi: implementasi gateway (dapat ditimpa per lingkungan)
    'gateways' => [
        'student' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\EloquentStudentGateway::class,
        'parent' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\EloquentParentGateway::class,
        'employee' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\HrdEmployeeGateway::class,
        'attendance' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\ActivityAttendanceGateway::class,
        'boarding' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\ActivityBoardingGateway::class,
        'asset' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\AssetModuleGateway::class,
        'maintenance' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\MaintenanceGateway::class,
        'finance' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\FinancePostingGateway::class,
        'notification' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\DatabaseNotificationGateway::class,
        'facility' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\ConfigurableFacilityGateway::class,
        'event' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\EloquentEventGateway::class,
        'document' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\LocalDocumentGateway::class,
        'clinic' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\ConfigurableClinicGateway::class,
        'location' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\NullLocationGateway::class,
        'vendor' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\DatabaseVendorGateway::class,
        'route_optimization' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\NullRouteOptimizationGateway::class,
        'access' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\DatabaseAccessControlGateway::class,
        'workflow' => \App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\WorkflowModuleGateway::class,
    ],
];
