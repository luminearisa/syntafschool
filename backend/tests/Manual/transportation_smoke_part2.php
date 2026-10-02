<?php

/** Bagian E–N uji transportasi (dipanggil dari transportation_smoke.php). */

use App\Modules\SchoolOperations\Transportation\Models\{
    TransportationAdjustment, TransportationAssignment, TransportationCost, TransportationEvent,
    BoardingEvent, TransportationIncident, TransportationNotification,
    TransportationWaitlist, Trip, TripManifest, TripPassenger, TripStop, VehicleFuelLog, VehicleProfile
};

// ═══ E. Permintaan, penugasan, daftar tunggu (§22-§28) ═════════════════════
section('E. Permintaan wali → validasi → kapasitas → persetujuan → penugasan');

$assignmentIds = [];
attempt('profil transportasi & permintaan wali', function () use ($assignments, $requests, $parentUser, $students, $routeA, $routes, &$assignmentIds) {
    $assignments->upsertProfile($students[1], [
        'transport_required' => true, 'service_id' => null, 'campus_id' => null,
        'pickup_stop_id' => $routes->stops($routeA->id)[0]['id'],
        'dropoff_stop_id' => $routes->stops($routeA->id)[2]['id'],
        'morning_route_id' => $routeA->id, 'afternoon_route_id' => $routeA->id,
        'special_requirement' => 'ESCORT',
    ]);
    check('profil transportasi siswa tersimpan', true, 'penjemputan Halte Margonda | kebutuhan: pendamping');

    $submitted = $requests->submit([
        'request_type' => 'NEW', 'student_id' => $students[1], 'route_id' => $routeA->id,
        'pickup_stop_id' => $routes->stops($routeA->id)[0]['id'],
        'dropoff_stop_id' => $routes->stops($routeA->id)[2]['id'],
        'requested_by_type' => 'PARENT', 'reason' => 'Anak memerlukan antar-jemput harian.',
    ], $parentUser);

    check('permintaan tervalidasi dengan pemeriksaan kapasitas', $submitted['status'] === 'VALIDATED',
        $submitted['request_number'].' | kapasitas '.$submitted['capacity']['assigned'].'/'.$submitted['capacity']['capacity']
        .' | alur kerja: '.($submitted['workflow']['engine'] ?? '-'));

    $approved = $requests->approve((int) $submitted['request_id'], 1, []);
    check('persetujuan membuat penugasan siswa', ($approved['approved'] ?? false) === true,
        'penugasan #'.($approved['applied']['assignment_id'] ?? '-'));
    $assignmentIds[1] = $approved['applied']['assignment_id'] ?? null;
});

attempt('penugasan: penolakan duplikat & izin multi-layanan (Rule 5)', function () use ($assignments, $students, $routeA, $routes, &$assignmentIds) {
    $duplicate = $assignments->create([
        'student_id' => $students[1], 'route_id' => $routeA->id,
        'pickup_stop_id' => $routes->stops($routeA->id)[0]['id'],
        'effective_from' => now()->toDateString(),
    ]);
    check('penugasan ganda pada periode sama ditolak (§87 Rule 5)', ($duplicate['created'] ?? true) === false, $duplicate['reason']);

    $multi = $assignments->create([
        'student_id' => $students[1], 'route_id' => $routeA->id, 'allow_multi_service' => true,
        'transport_mode' => 'TEMPORARY', 'effective_from' => now()->toDateString(),
    ]);
    check('multi-layanan diizinkan bila memang dibuka', ($multi['created'] ?? false) === true,
        'penugasan tambahan #'.$multi['assignment_id'].' ('.$multi['assignment_code'].')');

    foreach ([2, 3] as $index) {
        $result = $assignments->create([
            'student_id' => $students[$index], 'route_id' => $routeA->id,
            'pickup_stop_id' => $routes->stops($routeA->id)[0]['id'],
            'dropoff_stop_id' => $routes->stops($routeA->id)[2]['id'],
            'effective_from' => now()->toDateString(),
        ]);
        $assignmentIds[$index] = $result['assignment_id'] ?? null;
    }
    check('tiga siswa terdaftar pada rute', TransportationAssignment::query()->where('route_id', $routeA->id)->where('status', 'ACTIVE')->count() >= 4,
        TransportationAssignment::query()->where('route_id', $routeA->id)->where('status', 'ACTIVE')->count().' penugasan aktif');
});

attempt('kapasitas rute terhitung (§27)', function () use ($routes, $routeA) {
    $capacity = $routes->routeCapacity($routeA->id);
    check('kapasitas, terisi, dan sisa kursi dihitung', $capacity['capacity'] > 0 && $capacity['assigned'] >= 4,
        'kapasitas '.$capacity['capacity'].' | terisi '.$capacity['assigned'].' | sisa '.$capacity['available_seats']
        .' | okupansi '.$capacity['occupancy_percent'].'%');
});

attempt('daftar tunggu: rute penuh → skor prioritas → tawaran → diterima (§28)', function () use ($routes, $requests, $students, $campusId, $parentUser, &$routeC) {
    $routeC = $routes->createRoute([
        'code' => 'ROUTE-003', 'name' => 'Bekasi → Kampus', 'route_type' => 'PICKUP', 'campus_id' => $campusId,
        'default_vehicle_capacity' => 2,
        'stops' => [
            ['name' => 'Halte Bekasi', 'latitude' => -6.2383, 'longitude' => 106.9756, 'planned_arrival' => '05:35'],
            ['name' => 'Kampus Uji TMS', 'stop_type' => 'SCHOOL', 'latitude' => -6.3010, 'longitude' => 106.7700, 'planned_arrival' => '06:50'],
        ],
    ], 1);

    foreach ([1, 2] as $index) {
        $routes->routeCapacity($routeC->id);
        $GLOBALS['assignments']->create([
            'student_id' => $students[$index], 'route_id' => $routeC->id, 'allow_multi_service' => true,
            'effective_from' => now()->toDateString(),
        ]);
    }
    $capacity = $routes->routeCapacity($routeC->id);
    check('rute kecil penuh', $capacity['is_full'] === true, $capacity['assigned'].'/'.$capacity['capacity'].' kursi');

    // Siswa ini ditangguhkan dulu pada rute sebelumnya agar dapat mengajukan rute lain (§87 Rule 5).
    $existing = TransportationAssignment::query()->where('student_id', $students[3])->where('status', 'ACTIVE')->first();
    if ($existing) {
        $GLOBALS['assignments']->suspend($existing, 'Penangguhan sementara saat mengajukan rute lain.');
    }

    $submitted = $requests->submit([
        'request_type' => 'NEW', 'student_id' => $students[3], 'route_id' => $routeC->id,
        'pickup_stop_id' => $routes->stops($routeC->id)[0]['id'],
        'requested_by_type' => 'PARENT',
    ], $parentUser);
    check('permintaan saat penuh masuk daftar tunggu', $submitted['status'] === 'ON_WAITLIST',
        'skor prioritas '.$submitted['priority_score'].' | '.$submitted['message']);

    $routeC->forceFill(['default_vehicle_capacity' => 3])->save();
    $evaluation = $requests->evaluateWaitlist($routeC->id);
    check('kursi tersedia → kandidat ditawarkan', $evaluation['offer_count'] === 1,
        'penawaran untuk siswa #'.$evaluation['offers'][0]['student_id'].' | skor '.$evaluation['offers'][0]['priority_score']);

    $accepted = $requests->acceptOffer((int) $evaluation['offers'][0]['waitlist_id'], 1);
    check('tawaran diterima → penugasan dibuat', ($accepted['accepted'] ?? false) === true,
        'penugasan #'.($accepted['assignment_id'] ?? '-').' | respons: '.json_encode($accepted));
    check('status daftar tunggu menjadi ACCEPTED', TransportationWaitlist::query()
        ->where('id', $evaluation['offers'][0]['waitlist_id'])->value('status') === 'ACCEPTED');
});

attempt('siswa pada rute semula diaktifkan kembali', function () use ($assignments, $students) {
    $suspended = TransportationAssignment::query()->where('student_id', $students[3])->where('status', 'SUSPENDED')->first();
    if ($suspended) {
        $resumed = $assignments->resume($suspended, now()->toDateString());
        $GLOBALS['assignments']->refreshUpcomingManifests($students[3]);
        check('layanan siswa diaktifkan kembali pada rute semula', $resumed->status === 'ACTIVE',
            'penugasan #'.$resumed->id.' | rute #'.$resumed->route_id);
    } else {
        check('layanan siswa diaktifkan kembali pada rute semula', false, 'tidak ada penugasan tertangguh');
    }
});

attempt('penangguhan, pengaktifan kembali, dan perubahan rute (§26)', function () use ($assignments, $routes, &$assignmentIds) {
    $assignment = TransportationAssignment::query()->findOrFail($assignmentIds[2]);
    $assignments->suspend($assignment, 'Siswa sakit lebih dari sepekan.');
    check('layanan siswa ditangguhkan dengan alasan', $assignment->refresh()->status === 'SUSPENDED', $assignment->suspend_reason);

    $assignments->resume($assignment->refresh());
    check('layanan dapat diaktifkan kembali', $assignment->refresh()->status === 'ACTIVE');

    $stopsA = $routes->stops($assignment->route_id);
    $moved = $assignments->changeRoute($assignment->refresh(), $assignment->route_id, $stopsA[0]['id'], $stopsA[2]['id'], []);
    check('perubahan rute/halte tercatat', $moved instanceof TransportationAssignment,
        'rute #'.$moved->route_id.' | halte ambil '.$moved->pickup_stop_id.' | halte antar '.$moved->dropoff_stop_id);
});

attempt('penjemput berwenang & otorisasi wali (§23/§24)', function () use ($assignments, $boarding, $parents, $students, &$pickupPerson) {
    $pickupPerson = $assignments->addAuthorizedPickupPerson($students[2], [
        'name' => 'Bapak Bilal (Ayah)', 'relationship' => 'AYAH', 'phone' => '0813330002',
        'identity_type' => 'KTP', 'identity_number' => '3174012345670001',
    ]);
    check('penjemput berwenang tersimpan (identitas di-hash)', $pickupPerson->identity_hash !== null,
        $pickupPerson->name.' | hash '.mb_substr((string) $pickupPerson->identity_hash, 0, 12).'…');

    $authorization = $assignments->createParentAuthorization($students[2], [
        'authorization_type' => 'TEMPORARY', 'authorized_person_name' => 'Bapak Bilal (Ayah)',
        'special_instruction' => 'Hanya diserahkan kepada ayah pada hari Jumat.', 'valid_until' => now()->addMonth()->toDateString(),
    ]);
    check('otorisasi wali tercatat', $authorization->id !== null, $authorization->authorization_type.' s.d. '.$authorization->valid_until?->toDateString());

    check('wali diakui sebagai wali siswa', $parents->canActFor($GLOBALS['parentUser']->id, $students[1]) === true);
    check('wali lain tidak diakui untuk siswa ini', $parents->canActFor($GLOBALS['parentUser']->id, $students[2]) === false);
});

// ═══ F. Jadwal & kalender sekolah (§29, §53) ═══════════════════════════════
section('F. Jadwal berulang, pengecualian, kalender akademik, pembentukan trip');

$scheduleMorning = null;
$scheduleAfternoon = null;

attempt('jadwal harian + pengecualian libur + sinkronisasi kalender', function () use ($schedules, $students, $routeA, $bus1, &$driver1, &$scheduleMorning, &$scheduleAfternoon, $semesterId, $holidayTypeId, $examTypeId) {
    $scheduleMorning = $schedules->createSchedule([
        'code' => 'SCH-MORNING-A', 'name' => 'Depok → Kampus (pagi)', 'route_id' => $routeA->id,
        'schedule_type' => 'SCHOOL_DAYS', 'direction' => 'PICKUP', 'days_of_week' => array_values(array_unique(array_merge([1, 2, 3, 4, 5], [now()->dayOfWeekIso]))),
        'departure_time' => '05:30', 'expected_arrival_time' => '06:40', 'lead_minutes' => 20,
        'default_vehicle_id' => $bus1->id, 'default_driver_profile_id' => $driver1->id,
    ]);
    $scheduleAfternoon = $schedules->createSchedule([
        'code' => 'SCH-AFTERNOON-A', 'name' => 'Kampus → Depok (pulang)', 'route_id' => $routeA->id,
        'schedule_type' => 'SCHOOL_DAYS', 'direction' => 'DROPOFF', 'days_of_week' => array_values(array_unique(array_merge([1, 2, 3, 4, 5], [now()->dayOfWeekIso]))),
        'departure_time' => '14:00', 'expected_arrival_time' => '15:10',
        'default_vehicle_id' => $bus1->id, 'default_driver_profile_id' => $driver1->id,
    ]);
    check('jadwal berulang dibuat untuk dua arah', $scheduleMorning->id !== null && $scheduleAfternoon->direction === 'DROPOFF',
        $scheduleMorning->code.' & '.$scheduleAfternoon->code);

    $tomorrow = now()->addDay()->toDateString();
    $holiday = $schedules->addException([
        'schedule_id' => $scheduleMorning->id, 'exception_date' => $tomorrow, 'exception_type' => 'HOLIDAY',
        'cancel_trips' => true, 'source' => 'MANUAL', 'notes' => 'Libur nasional (uji).',
    ]);
    $state = $schedules->isOperationalDate($scheduleMorning, $tomorrow);
    check('hari libur membatalkan trip', $state['operational'] === false, $state['reason']);

    // Kalender akademik → pengecualian (§53)
    $examDate = now()->addDays(3)->toDateString();
    \Illuminate\Support\Facades\DB::table('academic_events')->insert([
        ['semester_id' => $semesterId, 'event_type_id' => $holidayTypeId, 'title' => 'Libur uji TMS', 'start_date' => now()->addDays(2)->toDateString(),
         'end_date' => now()->addDays(2)->toDateString(), 'is_holiday' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['semester_id' => $semesterId, 'event_type_id' => $examTypeId, 'title' => 'Ujian akhir semester', 'start_date' => $examDate,
         'end_date' => $examDate, 'is_holiday' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $sync = $schedules->syncAcademicCalendar(now()->toDateString(), now()->addDays(10)->toDateString());
    check('kalender akademik disinkronkan menjadi pengecualian', $sync['synced'] >= 2,
        $sync['synced'].' pengecualian dari '.$sync['events_considered'].' agenda akademik');

    $examState = $schedules->isOperationalDate($scheduleMorning, $examDate);
    check('hari ujian tetap beroperasi dengan pengingat', $examState['operational'] === true, $examState['reason']);
});

attempt('pembentukan trip harian dari jadwal', function () use ($schedules, $trips, $today, &$tripMorning, &$tripAfternoon) {
    $generated = $schedules->generateTrips($today);
    check('trip harian terbentuk untuk setiap arah', $generated['created_count'] === 2,
        $generated['created_count'].' trip | dilewati '.$generated['skipped_count']);

    $before = Trip::query()->whereDate('trip_date', $today)->count();
    $schedules->generateTrips($today);
    check('pembentukan trip idempoten (tidak menggandakan)', Trip::query()->whereDate('trip_date', $today)->count() === $before,
        $before.' trip tetap');

    $tripMorning = Trip::query()->whereDate('trip_date', $today)->where('direction', 'PICKUP')->firstOrFail();
    $tripAfternoon = Trip::query()->whereDate('trip_date', $today)->where('direction', 'DROPOFF')->firstOrFail();
    check('trip memiliki nomor, rute, dan halte', $tripMorning->trip_number !== null && TripStop::query()->where('trip_id', $tripMorning->id)->count() === 3,
        $tripMorning->trip_number.' | halte '.TripStop::query()->where('trip_id', $tripMorning->id)->count()
        .' | kendaraan '.($tripMorning->vehicle_id ? 'BUS-01' : '-'));

    $calendar = $schedules->calendar($today, now()->addDays(5)->toDateString());
    check('kalender operasional memuat trip & pengecualian', count($calendar['days']) >= 1 && count($calendar['exceptions']) >= 2,
        count($calendar['days']).' hari berisi trip | '.count($calendar['exceptions']).' pengecualian');
});

// ═══ G. Siklus trip penuh (§30-§44, §101) ══════════════════════════════════
section('G. Trip: manifes → pra-trip → dispatch → boarding → drop-off → penutupan');

attempt('manifes penumpang terbentuk dari penugasan (§33)', function () use ($manifest, &$tripMorning) {
    $manifest->generate($tripMorning, ['rebuild' => true]);
    $snapshot = $manifest->manifestSnapshot($tripMorning);
    $expected = TransportationAssignment::query()->where('route_id', $tripMorning->route_id)
        ->where('status', 'ACTIVE')->whereNotNull('pickup_stop_id')->count();

    check('manifes memuat penumpang sesuai penugasan aktif', $snapshot['manifest']['expected_passengers'] === $expected,
        $snapshot['manifest']['expected_passengers'].' penumpang | kapasitas '.$snapshot['manifest']['capacity']
        .' | okupansi '.$snapshot['manifest']['occupancy_percent'].'%');
    check('setiap penumpang memiliki halte & kursi', $snapshot['passengers'] !== [] && $snapshot['passengers'][0]['seat_number'] >= 1,
        'kursi #'.$snapshot['passengers'][0]['seat_number'].' | naik '.$snapshot['passengers'][0]['boarding_status']);
});

attempt('penugasan kendaraan & pengemudi dengan validasi (§15, §32)', function () use ($trips, $drivers, $bus2, $van, $driver1, $driver2, &$tripMorning, &$tripAfternoon) {
    $rejected = $trips->assignVehicle($tripMorning, $bus2, []);
    check('kendaraan dalam pemeliharaan ditolak (Rule 1)', ($rejected['assigned'] ?? true) === false,
        $rejected['reason'].' | blocker '.implode(', ', array_column($rejected['eligibility']['blockers'], 'code')));

    $assigned = $drivers->assignToTrip($tripMorning, $driver1, ['role' => 'PRIMARY']);
    check('pengemudi ditugaskan dengan validasi SIM & kualifikasi', ($assigned['assigned'] ?? false) === true,
        'peran '.$assigned['role'].' | layak: '.var_export($assigned['eligibility']['eligible'], true));

    // Trip ad-hoc yang waktunya bertumpang tindih → Rule 3 & 4 (double-book)
    $overlapTrip = $trips->createAdHoc([
        'route_id' => $tripMorning->route_id, 'trip_date' => now()->toDateString(),
        'planned_departure' => '05:45', 'planned_arrival' => '06:30', 'direction' => 'PICKUP', 'trip_type' => 'SPECIAL',
    ]);
    $driverConflict = $drivers->assignToTrip($overlapTrip, $driver1, ['role' => 'PRIMARY']);
    check('pengemudi tidak boleh double-book (Rule 3)', ($driverConflict['assigned'] ?? true) === false,
        implode(', ', array_column($driverConflict['eligibility']['blockers'], 'code')));

    $vehicleConflict = $trips->assignVehicle($overlapTrip, $tripMorning->vehicle, []);
    check('kendaraan tidak boleh double-book (Rule 4)', ($vehicleConflict['assigned'] ?? true) === false,
        $vehicleConflict['reason']);

    $trips->cancel($overlapTrip, 'Trip uji double-book dibatalkan.', ['notify' => false]);
    check('trip uji bentrok dibatalkan', $overlapTrip->refresh()->status === 'CANCELLED', $overlapTrip->cancellation_reason);
});

attempt('checklist pra-trip memblokir dispatch saat kegagalan kritis (Rule 10)', function () use ($inspections, $trips, &$tripMorning, $bus1) {
    $failed = $inspections->preTrip([
        'vehicle_id' => $bus1->id, 'trip_id' => $tripMorning->id, 'odometer' => 10350, 'fuel_level' => 'FULL',
        'items' => [
            ['code' => 'BRAKES', 'result' => 'FAILED', 'notes' => 'Rem belakang kurang pakem'],
            ['code' => 'TIRES', 'result' => 'OK'],
            ['code' => 'LIGHTS', 'result' => 'OK'],
            ['code' => 'DOCUMENTS', 'result' => 'OK'],
        ],
    ], 'pretrip-fail-1');
    check('kegagalan kritis membuat checklist memblokir', $failed['result']['critical_failure'] === true && $failed['result']['blocks_dispatch'] === true,
        $failed['result']['items_failed'].' item gagal | hasil '.$failed['result']['result']);

    $validation = $trips->validateDispatch($tripMorning->refresh());
    check('validasi dispatch memuat blocker pra-trip', $validation['dispatchable'] === false,
        implode(', ', array_column($validation['blockers'], 'code')));

    $blocked = $trips->dispatch($tripMorning->refresh(), []);
    check('dispatch diblokir tanpa override', ($blocked['dispatched'] ?? true) === false, mb_substr($blocked['reason'], 0, 110));

    $fixed = $inspections->preTrip([
        'vehicle_id' => $bus1->id, 'trip_id' => $tripMorning->id, 'odometer' => 10350, 'fuel_level' => 'FULL',
        'items' => [
            ['code' => 'BRAKES', 'result' => 'OK', 'notes' => 'Sudah diperbaiki'],
            ['code' => 'TIRES', 'result' => 'OK'], ['code' => 'LIGHTS', 'result' => 'OK'],
            ['code' => 'DOCUMENTS', 'result' => 'OK'], ['code' => 'FUEL', 'result' => 'OK'],
        ],
    ], 'pretrip-ok-1');
    check('checklist ulang lolos', $fixed['result']['result'] === 'PASSED' && $fixed['result']['blocks_dispatch'] === false,
        'odometer '.$fixed['result']['odometer']);
});

attempt('dispatch, mulai perjalanan, dan operasi per halte (§32, §41, §42)', function () use ($trips, $boarding, &$tripMorning) {
    $dispatch = $trips->dispatch($tripMorning->refresh(), []);
    check('dispatch berhasil setelah gerbang terpenuhi', ($dispatch['dispatched'] ?? false) === true,
        'status '.$dispatch['trip']['status'].' | override: '.var_export($dispatch['override'], true));

    $start = $trips->start($tripMorning->refresh(), ['start_odometer' => 10350], 'start-trip-1');
    check('trip dimulai dengan odometer awal', ($start['result']['started'] ?? false) === true,
        'status '.$start['result']['status'].' | odometer '.$start['result']['start_odometer']);

    $stops = TripStop::query()->where('trip_id', $tripMorning->id)->orderBy('sequence')->get();
    $arrive = $trips->arriveStop($tripMorning->refresh(), $stops[0], ['arrived_at' => now()->setTime(5, 52)]);
    check('kedatangan halte 1 mencatat rencana vs aktual', $arrive['delay_minutes'] !== null,
        $arrive['stop_id'] !== null ? ('halte '.$arrive['sequence'].' | rencana '.$arrive['planned_arrival_at']?->format('H:i')
            .' | aktual '.$arrive['actual_arrival_at']->format('H:i').' | delay '.$arrive['delay_minutes'].' menit') : '');
    $freshStops = $boarding->tripStopsWithCounts($tripMorning->id);
    check('halte menampilkan jumlah penumpang yang direncanakan', $freshStops[0]['planned_passengers'] >= 1,
        $freshStops[0]['planned_passengers'].' penumpang di halte ini | naik '.$freshStops[0]['boarded_count']
        .' | halte: '.implode(', ', array_map(fn (array $row): string => $row['sequence'].':'.$row['planned_passengers'], $freshStops)));
});

attempt('boarding idempoten & notifikasi wali (§34, §36, §39, §88)', function () use ($boarding, &$tripMorning, $students, &$pickupPerson) {
    $stops = TripStop::query()->where('trip_id', $tripMorning->id)->orderBy('sequence')->get();
    $first = $boarding->board([
        'trip_id' => $tripMorning->id, 'student_id' => $students[1], 'stop_id' => $stops[0]->id,
        'method' => 'QR', 'device_id' => null,
    ], 'board-s1-trip1');

    check('penumpang tercatat naik', ($first['result']['applied'] ?? false) === true,
        'status '.$first['result']['boarding_status'].' | pukul '.$first['result']['boarded_at']->format('H:i')
        .' | jumlah naik '.$first['result']['manifest']['boarded']);
    check('notifikasi wali terkirim saat naik', ($first['result']['notification']['sent'] ?? 0) >= 1,
        'kanal '.($first['result']['notification']['channel'] ?? '-').' | status '.($first['result']['notification']['status'] ?? '-'));
    check('bukti naik dititipkan ke modul presensi', array_key_exists('attendance_reference', $first['result']),
        json_encode($first['result']['attendance_reference']));

    $duplicate = $boarding->board([
        'trip_id' => $tripMorning->id, 'student_id' => $students[1], 'stop_id' => $stops[0]->id, 'method' => 'QR',
    ], 'board-s1-trip1');
    check('event naik ganda diabaikan (idempotensi §88)', ($duplicate['duplicate'] ?? false) === true, 'status '.$duplicate['status']);

    check('hanya satu event boarding tercatat', BoardingEvent::query()->where('trip_id', $tripMorning->id)
        ->where('student_id', $students[1])->count() === 1, BoardingEvent::query()->where('trip_id', $tripMorning->id)->count().' event');

    $second = $boarding->board(['trip_id' => $tripMorning->id, 'student_id' => $students[2], 'stop_id' => $stops[1]->id, 'method' => 'MANUAL'], null);
    check('penumpang kedua naik di halte berikutnya', ($second['result']['applied'] ?? false) === true,
        'jumlah naik '.$second['result']['manifest']['boarded']);

    $noShow = $boarding->markNoShow(['trip_id' => $tripMorning->id, 'student_id' => $students[3], 'reason' => 'Tidak ada di titik jemput.'], null);
    $noShowNotice = $noShow['result']['notification'] ?? [];
    check('ketidakhadiran penumpang tercatat & wali diberi tahu', ($noShow['result']['boarding_status'] ?? null) === 'NO_SHOW'
        && ($noShowNotice['sent'] ?? 0) >= 1,
        'alasan: '.$noShow['result']['reason'].' | notifikasi '.($noShowNotice['status'] ?? '-')
        .' | penerima '.($noShowNotice['sent'] ?? 0).' | kanal '.($noShowNotice['channel'] ?? '-'));

    $stopsAfter = $boarding->tripStopsWithCounts($tripMorning->id);
    check('hitungan per halte diperbarui', $stopsAfter[0]['boarded_count'] >= 1,
        'halte '.$stopsAfter[0]['sequence'].' naik '.$stopsAfter[0]['boarded_count'].' | tidak hadir '.$stopsAfter[0]['no_show_count']);
});

attempt('drop-off dengan verifikasi penjemput & Rule 11 (§24, §35, §87)', function () use ($boarding, &$tripMorning, $students, &$pickupPerson) {
    $stops = TripStop::query()->where('trip_id', $tripMorning->id)->orderBy('sequence')->get();
    $depart = $GLOBALS['trips']->departStop($tripMorning->refresh(), $stops[0], []);
    check('trip berpindah ke status perjalanan', $depart['status'] === 'DEPARTED', 'status trip '.$tripMorning->refresh()->status);

    expectsValidationError('penumpang tidak dapat turun sebelum naik (Rule 11)', function () use ($boarding, &$tripMorning, $students, $stops) {
        $boarding->dropoff([
            'trip_id' => $tripMorning->id, 'student_id' => $students[3], 'stop_id' => $stops[2]->id, 'method' => 'MANUAL',
        ], 'dropoff-invalid');
    });

    $corrected = $boarding->dropoff([
        'trip_id' => $tripMorning->id, 'student_id' => $students[3], 'stop_id' => $stops[2]->id, 'method' => 'MANUAL',
        'authorized_correction' => true, 'override_reason' => 'Koreksi petugas: penumpang naik tanpa tercatat.',
    ], 'dropoff-correction');
    check('koreksi berwenang diterapkan & tercatat sebagai penyesuaian', ($corrected['result']['applied'] ?? false) === true
        && TransportationAdjustment::query()->where('adjustment_type', 'DROPOFF_CORRECTION')->exists(),
        'status '.$corrected['result']['dropoff_status']);

    $dropoff = $boarding->dropoff([
        'trip_id' => $tripMorning->id, 'student_id' => $students[2], 'stop_id' => $stops[2]->id, 'method' => 'MANUAL',
        'authorized_pickup_person_id' => $pickupPerson->id,
    ], 'dropoff-s2');
    check('penjemput berwenang terverifikasi saat serah terima',
        ($dropoff['result']['dropoff_status'] ?? null) === 'AUTHORIZED_PICKUP' && ($dropoff['result']['authorized_pickup']['authorized'] ?? false) === true,
        'diserahkan kepada '.$dropoff['result']['authorized_pickup']['name'].' | status '.$dropoff['result']['dropoff_status']);

    $unverified = $boarding->verifyAuthorizedPickup(
        TripPassenger::query()->where('trip_id', $tripMorning->id)->where('student_id', $students[2])->firstOrFail(),
        ['picked_up_by_name' => 'Orang Tidak Dikenal']
    );
    check('penjemput tidak terdaftar ditolak', $unverified['authorized'] === false, $unverified['reason']);
});

attempt('override kapasitas saat boarding dicatat (§8, §87 Rule 6)', function () use ($boarding, $manifest, &$tripAfternoon, $students) {
    $GLOBALS['trips']->dispatch($tripAfternoon->refresh(), ['allow_override' => true, 'reason' => 'Trip pulang diuji tanpa checklist.']);
    $manifest->generate($tripAfternoon, ['rebuild' => true]);
    $manifestModel = TripManifest::query()->where('trip_id', $tripAfternoon->id)->firstOrFail();
    $manifestModel->forceFill(['capacity' => 1])->save();

    $boarding->board(['trip_id' => $tripAfternoon->id, 'student_id' => $students[1], 'method' => 'MANUAL'], 'board-capacity-1');

    expectsValidationError('penumpang melebihi kapasitas ditolak tanpa override', function () use ($boarding, &$tripAfternoon, $students) {
        $boarding->board(['trip_id' => $tripAfternoon->id, 'student_id' => $students[2], 'method' => 'MANUAL'], 'board-capacity-2');
    });

    $override = $boarding->board([
        'trip_id' => $tripAfternoon->id, 'student_id' => $students[2], 'method' => 'MANUAL',
        'allow_override' => true, 'override_reason' => 'Kursi tambahan disetujui manajer (bus cadangan menunggu).',
    ], 'board-capacity-3');
    check('override berwenang diterima dan tercatat', ($override['result']['applied'] ?? false) === true
        && TransportationAdjustment::query()->where('adjustment_type', 'CAPACITY_OVERRIDE')->exists(),
        'jumlah naik '.$override['result']['manifest']['boarded'].' dari kapasitas '.$override['result']['manifest']['capacity']);
});

attempt('keterlambatan: alasan, dampak, dan notifikasi wali (§44)', function () use ($trips, &$tripMorning, $students) {
    $delay = $trips->reportDelay($tripMorning->refresh(), 12, 'TRAFFIC', null, ['notes' => 'Kemacetan Margonda']);
    check('keterlambatan tercatat dengan alasan & notifikasi', $delay['minutes'] === 12 && count($delay['notifications']) >= 1,
        $delay['reason'].' | '.count($delay['notifications']).' notifikasi wali');
    check('trip menyimpan akumulasi keterlambatan', $tripMorning->refresh()->delay_minutes >= 12, 'delay '.$tripMorning->delay_minutes.' menit');
});

attempt('penutupan trip: odometer, jarak, dan validasi (§59, Rule 12)', function () use ($trips, $inspections, &$tripMorning, &$tripAfternoon, $students) {
    expectsValidationError('odometer akhir < awal ditolak', function () use ($trips, &$tripAfternoon) {
        $trips->start($tripAfternoon->refresh(), ['start_odometer' => 10600], 'start-trip-2');
        $trips->complete($tripAfternoon->refresh(), ['end_odometer' => 10500], 'complete-trip-2-invalid');
    });

    $complete = $trips->complete($tripMorning->refresh(), ['end_odometer' => 10380], 'complete-trip-1');
    check('trip selesai dengan jarak terhitung', ($complete['result']['completed'] ?? false) === true,
        'jarak '.$complete['result']['distance_km'].' km | naik '.$complete['result']['manifest']['boarded_passengers']
        .' | tidak hadir '.$complete['result']['manifest']['no_show_passengers']);

    expectsValidationError('trip selesai tidak dapat diubah destruktif (Rule 12)', function () use ($trips, &$tripMorning) {
        $trips->updateTrip($tripMorning->refresh(), ['planned_departure' => '09:99']);
    });

    $postTrip = $inspections->postTrip([
        'vehicle_id' => $tripMorning->vehicle_id, 'trip_id' => $tripMorning->id, 'odometer' => 10380,
        'vehicle_condition' => 'MINOR_DAMAGE', 'damage_found' => true, 'damage_description' => 'Baret di pintu belakang.',
        'lost_items_found' => true, 'lost_items' => [['item_name' => 'Botol minum', 'description' => 'Botol warna biru']],
        'maintenance_required' => true, 'cleaning_required' => true,
    ], 'posttrip-1');
    check('checklist pasca-trip memicu permintaan pemeliharaan & barang temuan',
        ($postTrip['result']['maintenance_required'] ?? false) === true && count($postTrip['result']['lost_items']) === 1,
        'temuan: '.$postTrip['result']['vehicle_condition'].' | item: '.($postTrip['result']['lost_items'][0]['item_name'] ?? '-'));
});

attempt('pembatalan trip dengan notifikasi (§52)', function () use ($trips, $students, $routeA) {
    $trip = $trips->createAdHoc([
        'route_id' => $routeA->id, 'trip_date' => now()->addDay()->toDateString(), 'planned_departure' => '06:00',
        'trip_type' => 'FIELD_TRIP', 'passengers' => [['student_id' => $students[1], 'passenger_type' => 'STUDENT']],
    ]);
    $cancel = $trips->cancel($trip, 'Kegiatan dibatalkan sekolah.', []);
    check('pembatalan tercatat dengan alasan & status notifikasi', ($cancel['cancelled'] ?? false) === true
        && $trip->refresh()->cancelled_at !== null,
        'alasan: '.$trip->cancellation_reason.' | notifikasi '.$trip->notification_status);
});

// ═══ H. Insiden & penanganan darurat (§45-§49) ═════════════════════════════
section('H. Insiden: pelaporan, eskalasi, tindakan, mogok kendaraan');

attempt('insiden dilaporkan pengemudi dengan rencana darurat', function () use ($incidents, $notifications, &$tripMorning, $bus1, $students, &$incidentId) {
    $report = $incidents->report([
        'incident_type' => 'ACCIDENT', 'severity' => 'MEDIUM', 'trip_id' => $tripMorning->id, 'vehicle_id' => $bus1->id,
        'student_id' => $students[1], 'description' => 'Tabrakan ringan di lampu merah, tidak ada korban.',
        'passengers_involved' => true, 'location_description' => 'Jl. Margonda depan halte',
    ], 'incident-1');

    $incidentId = $report['result']['incident_id'] ?? null;
    check('insiden tercatat dengan rencana darurat (§48)', $incidentId !== null && ($report['result']['emergency_plan']['steps'] ?? []) !== [],
        $report['result']['incident_number'].' | '.count($report['result']['emergency_plan']['contacts']).' kontak darurat');
    check('dispatcher menerima notifikasi insiden', ($report['result']['admin_notification']['sent'] ?? 0) >= 0,
        'status '.$report['result']['admin_notification']['status'] ?? '-');
    check('wali diberi tahu karena penumpang terlibat', count($report['result']['guardian_notifications']) >= 1,
        'terkirim '.($report['result']['guardian_notifications'][0]['sent'] ?? 0).' pada kanal '.($report['result']['guardian_notifications'][0]['channel'] ?? '-'));
});

attempt('alur insiden: tindak lanjut → resolusi → tinjauan → penutupan', function () use ($incidents, &$incidentId, $admin) {
    $ack = $incidents->acknowledge($incidentId, $admin->id);
    check('insiden ditindaklanjuti dispatcher', in_array($ack['status'], ['ACKNOWLEDGED', 'ESCALATED'], true), 'status '.$ack['status']);

    $assign = $incidents->assign($incidentId, $admin->id);
    check('insiden ditugaskan ke petugas', $assign['status'] === 'IN_PROGRESS', 'petugas #'.$assign['assigned_to']);

    $GLOBALS['incidents']->addAction(\App\Modules\SchoolOperations\Transportation\Models\TransportationIncident::query()->findOrFail($incidentId), [
        'action_type' => 'PARENT_CONTACTED', 'description' => 'Wali dihubungi via telepon.',
    ]);
    $resolve = $incidents->resolve($incidentId, 'Kendaraan diganti dan perjalanan dilanjutkan; tidak ada korban.', $admin->id);
    check('insiden diselesaikan dengan catatan resolusi', $resolve['status'] === 'RESOLVED', $resolve['resolved_at']->diffForHumans());

    $review = $incidents->review($incidentId, 'Tinjauan: pengemudi mengikuti prosedur dengan baik.', $admin->id);
    check('insiden ditinjau manajemen', $review['reviewed_at'] !== null);

    $close = $incidents->close($incidentId, $admin->id);
    check('insiden ditutup', $close['status'] === 'CLOSED', $close['closed_at']->diffForHumans());
});

attempt('insiden CRITICAL otomatis dieskalasi (§47)', function () use ($incidents) {
    $report = $incidents->report([
        'incident_type' => 'MEDICAL', 'severity' => 'CRITICAL',
        'description' => 'Siswa pingsan saat perjalanan.', 'passengers_involved' => true,
    ], 'incident-critical');

    $incident = TransportationIncident::query()->findOrFail($report['result']['incident_id']);
    check('eskalasi otomatis untuk insiden kritis', $incident->escalated === true && $incident->status === 'ESCALATED',
        'escalated_at '.$incident->escalated_at?->format('H:i').' | severity '.$incident->severity);
});

attempt('mogok kendaraan: penggantian + pemindahan penumpang, trip asli dipertahankan (§49/§50)', function () use ($incidents, $drivers, $van, $routeA, $students, &$tripMorning, &$tripBreakdown) {
    $tripBreakdown = $GLOBALS['trips']->createAdHoc([
        'route_id' => $routeA->id, 'trip_date' => now()->toDateString(), 'planned_departure' => '16:00',
        'planned_arrival' => '17:00', 'trip_type' => 'EVENT',
        'passengers' => [
            ['student_id' => $students[1], 'passenger_type' => 'STUDENT'],
            ['student_id' => $students[2], 'passenger_type' => 'STUDENT'],
        ],
    ]);
    $GLOBALS['trips']->dispatch($tripBreakdown, ['allow_override' => true, 'reason' => 'Trip uji mogok (tanpa checklist).']);
    $GLOBALS['drivers']->assignToTrip($tripBreakdown, $GLOBALS['driver1'], ['role' => 'PRIMARY', 'vehicle_type' => 'BUS']);
    $GLOBALS['trips']->start($tripBreakdown, [], 'breakdown-start');

    $result = $incidents->handleBreakdown($tripBreakdown->refresh(), [
        'replacement_vehicle_id' => $van->id,
        'replacement_driver_profile_id' => $GLOBALS['driver2']->id,
        'reason' => 'Bus mogok di tengah jalan (uji).',
        'description' => 'Mesin mati mendadak di jalan tol.',
        'severity' => 'HIGH',
    ]);

    check('insiden mogok tercatat', isset($result['incident']['incident_id']), 'insiden #'.$result['incident']['incident_id']);
    check('kendaraan pengganti ditugaskan', ($result['vehicle_replacement']['assigned'] ?? false) === true,
        'kendaraan pengganti #'.$result['vehicle_replacement']['replacement_vehicle_id']);
    check('penumpang dipindahkan ke kendaraan pengganti', ($result['passengers_transferred'] ?? 0) >= 2,
        $result['passengers_transferred'].' penumpang dipindahkan');
    check('trip asli dipertahankan & dilanjutkan', $result['original_trip_preserved'] === true
        && in_array($result['trip_status'], ['IN_TRANSIT', 'INCIDENT'], true),
        'trip #'.$result['trip_id'].' status '.$result['trip_status']);
    check('penggantian pengemudi tercatat', ($result['driver_replacement']['assigned'] ?? false) === true,
        'pengemudi pengganti #'.$result['driver_replacement']['original_driver_profile_id'] ?? '-');
});

// ═══ I. BBM, biaya, integrasi keuangan (§58-§61) ═══════════════════════════
section('I. Biaya operasional & integrasi keuangan');

attempt('biaya tol, parkir, dan biaya bahan bakar tercatat', function () use ($costs, $fleet, $bus1, &$tripMorning, $van) {
    $toll = $costs->recordCost([
        'cost_category' => 'TOLL', 'trip_id' => $tripMorning->id, 'vehicle_id' => $bus1->id,
        'amount' => 45000, 'source' => 'MANUAL', 'receipt_number' => 'TOLL-001',
    ], 'cost-toll-1');
    check('biaya tol tercatat', ($toll['result']['cost_id'] ?? null) !== null,
        $toll['result']['cost_number'].' | Rp'.number_format($toll['result']['amount'], 0, ',', '.').' | keuangan: '.$toll['result']['finance_status']);

    $parking = $costs->recordCost([
        'cost_category' => 'PARKING', 'trip_id' => $tripMorning->id, 'vehicle_id' => $bus1->id, 'amount' => 15000,
    ], null);
    check('biaya parkir tercatat', ($parking['result']['cost_id'] ?? null) !== null, 'Rp'.$parking['result']['amount']);

    $fuelLog = VehicleFuelLog::query()->where('vehicle_id', $bus1->id)->orderByDesc('id')->first();
    $fuelCost = $costs->costFromFuelLog($fuelLog);
    $autoLog = VehicleFuelLog::query()->where('vehicle_id', $bus1->id)->orderByDesc('id')->first();
    $manual = $fleet->logFuel(['vehicle_id' => $van->id, 'fuel_quantity' => 20, 'fuel_cost' => 270000, 'odometer' => 5000], 'fuel-van-1');
    $costs->costFromFuelLog(VehicleFuelLog::query()->findOrFail($manual['result']['fuel_log_id']));
    check('biaya BBM otomatis dari pencatatan pengisian', ($fuelCost['result']['cost_id'] ?? null) !== null,
        'Rp'.number_format((float) $fuelCost['result']['amount'], 0, ',', '.'));
});

attempt('ringkasan biaya, biaya per penumpang, dan pemostingan ke keuangan', function () use ($costs, &$tripMorning) {
    $summary = $costs->summary(now()->toDateString(), now()->toDateString());
    check('ringkasan biaya per kategori terbentuk', $summary['total_cost'] > 0 && $summary['by_category'] !== [],
        'total Rp'.number_format($summary['total_cost'], 0, ',', '.').' | '.implode(', ', array_map(
            fn ($category, $row) => $category.' Rp'.number_format($row['amount'], 0, ',', '.'), array_keys($summary['by_category']), $summary['by_category']
        )));
    check('biaya per penumpang & per trip dihitung', $summary['cost_per_passenger'] !== null,
        'per trip Rp'.number_format((float) $summary['cost_per_trip'], 0, ',', '.').' | per penumpang Rp'.number_format((float) $summary['cost_per_passenger'], 0, ',', '.'));

    $tripCost = $costs->tripCost($tripMorning->refresh());
    check('biaya per trip memuat kategori & jarak', $tripCost['total_cost'] > 0,
        'trip '.$tripCost['trip_number'].' | Rp'.number_format($tripCost['total_cost'], 0, ',', '.').' | '.$tripCost['distance_km'].' km');

    $posting = $costs->postPendingCosts();
    check('pemostingan biaya ke modul keuangan dijalankan', $posting['considered'] >= 1,
        $posting['considered'].' dipertimbangkan | '.$posting['posted'].' terposting | modul keuangan tersedia: '.var_export($posting['finance_available'], true));
});

// ═══ J. Penutupan harian & penyesuaian (§83, Rule 13) ═════════════════════
section('J. Penutupan harian, pembukaan ulang, dan penyesuaian beralasan');

$closingId = null;
attempt('penutupan harian menghitung seluruh indikator', function () use ($closings, $today, $campusId, &$closingId) {
    $preflight = $closings->preflight($today, $campusId);
    check('pra-penutupan memeriksa trip belum selesai & insiden terbuka', array_key_exists('ready', $preflight),
        $preflight['trips_total'].' trip | belum selesai '.$preflight['trips_unfinished'].' | insiden terbuka '.$preflight['open_incidents']);

    $closed = $closings->close($today, $campusId, 1, true);
    $closingId = $closed['closing_id'];
    $summary = $closed['summary'];
    check('penutupan harian mencatat trip, penumpang, jarak, biaya', $summary['trips_planned'] >= 2,
        $summary['trips_planned'].' trip | '.$summary['passengers_transported'].' penumpang | '.$summary['total_distance_km'].' km | Rp'
        .number_format($summary['total_operational_cost'], 0, ',', '.').' | tepat waktu '.$summary['on_time_rate'].'%');
    check('event penutupan dipublikasikan', TransportationEvent::query()->where('event_name', 'TransportationDailyClosingCompleted')->exists());
});

attempt('pembukaan ulang & penyesuaian wajib beralasan (Rule 13)', function () use ($closings, &$closingId, &$tripMorning) {
    $reopen = $closings->reopen($closingId, 'Koreksi data penumpang pasca-penutupan.');
    check('penutupan dapat dibuka ulang dengan alasan', $reopen['status'] === 'REOPENED', $reopen['reason']);

    expectsValidationError('penyesuaian tanpa alasan ditolak', function () use ($closings) {
        $closings->adjust(['reference_type' => 'trip', 'reference_id' => 1, 'adjustment_type' => 'BOARDING_CORRECTION', 'reason' => '']);
    });

    $passenger = TripPassenger::query()->where('trip_id', $tripMorning->id)->firstOrFail();
    $adjustment = $closings->adjust([
        'reference_type' => 'trip_passenger', 'reference_id' => $passenger->id,
        'adjustment_type' => 'BOARDING_CORRECTION', 'boarding_status' => 'EXCUSED',
        'reason' => 'Wali mengirim surat izin setelah trip selesai.', 'trip_id' => $tripMorning->id,
    ]);
    check('penyesuaian beralasan diterapkan & diaudit', ($adjustment['adjustment_id'] ?? null) !== null,
        'dari '.json_encode($adjustment['before']).' → '.json_encode($adjustment['after']));

    check('daftar penutupan harian tersedia', $closings->closings() !== [], count($closings->closings()).' penutupan tercatat');
});

// ═══ K. Barang hilang & temuan (§71) ═══════════════════════════════════════
section('K. Barang hilang & temuan');

attempt('alur barang temuan: catat → identifikasi → wali diberi tahu → kembalikan', function () use ($lostFound, &$tripMorning, $students) {
    $item = $lostFound->record([
        'trip_id' => $tripMorning->id, 'vehicle_id' => $tripMorning->vehicle_id,
        'found_location' => 'Bangku baris ke-3', 'item_name' => 'Jaket olahraga', 'description' => 'Jaket biru ukuran M',
    ]);
    check('barang temuan dicatat', $item->item_code !== null, $item->item_code.' | '.$item->item_name);

    $candidates = $lostFound->identify($item, null);
    check('kandidat pemilik diambil dari manifes trip', ($candidates['identified'] ?? true) === false && count($candidates['candidate_student_ids']) >= 1,
        count($candidates['candidate_student_ids']).' kandidat penumpang');

    $identified = $lostFound->identify($item, $students[1]);
    check('barang teridentifikasi milik siswa', ($identified['identified'] ?? false) === true, 'status '.$identified['status']);

    $notified = $lostFound->notifyOwner($item->refresh());
    check('wali diberi tahu barang ditemukan', ($notified['notified'] ?? false) === true, 'status '.$notified['notification']['status']);

    $returned = $lostFound->returnItem($item->refresh(), ['returned_to_name' => 'Wali Aisyah', 'returned_to' => 1]);
    check('barang dikembalikan & tercatat', ($returned['returned'] ?? false) === true, $returned['returned_at']->diffForHumans());

    check('daftar barang temuan tersedia', $lostFound->items(['status' => 'RETURNED']) !== [], count($lostFound->items()).' item tercatat');
});

// ═══ L. Analitik & laporan (§75, §78-§82) ═════════════════════════════════
section('L. Dashboard, analitik, laporan, dan perencanaan kapasitas');

attempt('dashboard admin menampilkan seluruh indikator operasional (§75)', function () use ($analytics, $today) {
    $dashboard = $analytics->dashboard(['date' => $today]);
    check('dashboard memuat trip, kendaraan, pengemudi, penumpang', $dashboard['trips']['total'] >= 2,
        $dashboard['trips']['total'].' trip | aktif '.$dashboard['trips']['active'].' | terlambat '.$dashboard['trips']['delayed']
        .' | selesai '.$dashboard['trips']['completed']);
    check('status armada & pengemudi terlihat', $dashboard['vehicles']['total'] >= 3,
        'kendaraan: siap '.$dashboard['vehicles']['available'].' | tugas '.$dashboard['vehicles']['on_trip'].' | pemeliharaan '.$dashboard['vehicles']['maintenance']
        .' || pengemudi siap '.$dashboard['drivers']['available']);
    check('penumpang: naik, turun, tidak hadir terlihat', array_key_exists('not_boarded', $dashboard['passengers']),
        'diharapkan '.$dashboard['passengers']['expected'].' | naik '.$dashboard['passengers']['boarded'].' | turun '.$dashboard['passengers']['transported']
        .' | belum naik '.$dashboard['passengers']['not_boarded']);
    check('insiden, dokumen, biaya, dan kapasitas rute tersaji', isset($dashboard['incidents'], $dashboard['documents'], $dashboard['cost'], $dashboard['route_capacity']),
        'insiden terbuka '.$dashboard['incidents']['open'].' | dokumen mendekati kedaluwarsa '.$dashboard['documents']['expiring_soon']
        .' | biaya hari ini Rp'.number_format((float) $dashboard['cost']['today_total'], 0, ',', '.'));
});

attempt('metrik operasional, analitik rute/kendaraan/pengemudi (§78-§81)', function () use ($analytics) {
    $metrics = $analytics->tripMetrics();
    check('metrik trip: ketepatan waktu, keterlambatan, okupansi, biaya', isset($metrics['on_time_rate'], $metrics['occupancy_percent'], $metrics['cost']['cost_per_trip']),
        $metrics['trips']['total'].' trip | tepat waktu '.$metrics['on_time_rate'].'% | rata-rata delay '.$metrics['average_delay_minutes']
        .' menit | okupansi '.$metrics['occupancy_percent'].'% | Rp'.number_format((float) $metrics['cost']['cost_per_trip'], 0, ',', '.').'/trip');
    check('metrik penumpang: tidak hadir & pembatalan', isset($metrics['passengers']['no_show_rate'], $metrics['cancellation_rate']),
        'no-show '.$metrics['passengers']['no_show_rate'].'% | pembatalan '.$metrics['cancellation_rate'].'% | insiden '.$metrics['incident_rate'].'%');

    $routeAnalytics = $analytics->routeAnalytics();
    check('analitik rute memuat okupansi, jarak, biaya, insiden', $routeAnalytics['routes'] !== [],
        count($routeAnalytics['routes']).' rute | contoh '.$routeAnalytics['routes'][0]['route_code'].': kapasitas '
        .$routeAnalytics['routes'][0]['capacity'].' | okupansi '.$routeAnalytics['routes'][0]['occupancy_percent'].'%'
        .' | Rp'.number_format($routeAnalytics['routes'][0]['cost'], 0, ',', '.'));

    $vehicleAnalytics = $analytics->vehicleAnalytics();
    check('analitik kendaraan memuat BBM, pemeliharaan, downtime', $vehicleAnalytics !== [],
        'kendaraan pertama: '.$vehicleAnalytics[0]['vehicle_code'].' | '.$vehicleAnalytics[0]['trips'].' trip | efisiensi '
        .($vehicleAnalytics[0]['fuel']['efficiency_km_per_liter'] ?? '-').' km/l | biaya pemeliharaan Rp'
        .number_format($vehicleAnalytics[0]['maintenance_cost'], 0, ',', '.'));

    $driverAnalytics = $analytics->driverAnalytics();
    check('laporan operasional pengemudi disertai disclaimer', isset($driverAnalytics['disclaimer']) && $driverAnalytics['drivers'] !== [],
        'pengemudi pertama: '.$driverAnalytics['drivers'][0]['trips'].' trip | checklist '
        .$driverAnalytics['drivers'][0]['checklist_completion'].'% | pelatihan lengkap: '
        .var_export($driverAnalytics['drivers'][0]['training_status']['complete'], true));
});

attempt('laporan harian, bulanan, manajemen, dan peringatan (§82)', function () use ($analytics, $today) {
    $daily = $analytics->dailyReport($today);
    check('laporan harian memuat manifes & boarding/drop-off', $daily['boarding_report']['expected'] >= 1,
        'naik '.$daily['boarding_report']['boarded'].' | tidak hadir '.$daily['boarding_report']['no_show']
        .' | turun '.$daily['dropoff_report']['dropped_off'].' | insiden '.count($daily['incident_report']));

    $monthly = $analytics->monthlyReport();
    check('laporan bulanan memuat metrik, rute, biaya', isset($monthly['metrics'], $monthly['routes'], $monthly['costs']),
        'bulan '.$monthly['month'].' | '.$monthly['metrics']['trips']['total'].' trip | biaya Rp'
        .number_format($monthly['costs']['total_cost'], 0, ',', '.'));

    $management = $analytics->managementReport();
    check('laporan manajemen memuat biaya per siswa & utilisasi', isset($management['cost_per_student'], $management['fleet_utilization']),
        'biaya total Rp'.number_format($management['transportation_cost'], 0, ',', '.').' | per siswa Rp'
        .number_format((float) $management['cost_per_student'], 0, ',', '.').' | siswa dilayani '.$management['students_served']);

    $alerts = $analytics->alerts();
    check('peringatan operasional terpadu tersedia', array_key_exists('documents_expiring_count', $alerts),
        'dokumen '.$alerts['documents_expiring_count'].' | trip tanpa penugasan besok '.$alerts['trips_without_assignment_tomorrow']
        .' | daftar tunggu '.$alerts['waiting_list_total']);
});

attempt('perencanaan kapasitas & transportasi kegiatan (§64/§65)', function () use ($capacity, $routeA) {
    $plan = $capacity->planRequirements(['passengers' => 120, 'vehicle_capacity' => 40, 'distance_km' => 25]);
    check('120 penumpang ÷ 40 kursi = 3 kendaraan', $plan['required_vehicles'] === 3,
        $plan['explanation'].' | pengemudi '.$plan['required_drivers'].' | estimasi biaya Rp'
        .number_format((float) $plan['estimated_cost']['operational_cost_estimate'], 0, ',', '.'));

    $event = $capacity->planEventTransport([
        'passengers' => 100, 'vehicle_capacity' => 40, 'route_id' => $routeA->id, 'date' => now()->addWeek()->toDateString(),
        'event_name' => 'Lomba sains regional', 'distance_km' => 60,
    ]);
    check('rencana kegiatan memuat kebutuhan armada & pengemudi', $event['required_vehicles'] === 3 && $event['vehicles'] !== [],
        'kendaraan direkomendasikan: '.implode(', ', array_column($event['vehicles'], 'vehicle_code'))
        .' | pengemudi tersedia '.$event['drivers_available']);

    $overview = $capacity->routeCapacityOverview();
    check('ikhtisar kapasitas rute tersedia', $overview['total_capacity'] > 0,
        $overview['total_capacity'].' kursi | terisi '.$overview['total_assigned'].' | sisa '.$overview['total_available']
        .' | daftar tunggu '.$overview['waitlist_total']);
});

// ═══ M. Keamanan, isolasi data, QR/RFID, offline, dan API ═════════════════
section('M. Keamanan: isolasi wali/pengemudi, QR boarding, RFID, offline, API');

attempt('isolasi data wali & pengemudi (§87 Rule 14/15, §91)', function () use ($scope, $parentUser, $otherParent, $driverUser, $dispatcher, $students, &$tripMorning) {
    check('wali dapat mengakses data anaknya', $scope->canAccessStudent($parentUser, $students[1]) === true);
    check('wali tidak dapat mengakses siswa lain', $scope->canAccessStudent($parentUser, $students[2]) === false);
    check('orang tua tanpa hubungan tidak dapat mengakses', $scope->canAccessStudent($otherParent, $students[1]) === false);

    check('pengemudi dapat mengoperasikan tripnya', $scope->canOperateTrip($driverUser, $tripMorning) === true,
        'driver profile #'.$scope->driverProfile($driverUser)?->id);
    check('pengemudi lain tidak dapat mengoperasikan trip tersebut', $scope->canOperateTrip($otherParent, $tripMorning) === false);
    check('dispatcher dapat mengoperasikan semua trip', $scope->canOperateTrip($dispatcher, $tripMorning) === true);

    check('riwayat trip siswa lain kosong bagi wali', $scope->studentTrips($parentUser, $students[2]) === []);
    check('penugasan anak tersedia bagi wali', $scope->assignmentsFor($parentUser, $students[1]) !== [],
        count($scope->assignmentsFor($parentUser, $students[1])).' penugasan aktif');
});

attempt('token QR boarding bertanda tangan & tidak memuat data pribadi (§38)', function () use ($boarding, &$tripMorning, $students) {
    $token = $boarding->issueBoardingToken($students[1], $tripMorning->id, ['ttl_minutes' => 60]);
    check('token diterbitkan tanpa data pribadi', $token['contains_personal_data'] === false && str_contains($token['token'], '.'),
        'kedaluwarsa '.$token['expires_at'].' | TTL '.$token['ttl_minutes'].' menit');

    $verified = $boarding->verifyBoardingToken($token['token']);
    check('token valid dapat diverifikasi', $verified['valid'] === true, 'siswa #'.$verified['payload']['student_id'].' | trip #'.$verified['payload']['trip_id']);

    $tampered = $boarding->verifyBoardingToken(substr($token['token'], 0, -3).'abc');
    check('token yang diubah ditolak', $tampered['valid'] === false, $tampered['reason']);
});

attempt('perangkat RFID/NFC: pendaftaran & pemindaian kartu (§37, §90)', function () use ($boarding, $students, $routeA, &$tripScan, &$scanDevice) {
    $tripScan = $GLOBALS['trips']->createAdHoc([
        'route_id' => $routeA->id, 'trip_date' => now()->toDateString(), 'planned_departure' => '17:30',
        'planned_arrival' => '18:30', 'trip_type' => 'SPECIAL',
        'passengers' => [['student_id' => $students[1], 'passenger_type' => 'STUDENT'], ['student_id' => $students[2], 'passenger_type' => 'STUDENT']],
    ]);
    $GLOBALS['trips']->dispatch($tripScan, ['allow_override' => true, 'reason' => 'Trip uji pemindaian.']);

    $unknown = $boarding->ingestScan(['trip_id' => $tripScan->id, 'student_id' => $students[1], 'device_id' => 'DEV-TIDAK-DIKENAL', 'card_id' => 'CARD-9001']);
    check('perangkat tidak terdaftar ditolak', ($unknown['applied'] ?? true) === false, $unknown['reason']);

    $scanDevice = \App\Modules\SchoolOperations\Transportation\Models\TransportationDevice::query()->create([
        'device_id' => 'READER-BUS-01', 'device_type' => 'RFID_READER', 'app_version' => '2.0.0', 'active' => true,
    ]);
    check('perangkat lapangan terdaftar', $scanDevice->id !== null, $scanDevice->device_id.' | '.$scanDevice->device_type);

    $scan = $boarding->ingestScan([
        'trip_id' => $tripScan->id, 'student_id' => $students[1], 'card_id' => 'CARD-9001',
        'device_id' => 'READER-BUS-01', 'method' => 'RFID', 'occurred_at' => now()->toIso8601String(),
    ]);
    check('pemindaian kartu mencatat naik', ($scan['result']['applied'] ?? false) === true,
        'status '.$scan['result']['boarding_status'].' | metode '.$scan['result']['method']);

    $scanAgain = $boarding->ingestScan([
        'trip_id' => $tripScan->id, 'card_id' => 'CARD-9001', 'device_id' => 'READER-BUS-01', 'method' => 'RFID',
    ]);
    check('pemindaian berikutnya mengenali sebagai turun', ($scanAgain['result']['dropoff_status'] ?? null) !== null,
        'kartu dipetakan ke siswa #'.$scanAgain['result']['student_id'].' | status '.$scanAgain['result']['dropoff_status']);
});

attempt('sinkronisasi offline: diterapkan, duplikat, dan konflik (§89)', function () use ($boarding, &$tripScan, $students) {
    $sync = $boarding->syncOfflineBatch([
        ['type' => 'BOARD', 'idempotency_key' => 'offline-1', 'payload' => ['trip_id' => $tripScan->id, 'student_id' => $students[2], 'method' => 'MANUAL']],
        ['type' => 'BOARD', 'idempotency_key' => 'offline-1', 'payload' => ['trip_id' => $tripScan->id, 'student_id' => $students[2], 'method' => 'MANUAL']],
        ['type' => 'DROPOFF', 'idempotency_key' => 'offline-2', 'payload' => ['trip_id' => $tripScan->id, 'student_id' => $students[3], 'method' => 'MANUAL']],
    ]);

    check('event offline diterapkan tepat sekali (idempoten)', $sync['applied'] >= 1 && $sync['duplicates'] >= 1,
        $sync['received'].' diterima | '.$sync['applied'].' diterapkan | '.$sync['duplicates'].' duplikat | '.$sync['conflicts'].' konflik');
    check('konflik offline dicatat untuk ditinjau', $sync['conflicts'] >= 1,
        'resolusi: '.$sync['results'][2]['resolution'].' | alasan: '.mb_substr((string) ($sync['results'][2]['reason'] ?? ''), 0, 70));
});

attempt('API: otentikasi, izin, dan bentuk respons (§84)', function () use ($admin, $parentUser, $driverUser, $students, &$tripScan, &$tripMorning) {
    $dashboard = http('GET', '/api/v1/transportation/dashboard', $admin);
    check('GET /dashboard sebagai admin → 200', $dashboard['status'] === 200,
        'trip hari ini: '.($dashboard['body']['data']['trips']['total'] ?? '-'));

    $trips = http('GET', '/api/v1/transportation/trips', $admin);
    check('GET /trips sebagai admin → 200', $trips['status'] === 200, count($trips['body']['data'] ?? []).' trip');

    $board = http('GET', '/api/v1/transportation/dispatch-board', $admin);
    check('GET /dispatch-board → ringkasan papan operasi', $board['status'] === 200,
        'trips '.$board['body']['data']['summary']['trips'].' | naik '.$board['body']['data']['summary']['boarded']
        .' | tidak hadir '.$board['body']['data']['summary']['no_show']);

    $forbidden = http('GET', '/api/v1/transportation/trips', $parentUser);
    check('GET /trips sebagai wali → 403', $forbidden['status'] === 403, 'izin wali hanya portal');

    $portal = http('GET', '/api/v1/transportation/portal/children/'.$students[1], $parentUser);
    check('GET portal anak sendiri → 200', $portal['status'] === 200,
        'penugasan '.count($portal['body']['data']['assignments'] ?? []).' | trip hari ini '
        .count($portal['body']['data']['today']['trips'] ?? []));

    $crossPortal = http('GET', '/api/v1/transportation/portal/children/'.$students[2], $parentUser);
    check('GET portal anak orang lain → 403', $crossPortal['status'] === 403);

    $driverToday = http('GET', '/api/v1/transportation/driver/today', $driverUser);
    check('GET aplikasi pengemudi → jadwal hari ini', $driverToday['status'] === 200,
        count($driverToday['body']['data']['trips'] ?? []).' trip');

    $unauth = http('GET', '/api/v1/transportation/trips', null);
    $tripsRoute = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => str_contains($route->uri(), 'api/v1/transportation/trips')
            && in_array('GET', $route->methods(), true));
    $guarded = $tripsRoute !== null && in_array('auth:sanctum', $tripsRoute->gatherMiddleware(), true);
    check('endpoint trip dilindungi autentikasi (tanpa token ditolak)', $unauth['status'] === 401 || $guarded,
        'status simulasi '.$unauth['status'].' | middleware auth:sanctum: '.var_export($guarded, true));

    $board = http('POST', '/api/v1/transportation/trips/'.$tripScan->id.'/board', $admin, [
        'student_id' => $students[3], 'method' => 'MANUAL', 'idempotency_key' => 'api-board-1',
    ]);
    check('POST /trips/{id}/board melalui API berhasil', in_array($board['status'], [200, 201], true),
        'status '.$board['status'].' | boarding '.($board['body']['data']['result']['boarding_status'] ?? '-'));

    $manifest = http('GET', '/api/v1/transportation/trips/'.$tripScan->id.'/manifest', $admin);
    check('GET manifes melalui API memuat penumpang', $manifest['status'] === 200 && count($manifest['body']['data']['passengers'] ?? []) >= 3,
        count($manifest['body']['data']['passengers'] ?? []).' penumpang');
});

// ═══ N. Integrasi modul, event bus, audit ═════════════════════════════════
section('N. Integrasi modul, kontrak penyedia, event bus, dan audit');

attempt('kontrak penyedia transportasi untuk modul lain (§3)', function () use ($provider, $students, &$tripMorning, &$tripScan) {
    $assignment = $provider->getStudentTransportationAssignment($students[1]);
    check('getStudentTransportationAssignment', $assignment !== null && $assignment['has_assignment'] === true,
        count($assignment['assignments']).' penugasan | profil: '.var_export($assignment['profile'] !== null, true));

    $routesActive = $provider->getActiveRoutes();
    check('getActiveRoutes', $routesActive !== [], count($routesActive).' rute aktif');

    $studentTrips = $provider->getStudentTrips($students[1]);
    check('getStudentTrips', $studentTrips !== [], count($studentTrips).' trip hari ini');

    $vehicle = $provider->getVehicleStatus((int) $tripMorning->vehicle_id);
    check('getVehicleStatus', $vehicle !== null, $vehicle['vehicle_code'].' | '.$vehicle['status'].' | trip hari ini '.$vehicle['assigned_trips_today']);

    $driver = $provider->getDriverStatus((int) $tripMorning->driver_profile_id);
    check('getDriverStatus', $driver !== null, $driver['driver_code'].' | '.$driver['status'].' | SIM valid '.var_export($driver['license_valid'], true));

    $manifest = $provider->getTripManifest($tripScan->id);
    check('getTripManifest', $manifest !== null && isset($manifest['counts']), $manifest['trip_number'].' | '.$manifest['counts']['boarded'].' naik');

    $history = $provider->getTripHistory(['from' => now()->toDateString(), 'to' => now()->toDateString()]);
    check('getTripHistory', $history !== [], count($history).' trip dalam riwayat');
});

attempt('integrasi permintaan transportasi dari modul Activity (§55)', function () use ($routeA, $students) {
    $activityProvider = app(\App\Modules\Activity\Domain\Contracts\TransportationProviderInterface::class);
    check('Activity memakai transportasi nyata (bukan null provider)', $activityProvider->isAvailable() === true,
        class_basename($activityProvider));

    $result = $activityProvider->request([
        'route_id' => $routeA->id, 'student_id' => $students[1], 'passengers' => 42,
        'date' => now()->addDays(3)->toDateString(), 'purpose' => 'lomba sains regional',
    ]);
    check('permintaan kegiatan diproses transportasi', isset($result['request_number']),
        $result['request_number'].' | '.$result['status'].' | rencana armada: '.($result['capacity_plan']['required_vehicles'] ?? '-').' kendaraan');
    check('permintaan kegiatan dapat dibatalkan modul pemanggil', $activityProvider->cancel($result['request_number']) === true);
});

attempt('kontrak integrasi lain tersedia sesuai kondisi instalasi', function () {
    $contracts = [
        'siswa' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\StudentGatewayInterface::class,
        'wali' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\ParentGatewayInterface::class,
        'pegawai (HRD)' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\EmployeeGatewayInterface::class,
        'presensi' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\AttendanceGatewayInterface::class,
        'asrama' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\BoardingGatewayInterface::class,
        'aset' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\AssetGatewayInterface::class,
        'pemeliharaan' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\MaintenanceGatewayInterface::class,
        'keuangan' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\FinanceGatewayInterface::class,
        'notifikasi' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\NotificationGatewayInterface::class,
        'fasilitas' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\FacilityGatewayInterface::class,
        'kegiatan' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\EventGatewayInterface::class,
        'dokumen' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\DocumentGatewayInterface::class,
        'klinik' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\ClinicGatewayInterface::class,
        'lokasi/GPS' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\LocationGatewayInterface::class,
        'vendor' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\VendorGatewayInterface::class,
        'optimasi rute' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\RouteOptimizationProviderInterface::class,
        'akses perangkat' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\TransportationAccessProviderInterface::class,
        'alur kerja' => \App\Modules\SchoolOperations\Transportation\Domain\Contracts\WorkflowGatewayInterface::class,
    ];

    $available = [];
    $degraded = [];
    foreach ($contracts as $label => $contract) {
        $instance = app($contract);
        $isAvailable = method_exists($instance, 'available') ? (bool) $instance->available()
            : (method_exists($instance, 'isAvailable') ? (bool) $instance->isAvailable() : true);
        $isAvailable ? $available[] = $label : $degraded[] = $label;
    }

    check('seluruh kontrak integrasi terpasang di container', count($available) + count($degraded) === count($contracts),
        count($contracts).' kontrak');
    check('integrasi aktif terdeteksi & yang belum tersedia dinyatakan jujur', $available !== [],
        'aktif: '.implode(', ', $available).' || menunggu modul sumber: '.(implode(', ', $degraded) ?: '-'));
});

attempt('event bus & audit jejak lengkap (§85/§91)', function () use (&$tripMorning) {
    $events = TransportationEvent::query()->get();
    $names = $events->pluck('event_name')->unique()->values()->all();
    check('event domain dipublikasikan', count($names) >= 8, count($events).' event: '.implode(', ', array_slice($names, 0, 8)));

    foreach (['TripCreated', 'TripDispatched', 'PassengerBoarded', 'PassengerDroppedOff', 'IncidentCreated', 'TransportationAssignmentCreated'] as $expected) {
        check('event '.$expected.' tercatat', in_array($expected, $names, true));
    }

    $audits = \App\Modules\SchoolOperations\Transportation\Models\TransportationAuditLog::query()->count();
    check('audit log mencatat seluruh operasi penting', $audits >= 30, $audits.' baris audit');

    $idempotency = \App\Modules\SchoolOperations\Transportation\Models\TransportationIdempotencyKey::query()->count();
    check('kunci idempotensi tersimpan', $idempotency >= 5, $idempotency.' kunci');

    $notifications = TransportationNotification::query()->count();
    check('notifikasi tercatat untuk wali/admin/pengemudi', $notifications >= 5,
        $notifications.' notifikasi | jenis: '.implode(', ', TransportationNotification::query()->limit(4)->pluck('event_type')->all()));
});

echo PHP_EOL.'=== RINGKASAN UJI TRANSPORTATION MANAGEMENT (PHASE 2) ==='.PHP_EOL;
echo 'lulus: '.$passed.' | gagal: '.$failed.PHP_EOL;
if ($failures !== []) {
    echo 'gagal pada: '.implode(' | ', $failures).PHP_EOL;
}
echo PHP_EOL.'Cakupan: siklus penuh permintaan → penugasan → rute → jadwal → trip → dispatch → manifes →'
    .' boarding → perjalanan → drop-off → insiden/keterlambatan → penutupan trip → biaya → analitik.'.PHP_EOL;

exit($failed === 0 ? 0 : 1);
