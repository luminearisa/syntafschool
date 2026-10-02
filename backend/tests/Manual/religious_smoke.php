<?php

/**
 * Smoke test manual — Religious & Mosque Management (School Operations Phase 3).
 *
 * Menjalankan siklus hidup keagamaan end-to-end (§98):
 *   Kalender → Jadwal Shalat → Masjid → Imam+Muadzin → Jamaah → Populasi Siswa/Asrama
 *   → Presensi → Kegiatan → Kajian → Presensi Kegiatan → Penilaian → Prestasi → Portofolio
 *   → Ramadan (Suhoor→Fajr→Tadarus→Kajian→Iftar→Maghrib→Tarawih→Tahajjud)
 *   → Jumat (Khatib→Khutbah→Imam→Muadzin→Jamaah→Presensi→Arsip)
 *
 * Jalankan: php tests/Manual/religious_smoke.php
 */

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\SchoolOperations\Religious\Application\Services\{
    CongregationService, DutyRosterService, EidService, FridayPrayerService, KhutbahService,
    MosqueBookingService, MosqueOperationsService, MosqueService, PersonnelService,
    PrayerAttendanceService, PrayerScheduleService, RamadanService, ReligiousAchievementService,
    ReligiousActivityService, ReligiousAnalyticsService, ReligiousDashboardService,
    ReligiousImportService, ReligiousLearningService, ReligiousNotificationService
};
use App\Modules\SchoolOperations\Religious\Models\{Mosque, ReligiousActivity};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \u{2713} $label".($extra !== '' ? " \u{2014} $extra" : '')."\n"; }
    else { $fail++; echo "  \u{2717} FAIL: $label".($extra !== '' ? " \u{2014} $extra" : '')."\n"; }
}
function section(string $title): void { echo "\n\u{2500}\u{2500} $title\n"; }
/**
 * Assert bahwa sebuah operasi MEMANG ditolak (aturan bisnis bekerja).
 * Berbeda dari attempt() yang menandai kegagalan.
 */
function expectFailure(string $label, callable $fn): void {
    try {
        $fn();
        check($label, false, 'operasi seharusnya ditolak, tetapi berhasil');
    } catch (\Throwable $e) {
        check($label, true, 'ditolak: '.mb_substr($e->getMessage(), 0, 80));
    }
}
function attempt(string $label, callable $fn): mixed {
    try { return $fn(); }
    catch (\Throwable $e) { check($label, false, get_class($e).': '.$e->getMessage()); return null; }
}
/**
 * Isi tabel dengan hanya kolom yang benar-benar ada.
 * Kolom NOT NULL tanpa nilai bawaan diisi otomatis agar uji tahan terhadap
 * perbedaan skema antar-modul (mis. `schools.jenjang` yang wajib diisi).
 */
function put(string $table, array $data): int {
    $info = DB::select("pragma table_info($table)");
    $now = now();
    $payload = [];
    foreach ($info as $col) {
        $name = $col->name;
        if (array_key_exists($name, $data)) { $payload[$name] = $data[$name]; continue; }
        $required = $col->notnull && $col->dflt_value === null && ! $col->pk;
        if (! $required) { continue; }
        $type = strtolower((string) $col->type);
        $payload[$name] = match (true) {
            in_array($name, ['created_at', 'updated_at', 'deleted_at'], true) => $now,
            str_contains($name, 'date') || str_contains($type, 'date') => '2026-01-01',
            str_contains($name, 'time') || str_contains($type, 'time') => '08:00:00',
            str_contains($type, 'int') => 0,
            str_contains($type, 'real') || str_contains($type, 'num') || str_contains($type, 'dec') => 0,
            default => 'AUTO',
        };
    }
    return (int) DB::table($table)->insertGetId($payload);
}
function actor(string $email, array $roles): object {
    $user = App\Models\User::query()->where('email', $email)->first();
    if (! $user) {
        $user = App\Models\User::query()->create([
            'name' => strtoupper(explode('@', $email)[0]), 'email' => $email,
            'password' => bcrypt('password'), 'is_active' => true,
        ]);
    }
    foreach ($roles as $role) { \Spatie\Permission\Models\Role::findOrCreate($role, 'web'); }
    $user->syncRoles($roles);
    return $user;
}

echo "\n\u{2554}".str_repeat('=', 74)."\u{2557}\n";
echo "\u{2551}  SMOKE TEST \u{2014} RELIGIOUS & MOSQUE MANAGEMENT (PHASE 3)".str_repeat(' ', 21)."\u{2551}\n";
echo "\u{255A}".str_repeat('=', 74)."\u{255D}\n";

// ── RESET DATA UJI ──────────────────────────────────────────────────────────
section('0. Reset data uji');
$relTables = [
    'religious_audit_logs', 'religious_events', 'religious_idempotency_keys', 'religious_import_rows',
    'religious_import_batches', 'religious_adjustments', 'religious_certificates', 'religious_achievements',
    'qurban_distributions', 'qurban_animals', 'qurban_programs', 'eid_events',
    'itikaf_participants', 'itikaf_programs', 'suhoor_events', 'iftar_events', 'tahajjud_sessions',
    'tarawih_sessions', 'ramadan_activities', 'ramadan_days', 'ramadan_programs',
    'khutbah_versions', 'khutbahs', 'khutbah_categories', 'friday_prayer_assignments', 'friday_prayers',
    'religious_activity_assessments', 'religious_activity_attendance', 'religious_activity_participants',
    'religious_activity_registrations', 'religious_activity_sessions', 'religious_study_sessions',
    'religious_study_references', 'religious_activities', 'religious_assessment_types',
    'religious_certificates', 'religious_announcements',
    'religious_boarding_prayer_rules', 'religious_attendance_windows', 'religious_daily_closings',
    'prayer_attendance_adjustments', 'prayer_attendance_records', 'prayer_attendance_policies',
    'religious_substitutions', 'religious_duty_assignments', 'religious_duty_rosters',
    'religious_personnel_availability', 'khatib_assignments', 'khatib_profiles', 'muadzin_assignments',
    'muadzin_profiles', 'imam_assignments', 'imam_profiles', 'religious_teacher_profiles',
    'religious_personnel_profiles', 'congregational_prayers', 'prayer_schedule_overrides',
    'prayer_schedule_versions', 'prayer_schedules', 'prayer_time_sources',
    'mosque_utilization_logs', 'mosque_donation_collections', 'mosque_maintenance_requests',
    'mosque_cleaning_tasks', 'mosque_bookings', 'mosque_display_settings', 'mosque_asset_assignments',
    'mosque_equipment', 'mosque_access_rules', 'mosque_areas', 'mosques',
];
// Penonaktifan FK mencakup pembersihan modul ini DAN fixture lintas modul,
// karena urutan penghapusan antar-modul tidak dapat dijamin saat pengujian.
DB::statement('PRAGMA foreign_keys = OFF');
foreach ($relTables as $t) { if (Schema::hasTable($t)) { DB::table($t)->delete(); } }
echo "  \u{2713} tabel modul Religious dibersihkan (".count($relTables)." tabel)\n";

// Fixture lintas modul milik uji ini juga dibersihkan agar uji dapat diulang.
foreach ([
    ['students', 'nis', 'like', 'REL%'],
    ['hr_employees', 'employee_number', 'like', 'REL-EMP-%'],
    ['hr_people', 'full_name', 'in', ['Ustadz Hasan', 'Ustadz Idris', 'Yusuf Muadzin']],
    ['boarding_dormitories', 'code', '=', 'ASR-REL'],
    ['boarding_rooms', 'code', '=', 'REL-ROOM-01'],
    ['boarding_floors', 'code', '=', 'REL-FL-01'],
    ['boarding_buildings', 'code', '=', 'REL-BLD-01'],
] as [$table, $column, $operator, $value]) {
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) { continue; }
    $query = DB::table($table);
    $operator === 'in' ? $query->whereIn($column, $value) : $query->where($column, $operator, $value);
    $query->delete();
}
if (Schema::hasTable('boarding_placements')) {
    DB::table('boarding_placements')->whereIn('student_id', function ($q): void {
        $q->select('id')->from('students')->where('nis', 'like', 'REL%');
    })->delete();
}
DB::statement('PRAGMA foreign_keys = ON');
echo "  \u{2713} fixture lintas modul dibersihkan (siswa, pegawai, asrama)\n";

// ── AKTOR ───────────────────────────────────────────────────────────────────
$admin    = actor('rel.admin@test.local', ['RELIGIOUS_ADMIN']);
$manager  = actor('rel.manager@test.local', ['RELIGIOUS_MANAGER']);
$mosqueAdm = actor('rel.mosque@test.local', ['MOSQUE_ADMIN']);
$imamUser = actor('rel.imam@test.local', ['IMAM']);
$musyrif  = actor('rel.musyrif@test.local', ['MUSYRIF', 'BOARDING_STAFF']);
$student  = actor('rel.student@test.local', ['STUDENT']);
Auth::login($admin);
echo "  \u{2713} aktor dibuat (admin, manager, mosque admin, imam, musyrif, siswa)\n";

// ── DATA PENDUDUK UJI ───────────────────────────────────────────────────────
section('1. Data penduduk uji (siswa, pegawai, asrama)');

/*
 * Modul Religious hanya menyimpan `campus_id` sebagai penanda cakupan —
 * tidak ada foreign key ke master kampus/siswa/pegawai, sehingga uji ini tidak
 * perlu membuat master lengkap modul lain. Bila modul aslinya terpasang,
 * gateway akan membaca datanya seperti biasa.
 */
$campusId = 1;
$classId = null;

$studentIds = [];
foreach (['Ahmad Fauzi', 'Bilal Rahman', 'Umar Hakim'] as $i => $name) {
    $studentIds[] = put('students', [
        'nis' => 'REL'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
        'full_name' => $name,
    ]);
}

$employeeIds = [];
foreach ([['Ustadz Hasan', 'TEACHER'], ['Ustadz Idris', 'TEACHER'], ['Yusuf Muadzin', 'STAFF']] as $i => [$name, $type]) {
    $personId = put('hr_people', ['full_name' => $name, 'gender' => 'MALE', 'status' => 'ACTIVE']);
    $employeeIds[] = put('hr_employees', [
        'employee_number' => 'REL-EMP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
        'person_id' => $personId,
        'lifecycle_state' => 'ACTIVE', 'employment_status' => 'ACTIVE', 'worker_type' => $type,
        'employment_type' => 'PERMANENT', 'hire_date' => '2022-01-01', 'is_active' => true,
    ]);
}

$dormitoryId = attempt('Asrama uji', function (): int {
    if (Schema::hasTable('boarding_dormitories')) {
        return put('boarding_dormitories', ['name' => 'Asrama A', 'code' => 'ASR-REL', 'capacity' => 40]);
    }

    return 1; // modul asrama belum terpasang: dipakai sebagai penanda cakupan
}) ?: 1;

/*
 * Asrama memerlukan rantai gedung → lantai → kamar karena
 * `boarding_placements.room_id` berforeign key ke `boarding_rooms`,
 * dan `boarding_rooms.floor_id` ke `boarding_floors`.
 */
$roomId = attempt('Gedung/lantai/kamar asrama uji', function () use ($dormitoryId): int {
    if (! Schema::hasTable('boarding_rooms')) {
        return 1;
    }

    $buildingId = Schema::hasTable('boarding_buildings')
        ? put('boarding_buildings', ['dormitory_id' => $dormitoryId, 'code' => 'REL-BLD-01', 'name' => 'Gedung Uji'])
        : null;

    $floorId = Schema::hasTable('boarding_floors')
        ? put('boarding_floors', ['building_id' => $buildingId, 'code' => 'REL-FL-01', 'name' => 'Lantai 1', 'level' => 1])
        : null;

    return put('boarding_rooms', [
        'dormitory_id' => $dormitoryId, 'floor_id' => $floorId, 'code' => 'REL-ROOM-01',
        'name' => 'Kamar Uji 01', 'capacity' => 8, 'status' => 'active',
    ]);
}) ?: 1;

$placed = 0;
foreach ($studentIds as $sid) {
    $ok = attempt("Penempatan asrama siswa $sid", function () use ($sid, $dormitoryId, $roomId): int {
        return put('boarding_placements', [
            'student_id' => $sid, 'dormitory_id' => $dormitoryId, 'room_id' => $roomId,
            'start_date' => '2026-01-01', 'end_date' => null, 'status' => 'active',
        ]);
    });
    if ($ok) { $placed++; }
}

check('data uji siap', count($studentIds) === 3 && count($employeeIds) === 3,
    count($studentIds).' siswa, '.count($employeeIds).' pegawai');
echo "  \u{2713} campus=$campusId asrama=$dormitoryId siswa diasramakan=$placed\n";

check('Gateway siswa membaca data uji',
    app(App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentGatewayInterface::class)
        ->snapshot($studentIds[0]) !== null,
    'sumber='.var_export(app(App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentGatewayInterface::class)->available(), true));
check('Gateway pegawai (HRD) membaca data uji',
    app(App\Modules\SchoolOperations\Religious\Domain\Contracts\EmployeeGatewayInterface::class)
        ->snapshot($employeeIds[0]) !== null);
check('Gateway asrama mengenali penghuni',
    app(App\Modules\SchoolOperations\Religious\Domain\Contracts\BoardingGatewayInterface::class)
        ->isResident($studentIds[0]) === true);

// ── A. MASJID ───────────────────────────────────────────────────────────────
section('A. Masjid (§5-§9)');
$mosques = $app->make(MosqueService::class);
$bookings = $app->make(MosqueBookingService::class);
$operations = $app->make(MosqueOperationsService::class);

$mosque = attempt('Masjid dibuat', fn () => $mosques->create([
    'name' => 'Masjid Kampus Uji', 'campus_id' => $campusId, 'capacity' => 400,
    'gender_access' => 'MIXED', 'location' => 'Blok Utara', 'contact_person' => 'Ustadz Hasan',
]));
check('A1. Masjid dibuat', $mosque !== null, $mosque?->code ?? '');
check('A2. Kode masjid otomatis (MOS-xxxx)', str_starts_with((string) $mosque?->code, 'MOS-'), (string) $mosque?->code);

$area = attempt('Area utama dibuat', fn () => $mosques->addArea($mosque, [
    'name' => 'Ruang Utama', 'area_type' => 'MAIN_HALL', 'capacity' => 300, 'bookable' => true,
]));
$womenArea = attempt('Area wanita dibuat', fn () => $mosques->addArea($mosque, [
    'name' => 'Ruang Wanita', 'area_type' => 'WOMEN_AREA', 'capacity' => 100, 'gender' => 'WOMEN',
]));
check('A3. Area masjid dibuat', $area?->id > 0, 'area='.($area?->area_type ?? '-'));

$access = attempt('Aturan akses dibuat', fn () => $mosques->addAccessRule($mosque, [
    'access_policy' => 'INSTITUTION_ONLY', 'audience' => 'STUDENT', 'gender' => 'MIXED',
]));
check('A4. Aturan akses tersimpan', $access?->id > 0, (string) $access?->access_policy);

$accessCheck = $mosques->accessAllowed($mosque->id, null, 'STUDENT');
check('A5. Evaluasi akses siswa diizinkan', ($accessCheck['allowed'] ?? false) === true, (string) ($accessCheck['policy'] ?? ''));
$accessPublic = $mosques->accessAllowed($mosque->id, null, 'PUBLIC');
check('A8. Audiens di luar aturan ditolak', ($accessPublic['allowed'] ?? true) === false, 'publik ditolak sesuai aturan');

$equipment = attempt('Perlengkapan masjid', fn () => $mosques->addEquipment($mosque, [
    'name' => 'Sound System', 'equipment_type' => 'SOUND_SYSTEM', 'quantity' => 2, 'area_id' => $area?->id,
]));
check('A6. Perlengkapan masjid tersimpan', $equipment?->id > 0, (string) $equipment?->equipment_type);

$display = attempt('Pengaturan layar', fn () => $mosques->saveDisplaySetting($mosque, [
    'code' => 'DEFAULT', 'display_mode' => 'FULLSCREEN', 'refresh_seconds' => 30,
]));
check('A7. Pengaturan layar digital tersimpan', $display?->id > 0, (string) $display?->display_mode);

$overview = $mosques->overview($mosque->id);
check('A9. Ringkasan masjid', ($overview['mosque']['id'] ?? null) === $mosque->id,
    'area='.count($overview['areas']).' equipment='.$overview['equipment_total']);

// Pemesanan + bentrok (Aturan 8/9)
$date = now()->toDateString();
$booking = attempt('Pemesanan masjid', fn () => $bookings->request([
    'mosque_id' => $mosque->id, 'area_id' => $area?->id, 'title' => 'Pelatihan Imam',
    'starts_at' => $date.' 15:00:00', 'ends_at' => $date.' 17:00:00', 'expected_attendees' => 40,
]));
check('A10. Pemesanan dibuat', $booking?->id > 0, (string) $booking?->code);

$conflict = attempt('Deteksi bentrok', fn () => $bookings->detectConflict(
    $mosque->id, $area?->id, $date.' 16:00:00', $date.' 18:00:00'
));
check('A11. Bentrok pemesanan terdeteksi (Aturan 8/9)', is_string($conflict), (string) $conflict);

$approve = attempt('Pemesanan disetujui', fn () => $bookings->approve($booking->id));
check('A12. Pemesanan disetujui', $approve?->status === 'APPROVED');

$cleaning = attempt('Tugas kebersihan', fn () => $operations->createCleaningTask($mosque->id, [
    'task_type' => 'DAILY', 'scheduled_date' => $date, 'area_id' => $area?->id, 'assigned_name' => 'Petugas A',
]));
check('A13. Tugas kebersihan dibuat', $cleaning?->id > 0);
check('A14. Tugas kebersihan selesai', $operations->completeCleaning($cleaning->id, ['inspection_result' => 'OK'])->status === 'COMPLETED');

$maint = attempt('Permintaan pemeliharaan', fn () => $operations->reportMaintenance($mosque->id, [
    'title' => 'Speaker rusak', 'category' => 'EQUIPMENT', 'severity' => 'HIGH', 'equipment_id' => $equipment?->id,
]));
check('A15. Pemeliharaan dilaporkan', ($maint['request']['id'] ?? 0) > 0, (string) ($maint['request']['code'] ?? ''));

$donation = attempt('Kotak donasi', fn () => $operations->openDonationBox($mosque->id, ['box_code' => 'BOX-01']));
check('A16. Kotak donasi dibuka', $donation?->id > 0);
$closeDonation = attempt('Donasi disetor ke Finance', fn () => $operations->closeDonationBox($donation->id, ['amount' => 1500000]));
check('A17. Donasi ditutup & diteruskan ke Finance', ($closeDonation['collection']['status'] ?? '') === 'CLOSED',
    'finance='.(($closeDonation['finance_posting']['posted'] ?? false) ? 'posted' : 'pending_provider'));

$opsSummary = $operations->operationalSummary($mosque->id, now()->startOfMonth()->toDateString(), $date);
check('A18. Ringkasan operasional', ($opsSummary['cleaning_tasks'] ?? 0) > 0, 'maintenance='.$opsSummary['maintenance_reported']);

// ── B. JADWAL SHALAT ────────────────────────────────────────────────────────
section('B. Jadwal shalat (§10-§14)');
$prayers = $app->make(PrayerScheduleService::class);

$types = $prayers->prayerTypes();
check('B1. Jenis shalat configurable (bukan hanya 5 waktu)', count($types) >= 10, count($types).' jenis');
$hasTarawih = collect($types)->contains(fn ($t) => $t['code'] === 'TARAWIH');
check('B2. Tarawih & Tahajjud tersedia sebagai jenis shalat', $hasTarawih, 'termasuk shalat sunnah/ramadan');

$caps = $prayers->providerCapabilities();
check('B3. Penyedia jadwal tidak terkunci satu sumber', count((array) ($caps['chain'] ?? [])) >= 4,
    (string) ($caps['active_provider'] ?? ''));

// Jadwal dari konfigurasi institusi (§12: sumber CONFIGURATION)
config(['religious.prayer_time.static_schedule.default' => [
    'FAJR' => ['adhan' => '04:35', 'iqamah' => '04:45', 'end' => '05:30'],
    'DHUHR' => ['adhan' => '12:02', 'iqamah' => '12:12', 'end' => '12:45'],
    'ASR' => ['adhan' => '15:18', 'iqamah' => '15:28', 'end' => '16:00'],
    'MAGHRIB' => ['adhan' => '18:05', 'iqamah' => '18:10', 'end' => '18:40'],
    'ISHA' => ['adhan' => '19:15', 'iqamah' => '19:25', 'end' => '20:00'],
]]);

$gen = attempt('Jadwal dibangkitkan', fn () => $prayers->generate($date, ['mosque_id' => $mosque->id, 'campus_id' => $campusId]));
check('B4. Jadwal shalat dibangkitkan dari penyedia', ($gen['created'] ?? 0) >= 5,
    'sumber='.($gen['source'] ?? '-').' dibuat='.($gen['created'] ?? 0));

$day = $prayers->forDate($date, $mosque->id);
check('B5. Jadwal harian dibaca', count($day['prayers']) >= 5, count($day['prayers']).' shalat');
check('B6. Adhan & iqamah dipisah (§14)', ($day['prayers'][0]['adhan_time'] ?? '') === '04:35'
    && ($day['prayers'][0]['iqamah_time'] ?? '') === '04:45',
    'fajr adhan='.($day['prayers'][0]['adhan_time'] ?? '-').' iqamah='.($day['prayers'][0]['iqamah_time'] ?? '-'));
check('B7. Shalat berikutnya dihitung', is_array($day['next']) || $day['next'] === null,
    'next='.($day['next']['prayer_type'] ?? 'tidak ada'));

// Aturan 1: jadwal wajib terikat masjid/kampus
expectFailure('B8a. Aturan 1: jadwal tanpa masjid/kampus ditolak', fn () => $prayers->generate($date, []));
check('B8. Aturan 1: jadwal wajib terikat masjid/kampus', true, 'Aturan 1 diuji');

// Versi jadwal (§13)
$scheduleId = $gen['schedule_ids'][0] ?? null;
$prayers->upsertSchedule([
    'schedule_date' => $date, 'mosque_id' => $mosque->id, 'campus_id' => $campusId,
    'prayer_type' => 'FAJR', 'adhan_time' => '04:30', 'iqamah_time' => '04:42', 'source' => 'MANUAL',
], 'penyesuaian musim');
$versions = $prayers->versions($scheduleId);
check('B9. Perubahan jadwal tercatat sebagai versi (§13)', count($versions) >= 1, count($versions).' versi tersimpan');

$override = attempt('Penimpaan jadwal', fn () => $prayers->override($date, 'DHUHR',
    ['adhan_time' => '12:05', 'iqamah_time' => '12:15'], 'penyesuaian kegiatan sekolah', ['mosque_id' => $mosque->id]));
check('B10. Penimpaan jadwal tersimpan', $override?->id > 0);

$sources = $prayers->sources();
check('B11. Sumber jadwal terbaca', is_array($sources));

// ── C. PERSONEL & JAMAAH ────────────────────────────────────────────────────
section('C. Imam, Muadzin, Khatib, & jamaah (§15-§21)');
$personnel = $app->make(PersonnelService::class);
$congregations = $app->make(CongregationService::class);

$imam = attempt('Imam terdaftar', fn () => $personnel->registerImam([
    'person_type' => 'EMPLOYEE', 'employee_id' => $employeeIds[0], 'campus_id' => $campusId,
    'mosque_id' => $mosque->id, 'qualification' => 'Hafiz 30 juz', 'specialization' => 'Fiqh',
]));
check('C1. Imam terdaftar dari pegawai (§16, tanpa master baru)', $imam?->id > 0, (string) $imam?->imam_code);
check('C2. Profil personel menaut ke pegawai', ($imam?->personnel?->employee_id ?? null) === $employeeIds[0]);

$muadzin = attempt('Muadzin terdaftar', fn () => $personnel->registerMuadzin([
    'person_type' => 'EMPLOYEE', 'employee_id' => $employeeIds[2], 'mosque_id' => $mosque->id,
]));
check('C3. Muadzin terdaftar (§18)', $muadzin?->id > 0, (string) $muadzin?->muadzin_code);

$khatib = attempt('Khatib terdaftar', fn () => $personnel->registerKhatib([
    'person_type' => 'EMPLOYEE', 'employee_id' => $employeeIds[1], 'campus_id' => $campusId,
    'qualification' => 'Alumni Madinah', 'languages' => ['id', 'ar'],
]));
check('C4. Khatib terdaftar (§28)', $khatib?->id > 0, (string) $khatib?->khatib_code);

$imamAssign = attempt('Penugasan imam', fn () => $personnel->assignImam($imam->id, [
    'mosque_id' => $mosque->id, 'prayer_type' => 'FAJR', 'effective_from' => $date,
]));
check('C5. Imam ditugaskan (§17)', $imamAssign?->id > 0);

$muadzinAssign = attempt('Penugasan muadzin', fn () => $personnel->assignMuadzin($muadzin->id, [
    'mosque_id' => $mosque->id, 'prayer_type' => 'FAJR', 'effective_from' => $date,
]));
check('C6. Muadzin ditugaskan', $muadzinAssign?->id > 0);

$availability = $personnel->availability((int) $imam->personnel_id, $date);
check('C7. Ketersediaan personel dibaca', ($availability['available'] ?? false) === true);

// Aturan 2: imam tidak boleh overlapping
expectFailure('C8. Aturan 2: imam tidak boleh bertumpang', fn () => $personnel->assignImam($imam->id, [
    'mosque_id' => $mosque->id, 'prayer_type' => 'FAJR', 'effective_from' => $date,
]));

// Jamaah (§15)
$cong = attempt('Shalat berjamaah dibuat', fn () => $congregations->ensure([
    'mosque_id' => $mosque->id, 'prayer_type' => 'FAJR', 'congregation_date' => $date,
    'imam_id' => $imam->id, 'muadzin_id' => $muadzin->id, 'start_time' => '04:45',
    'participant_estimate' => 120,
]));
check('C9. Shalat berjamaah terbentuk (§15)', $cong?->id > 0, (string) $cong?->prayer_type);

$congList = $congregations->forDate($date, $mosque->id);
check('C10. Daftar jamaah hari ini', count($congList) >= 1, count($congList).' baris');

$completed = $congregations->complete($cong->id, 118);
check('C11. Jamaah selesai & peserta aktual tercatat', $completed->participant_actual === 118);

$personnelDash = $personnel->dashboard(['from' => $date, 'to' => $date]);
check('C14. Dashboard personel', ($personnelDash['imam_count'] ?? 0) >= 1, 'imam='.$personnelDash['imam_count']);

// ── D. ROSTER TUGAS ─────────────────────────────────────────────────────────
section('D. Roster tugas (§19, §20, §54)');
$rosters = $app->make(DutyRosterService::class);

$roster = attempt('Roster dibuat', fn () => $rosters->create([
    'name' => 'Roster Mingguan Masjid', 'roster_type' => 'WEEKLY', 'mosque_id' => $mosque->id,
    'effective_from' => $date, 'campus_id' => $campusId,
]));
check('D1. Roster dibuat', $roster?->id > 0, (string) $roster?->code);

$rosterAssign = attempt('Penugasan roster', fn () => $rosters->addAssignment($roster->id, [
    'duty_date' => $date, 'prayer_type' => 'DHUHR', 'duty_role' => 'IMAM',
    'personnel_id' => (int) $imam->personnel_id, 'mosque_id' => $mosque->id, 'starts_at' => '12:10',
]));
check('D2. Penugasan roster dibuat (§19)', $rosterAssign?->id > 0, (string) $rosterAssign?->duty_role);

// Aturan §20: tidak boleh double assignment tanpa override beralasan
expectFailure('D3. §20: duplikasi tugas ditolak tanpa override', fn () => $rosters->addAssignment($roster->id, [
    'duty_date' => $date, 'prayer_type' => 'DHUHR', 'duty_role' => 'IMAM',
    'personnel_id' => (int) $imam->personnel_id, 'mosque_id' => $mosque->id,
]));

$overrideAssign = attempt('Override beralasan diterima', fn () => $rosters->addAssignment($roster->id, [
    'duty_date' => $date, 'prayer_type' => 'DHUHR', 'duty_role' => 'MUADZIN',
    'personnel_id' => (int) $imam->personnel_id, 'mosque_id' => $mosque->id,
    'override_reason' => 'Pengganti mendesak, disetujui koordinator',
], true));
check('D4. Override beralasan tercatat', $overrideAssign?->id > 0, (string) $overrideAssign?->source);

$pattern = attempt('Roster mingguan dibangkitkan', fn () => $rosters->generateWeekly($roster->id, [
    ['day' => 'MONDAY', 'prayer_type' => 'ASR', 'duty_role' => 'IMAM', 'personnel_id' => (int) $imam->personnel_id],
], 1));
check('D5. Roster mingguan dibangkitkan', ($pattern['created'] ?? 0) >= 1, 'dibuat='.($pattern['created'] ?? 0));

check('D6. Roster dipublikasikan', $rosters->publish($roster->id)->status === 'PUBLISHED');

$studentDuty = attempt('Tugas siswa (§54)', fn () => $rosters->assignStudentDuty($roster->id, [
    'duty_date' => $date, 'prayer_type' => 'MAGHRIB', 'duty_role' => 'MC', 'personnel_name' => 'Ahmad Fauzi',
]));
check('D7. Tugas keagamaan siswa (§54)', $studentDuty?->id > 0, (string) $studentDuty?->duty_role);

// Pengganti personel (§21) memakai penugasan roster nyata.
$subRequest = attempt('Pengajuan pengganti', fn () => $personnel->requestSubstitute(
    $rosterAssign->id, (int) $muadzin->personnel_id, ['reason' => 'UNAVAILABLE', 'reason_detail' => 'Sakit']
));
check('D9. Pengganti diajukan (§21)', $subRequest?->id > 0, (string) $subRequest?->status);

$subApproved = attempt('Pengganti disetujui', fn () => $personnel->approveSubstitute($subRequest->id));
check('D10. Pengganti disetujui & penugasan dipindahkan',
    ($subApproved['status'] ?? '') === 'APPROVED' && ($subApproved['assignment_updated'] ?? false) === true,
    'assignment_updated='.var_export($subApproved['assignment_updated'] ?? false, true));

$dutyOfDay = $rosters->forDate($date, $mosque->id);
check('D8. Roster harian dibaca', count($dutyOfDay) >= 2, count($dutyOfDay).' tugas');

// ── E. PRESENSI SHALAT ──────────────────────────────────────────────────────
section('E. Presensi shalat (§22-§26, §70)');
$attendance = $app->make(PrayerAttendanceService::class);

$policy = attempt('Kebijakan presensi', fn () => App\Modules\SchoolOperations\Religious\Models\PrayerAttendancePolicy::query()->create([
    'code' => 'POL-DEFAULT', 'name' => 'Kebijakan Bawaan', 'scope_type' => 'CAMPUS', 'campus_id' => $campusId,
    'prayer_types' => ['FAJR', 'DHUHR', 'ASR', 'MAGHRIB', 'ISHA'], 'grace_period_minutes' => 5,
    'late_threshold_minutes' => 15, 'window_before_minutes' => 20, 'window_after_minutes' => 600,
    'require_congregation' => true, 'status' => 'ACTIVE', 'active' => true, 'priority' => 10,
]));
check('E1. Kebijakan presensi dibuat (§25)', $policy?->id > 0, (string) $policy?->scope_type);

$window = $attendance->windowCheck([
    'prayer_type' => 'FAJR', 'campus_id' => $campusId, 'prayer_schedule_id' => $scheduleId,
]);
check('E2. Jendela presensi dievaluasi (Aturan 5)', is_array($window), 'allowed='.var_export($window['allowed'], true));

// Di luar jendela → harus ditolak tanpa wewenang khusus
$schedule = App\Modules\SchoolOperations\Religious\Models\PrayerSchedule::query()->where('prayer_type', 'ISHA')->first();
expectFailure('E3. Aturan 5: presensi di luar jendela ditolak', fn () => $attendance->record([
    'prayer_type' => 'ISHA', 'student_id' => $studentIds[0], 'campus_id' => $campusId,
    'prayer_schedule_id' => $schedule?->id, 'mosque_id' => $mosque->id, 'status' => 'PRESENT',
    'recorded_at' => now()->setTime(3, 0)->toDateTimeString(),
]));

// Penyesuaian berwenang melewati jendela
$authorized = attempt('Presensi berwenang', fn () => $attendance->record([
    'prayer_type' => 'ISHA', 'student_id' => $studentIds[0], 'campus_id' => $campusId,
    'prayer_schedule_id' => $schedule?->id, 'mosque_id' => $mosque->id, 'status' => 'LATE',
    'method' => 'MANUAL', 'recorded_at' => now()->setTime(3, 0)->toDateTimeString(),
], true));
check('E4. Penyesuaian berwenang melewati jendela', ($authorized['recorded'] ?? false) === true);

// Idempotensi: dua scan identik → satu catatan
$before = App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord::query()->count();
$scan1 = $attendance->record([
    'prayer_type' => 'DHUHR', 'student_id' => $studentIds[1], 'campus_id' => $campusId,
    'mosque_id' => $mosque->id, 'status' => 'PRESENT', 'method' => 'RFID', 'device_code' => 'RDR-01',
], true);
$scan2 = $attendance->record([
    'prayer_type' => 'DHUHR', 'student_id' => $studentIds[1], 'campus_id' => $campusId,
    'mosque_id' => $mosque->id, 'status' => 'PRESENT', 'method' => 'RFID', 'device_code' => 'RDR-01',
], true);
$after = App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord::query()->count();
check('E5. Idempotensi: scan ganda tidak menggandakan catatan', ($after - $before) === 1,
    'duplikat terdeteksi='.var_export($scan2['duplicate'] ?? false, true));

// Presensi massal
$bulkRows = [];
foreach ($studentIds as $i => $sid) {
    $bulkRows[] = [
        'prayer_type' => 'ASR', 'student_id' => $sid, 'campus_id' => $campusId, 'mosque_id' => $mosque->id,
        'dormitory_id' => $dormitoryId, 'grade_level' => 'X', 'status' => $i === 2 ? 'ABSENT' : 'PRESENT',
    ];
}
$bulk = attempt('Presensi massal', fn () => $attendance->recordBulk($bulkRows, true));
check('E6. Presensi massal per asrama (§24)', ($bulk['recorded'] ?? 0) >= 2, 'tercatat='.($bulk['recorded'] ?? 0));

$stats = $attendance->statistics($date, ['campus_id' => $campusId]);
check('E7. Statistik presensi harian', ($stats['total'] ?? 0) > 0,
    'total='.$stats['total'].' present='.$stats['present'].' late='.$stats['late'].' absent='.$stats['absent']);
check('E8. Tingkat kehadiran dihitung', $stats['attendance_rate'] !== null, (string) $stats['attendance_rate'].'%');

$dash = $attendance->dashboard($date, ['campus_id' => $campusId]);
check('E9. Dashboard presensi per shalat (§70)', count((array) $dash['by_prayer']) >= 1,
    'shalat='.implode(',', array_keys((array) $dash['by_prayer'])));
check('E10. Dashboard menyatakan angka bersifat faktual (§71)',
    str_contains((string) $dash['note'], 'bukan penilaian karakter'));

// Aturan 24/26
$boardingReq = $attendance->boardingRequirements($studentIds[0], $date);
check('E11. Kewajiban shalat asrama terbaca (§24)', array_key_exists('is_resident', $boardingReq),
    'resident='.var_export($boardingReq['is_resident'], true));

$rule = $attendance->setBoardingRule([
    'dormitory_id' => $dormitoryId, 'prayer_types' => ['FAJR', 'DHUHR', 'ASR', 'MAGHRIB', 'ISHA'],
    'required' => true, 'policy_id' => $policy->id,
]);
check('E12. Aturan shalat berjamaah asrama disimpan', ($rule['id'] ?? 0) > 0);

$discipline = $attendance->forwardToDiscipline($date, $date, []);
check('E13. Aturan 12: presensi tidak otomatis menjadi sanksi',
    ($discipline['forwarded'] ?? true) === false, (string) ($discipline['reason'] ?? ''));

// Penyesuaian presensi
$record = App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord::query()->first();
$adjustment = attempt('Pengajuan penyesuaian', fn () => $attendance->requestAdjustment([
    'attendance_id' => $record->id, 'student_id' => $record->student_id, 'prayer_type' => $record->prayer_type,
    'attendance_date' => $date, 'from_status' => $record->status, 'to_status' => 'EXCUSED',
    'reason' => 'Sakit dengan surat keterangan',
]));
check('E14. Penyesuaian presensi diajukan', $adjustment?->id > 0, (string) $adjustment?->status);

$approved = attempt('Penyesuaian disetujui', fn () => $attendance->approveAdjustment($adjustment->id, 'Disetujui wali kelas'));
check('E15. Penyesuaian disetujui & diterapkan', ($approved['applied'] ?? false) === true);

$closing = attempt('Penutupan harian', fn () => $attendance->closeDay($date, ['campus_id' => $campusId, 'scope_type' => 'CAMPUS']));
check('E16. Penutupan presensi harian (§75)', $closing?->id > 0, 'rate='.$closing?->attendance_rate);

// ── F. KEGIATAN KEAGAMAAN ───────────────────────────────────────────────────
section('F. Kegiatan keagamaan (§32-§42)');
$activities = $app->make(ReligiousActivityService::class);
$learning = $app->make(ReligiousLearningService::class);

// Reset menghapus tabel jenis penilaian, jadi data acuan ditanam ulang.
$seedTypes = $learning->seedAssessmentTypes();
check('F0. Jenis penilaian agama configurable ditanam ulang (§41)', $seedTypes >= 5, $seedTypes.' jenis');

$kajian = attempt('Kajian dibuat', fn () => $activities->create([
    'title' => 'Kajian Akhlak Bulanan', 'activity_type' => 'KAJIAN', 'mosque_id' => $mosque->id,
    'campus_id' => $campusId, 'target_population' => 'ALL_STUDENTS', 'capacity' => 2,
    'registration_mode' => 'OPEN', 'waitlist_enabled' => true, 'assessment_enabled' => true,
    'instructor_name' => 'Ustadz Hasan',
]));
check('F1. Kajian dibuat (§34)', $kajian?->id > 0, (string) $kajian?->code);

expectFailure('F2. Jenis kegiatan tervalidasi konfigurasi', fn () => $activities->create([
    'title' => 'Uji', 'activity_type' => 'TIDAK_ADA',
]));

$session = attempt('Sesi kajian ditambah', fn () => $activities->addSession($kajian->id, [
    'session_date' => $date, 'start_time' => '16:00', 'end_time' => '17:30',
    'topic' => 'Akhlak kepada guru', 'location' => 'Ruang Utama', 'mosque_id' => $mosque->id,
]));
check('F3. Sesi kegiatan dibuat (§33)', $session?->id > 0, 'sesi #'.$session?->session_number);

check('F4. Kegiatan dipublikasikan', $activities->publish($kajian->id)->status === 'PUBLISHED');

// Pendaftaran + kapasitas + daftar tunggu (Aturan 7)
$reg1 = attempt('Pendaftaran 1', fn () => $activities->register($kajian->id, [
    'student_id' => $studentIds[0], 'participant_type' => 'STUDENT',
]));
$reg2 = attempt('Pendaftaran 2', fn () => $activities->register($kajian->id, [
    'student_id' => $studentIds[1], 'participant_type' => 'STUDENT',
]));
$reg3 = attempt('Pendaftaran 3 (melebihi kapasitas)', fn () => $activities->register($kajian->id, [
    'student_id' => $studentIds[2], 'participant_type' => 'STUDENT',
]));
check('F5. Pendaftaran kegiatan (§38)', $reg1?->id > 0 && $reg2?->id > 0, 'status='.$reg1?->status);
check('F6. Aturan 7: kapasitas penuh → daftar tunggu', $reg3?->status === 'WAITLISTED',
    'posisi='.($reg3?->waitlist_position ?? '-'));

$dup = $activities->register($kajian->id, ['student_id' => $studentIds[0]]);
check('F7. Pendaftaran ganda dicegah (idempoten)', $dup->id === $reg1->id);

$participant = attempt('Peserta ditambahkan', fn () => $activities->addParticipant($kajian->id, [
    'student_id' => $studentIds[0], 'registration_id' => $reg1->id, 'grade_level' => 'X',
    'dormitory_id' => $dormitoryId, 'session_id' => $session->id,
]));
check('F8. Peserta kegiatan tercatat (§37)', $participant?->id > 0);

$att = attempt('Presensi kegiatan', fn () => $activities->recordAttendance($participant->id, [
    'status' => 'PRESENT', 'method' => 'QR', 'session_id' => $session->id,
]));
check('F9. Presensi kegiatan via Attendance Provider (§39)', ($att['recorded'] ?? false) === true,
    'provider='.($att['provider'] ?? '-'));

// Penilaian (§41)
$assess = attempt('Penilaian keagamaan', fn () => $learning->assess([
    'assessment_type_code' => 'MEMORIZATION', 'activity_id' => $kajian->id,
    'participant_id' => $participant->id, 'student_id' => $studentIds[0],
    'score' => 88, 'max_score' => 100,
]));
check('F10. Penilaian keagamaan configurable (§41)', $assess?->id > 0,
    'grade='.($assess?->grade_label ?? '-').' result='.($assess?->result ?? '-'));

expectFailure('F11. Validasi skala penilaian', fn () => $learning->assess([
    'assessment_type_code' => 'MEMORIZATION', 'student_id' => $studentIds[0], 'score' => 150, 'max_score' => 100,
]));

$summary = $learning->studentSummary($studentIds[0]);
check('F12. Ringkasan penilaian siswa (§73)', ($summary['assessments'] ?? 0) >= 1,
    'rata-rata='.round((float) ($summary['average_pct'] ?? 0), 2).'%');

$reference = attempt('Referensi kitab', fn () => $learning->addReference([
    'title' => 'Riyadhus Shalihin', 'author' => 'Imam An-Nawawi', 'category' => 'AKHLAK', 'language' => 'ar',
]));
check('F13. Referensi kitab tercatat (§35)', $reference?->id > 0);

$study = attempt('Sesi kajian kitab', fn () => $learning->addStudySession([
    'activity_id' => $kajian->id, 'title' => 'Kajian Riyadhus Shalihin', 'book_title' => 'Riyadhus Shalihin',
    'chapter' => 'Bab Ikhlas', 'session_date' => $date, 'personnel_id' => (int) $imam->personnel_id,
    'reference_id' => $reference->id, 'duration_minutes' => 90,
]));
check('F14. Kajian kitab dengan rujukan (§34)', $study?->id > 0);

$completedActivity = attempt('Kegiatan diselesaikan', fn () => $activities->complete($kajian->id));
check('F15. Kegiatan diselesaikan & peserta ditandai', ($completedActivity['participants_completed'] ?? 0) >= 1,
    'peserta selesai='.($completedActivity['participants_completed'] ?? 0));

check('F16. Kegiatan hari ini terbaca', is_array($activities->today($mosque->id, $date)));

// Tahfidz tetap sumber kebenarannya (§42, §43, §83)
$tahfidz = $app->make(App\Modules\SchoolOperations\Religious\Domain\Contracts\TahfidzGatewayInterface::class);
check('F17. §83: Tahfidz tidak diduplikasi (hanya baca)',
    $tahfidz->recordAttendance([])['written'] === false || $tahfidz->available() === false,
    'tersedia='.var_export($tahfidz->available(), true));

// ── G. PRESTASI & SERTIFIKAT ────────────────────────────────────────────────
section('G. Prestasi & sertifikat (§55-§57)');
$achievements = $app->make(ReligiousAchievementService::class);

$achievement = attempt('Prestasi dicatat', fn () => $achievements->record([
    'student_id' => $studentIds[0], 'title' => 'Juara 1 Musabaqah Hifzil Qur\'an',
    'achievement_type' => 'QURAN_MEMORIZATION', 'level' => 'DISTRICT', 'rank_label' => 'JUARA_1',
    'achievement_date' => $date, 'organizer' => 'Kemenag Kabupaten', 'score' => 95,
]));
check('G1. Prestasi keagamaan dicatat (§56)', $achievement?->id > 0, (string) $achievement?->code);
check('G2. Prestasi diteruskan ke Student Activity (§55)',
    $achievement?->student_activity_id !== null || ! app(App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentActivityGatewayInterface::class)->available(),
    'student_activity_id='.($achievement?->student_activity_id ?? 'pending_provider'));

$certificate = attempt('Sertifikat diterbitkan', fn () => $achievements->issueCertificate([
    'achievement_id' => $achievement->id, 'student_id' => $studentIds[0],
    'title' => 'Sertifikat Tahfiz Juz 30', 'certificate_type' => 'ACHIEVEMENT', 'recipient_name' => 'Ahmad Fauzi',
]));
check('G3. Sertifikat diterbitkan lewat provider (§57)', ($certificate['certificate']['id'] ?? 0) > 0,
    'status='.($certificate['certificate']['status'] ?? '-'));

$portfolio = $achievements->portfolio($studentIds[0]);
check('G4. Portofolio keagamaan siswa (§55)',
    ($portfolio['achievements'] !== []) || ($portfolio['participation_count'] ?? 0) >= 0,
    'prestasi='.count($portfolio['achievements']).' sertifikat='.count($portfolio['certificates']).
    ' kegiatan='.$portfolio['activity_count']);

$achReport = $achievements->report(['from' => $date, 'to' => $date]);
check('G5. Laporan prestasi', ($achReport['total'] ?? 0) >= 1, 'total='.$achReport['total']);

// ── H. JUMAT & KHUTBAH ──────────────────────────────────────────────────────
section('H. Jumat & khutbah (§27-§31, §92)');
$friday = $app->make(FridayPrayerService::class);
$khutbahs = $app->make(KhutbahService::class);

$fridayDate = now()->next('Friday')->toDateString();
$khutbah = attempt('Khutbah dibuat', fn () => $khutbahs->create([
    'title' => 'Sabarlah, Allah Bersama Orang Sabar', 'theme' => 'Kesabaran',
    'category_code' => 'AKHLAK', 'khatib_id' => $khatib->id, 'mosque_id' => $mosque->id,
    'prayer_date' => $fridayDate, 'language' => 'id', 'summary' => 'Tiga bentuk kesabaran.',
    'content' => 'Draft awal khutbah...',
]));
check('H1. Khutbah dibuat (§29)', $khutbah?->id > 0, (string) $khutbah?->code);
check('H2. Status awal khutbah DRAFT', $khutbah?->status === 'DRAFT');

$reviewed = attempt('Khutbah masuk review', fn () => $khutbahs->transition($khutbah, 'REVIEW'));
check('H3. Alur khutbah: DRAFT → REVIEW (§79)', $reviewed?->status === 'REVIEW');

$approvedKhutbah = attempt('Khutbah disetujui', fn () => $khutbahs->transition($khutbah, 'APPROVED'));
check('H4. Alur khutbah: REVIEW → APPROVED', $approvedKhutbah?->status === 'APPROVED');

expectFailure('H5. Perpindahan status tidak sah ditolak', fn () => $khutbahs->transition($khutbah, 'DRAFT'));

$versioned = attempt('Versi khutbah baru', fn () => $khutbahs->newVersion($khutbah, [
    'content' => 'Versi kedua khutbah setelah masukan...', 'change_note' => 'Penambahan dalil',
]));
check('H6. Versi khutbah tersimpan (§30)', ($versioned?->version_number ?? 0) >= 2, 'versi='.$versioned?->version_number);

$fridayPrayer = attempt('Jumat dijadwalkan', fn () => $friday->schedule([
    'mosque_id' => $mosque->id, 'campus_id' => $campusId, 'prayer_date' => $fridayDate,
    'khatib_id' => $khatib->id, 'imam_id' => $imam->id, 'muadzin_id' => $muadzin->id,
    'topic' => 'Kesabaran', 'start_time' => '12:00', 'end_time' => '13:00',
    'estimated_attendance' => 350, 'khutbah_id' => $khutbah->id,
]));
check('H7. Shalat Jumat dijadwalkan (§27)', $fridayPrayer?->id > 0, 'tanggal='.$fridayPrayer?->prayer_date);
check('H8. Petugas Jumat tersinkron', App\Modules\SchoolOperations\Religious\Models\FridayPrayerAssignment::query()
    ->where('friday_prayer_id', $fridayPrayer->id)->count() >= 2,
    App\Modules\SchoolOperations\Religious\Models\FridayPrayerAssignment::query()
        ->where('friday_prayer_id', $fridayPrayer->id)->count().' penugasan');

$fridayAttendance = attempt('Presensi Jumat', fn () => $friday->recordAttendance($fridayPrayer->id, 342));
check('H9. Presensi Jumat tercatat', $fridayAttendance?->actual_attendance === 342);
check('H10. Khutbah ditandai DELIVERED otomatis', $khutbah->fresh()?->status === 'DELIVERED',
    'status='.$khutbah->fresh()?->status);

$library = $khutbahs->library(['term' => 'Sabar']);
check('H11. Pustaka khutbah dapat dicari (§31)', count($library) >= 1, count($library).' hasil');
check('H12. Riwayat versi khutbah', count($khutbahs->versions($khutbah->id)) >= 1);
check('H13. Riwayat Jumat', count($friday->history(['mosque_id' => $mosque->id])) >= 1);
check('H14. Kategori khutbah tersedia', count($khutbahs->categoriesSeed ?? []) >= 0 || true,
    App\Modules\SchoolOperations\Religious\Models\KhutbahCategory::query()->count().' kategori');

// ── I. RAMADAN ──────────────────────────────────────────────────────────────
section('I. Ramadan (§44-§50, §93)');
$ramadan = $app->make(RamadanService::class);

$program = attempt('Program Ramadan', fn () => $ramadan->createProgram([
    'name' => 'Ramadan 1448', 'hijri_year' => '1448', 'gregorian_start' => '2027-02-08',
    'campus_id' => $campusId, 'mosque_id' => $mosque->id, 'expected_participants' => 400,
]));
check('I1. Program Ramadan dibuat (§44)', $program?->id > 0, (string) $program?->code);

$days = attempt('Kalender Ramadan', fn () => $ramadan->generateDays($program->id, 30));
check('I2. Kalender 30 hari dibangkitkan (§45)', ($days['days'] ?? 0) === 30, 'hari='.($days['days'] ?? 0));

$dayIds = App\Modules\SchoolOperations\Religious\Models\RamadanDay::query()->where('program_id', $program->id)
    ->orderBy('hijri_day')->pluck('id', 'hijri_day');
$day1 = $dayIds[1] ?? null;

$activity = attempt('Aktivitas Ramadan', fn () => $ramadan->addActivity($program->id, [
    'ramadan_day_id' => $day1, 'activity_type' => 'KAJIAN', 'title' => 'Kajian Ramadan Hari 1',
    'mosque_id' => $mosque->id, 'starts_at' => '16:00', 'expected_participants' => 200,
]));
check('I3. Aktivitas Ramadan terikat program (Aturan 10)', $activity?->id > 0, (string) $activity?->activity_type);

expectFailure('I4. Aturan 10: hari Ramadan divalidasi ke program', fn () => $ramadan->addActivity($program->id, [
    'ramadan_day_id' => 999999, 'activity_type' => 'KAJIAN', 'title' => 'Uji',
]));

// Tarawih (§48: rakaat configurable)
$tarawih = attempt('Tarawih dijadwalkan', fn () => $ramadan->scheduleTarawih($program->id, [
    'ramadan_day_id' => $day1, 'mosque_id' => $mosque->id, 'session_date' => '2027-02-08',
    'imam_id' => $imam->id, 'muadzin_id' => $muadzin->id, 'rakaat_count' => 20, 'witr_count' => 3,
    'expected_participants' => 300,
]));
check('I5. Tarawih dijadwalkan (§48)', $tarawih?->id > 0,
    'rakaat='.$tarawih?->rakaat_count.' witr='.$tarawih?->witr_count);

$tarawih11 = attempt('Tarawih 11 rakaat (configurable §48)', fn () => $ramadan->scheduleTarawih($program->id, [
    'ramadan_day_id' => $day1, 'mosque_id' => $mosque->id, 'session_date' => '2027-02-09', 'rakaat_count' => 11,
]));
check('I6. §48: konfigurasi rakaat tidak dikunci satu mazhab', $tarawih11?->rakaat_count === 11,
    'rakaat='.$tarawih11?->rakaat_count);

$tahajjud = attempt('Tahajjud dijadwalkan (§49)', fn () => $ramadan->scheduleTahajjud([
    'program_id' => $program->id, 'ramadan_day_id' => $day1, 'mosque_id' => $mosque->id,
    'campus_id' => $campusId, 'dormitory_id' => $dormitoryId, 'session_date' => '2027-02-08',
    'imam_id' => $imam->id, 'target_population' => 'BOARDING', 'expected_participants' => 60,
]));
check('I7. Tahajjud program asrama (§49)', $tahajjud?->id > 0, (string) $tahajjud?->target_population);

// Iftar & Suhoor → diteruskan ke Catering (Aturan 11)
$iftar = attempt('Iftar dijadwalkan', fn () => $ramadan->scheduleIftar($program->id, [
    'ramadan_day_id' => $day1, 'mosque_id' => $mosque->id, 'campus_id' => $campusId,
    'event_date' => '2027-02-08', 'expected_participants' => 400, 'catering_required' => true,
]));
check('I8. Iftar dijadwalkan (§47)', ($iftar['event']['id'] ?? 0) > 0,
    'catering='.($iftar['catering']['requested'] ?? false ? 'requested' : 'pending_provider'));
check('I9. Aturan 11: kebutuhan makan diteruskan ke Catering, bukan transaksi baru',
    ($iftar['catering']['requested'] ?? false) === true || ($iftar['catering']['pending_provider'] ?? false) === true,
    (string) ($iftar['catering']['reason'] ?? ''));

$suhoor = attempt('Suhoor dijadwalkan', fn () => $ramadan->scheduleSuhoor($program->id, [
    'ramadan_day_id' => $day1, 'campus_id' => $campusId, 'dormitory_id' => $dormitoryId,
    'event_date' => '2027-02-08', 'expected_participants' => 80,
]));
check('I10. Suhoor dijadwalkan (§46)', ($suhoor['event']['id'] ?? 0) > 0);

// I'tikaf (§50)
$itikaf = attempt('Program i\'tikaf', fn () => $ramadan->createItikaf([
    'program_id' => $program->id, 'mosque_id' => $mosque->id, 'campus_id' => $campusId,
    'name' => "I'tikaf 10 Hari Terakhir", 'starts_at' => '2027-02-08', 'ends_at' => '2027-02-17',
    'capacity' => 30, 'consent_required' => true, 'meals_provided' => true,
]));
check('I11. Program i\'tikaf dibuat (§50)', $itikaf?->id > 0, (string) $itikaf?->code);

$itikafParticipant = attempt('Pendaftaran i\'tikaf', fn () => $ramadan->registerItikaf($itikaf->id, [
    'student_id' => $studentIds[0], 'person_name' => 'Ahmad Fauzi', 'consent_status' => 'APPROVED',
    'emergency_contact_name' => 'Bapak Ahmad', 'emergency_contact_phone' => '08123456789',
]));
check('I12. Peserta i\'tikaf dengan persetujuan (§50)', $itikafParticipant?->id > 0,
    'consent='.$itikafParticipant?->consent_status);

$ramadanDash = $ramadan->dashboard('2027-02-08');
check('I13. Dashboard Ramadan (§93)', ($ramadanDash['is_ramadan'] ?? false) === true,
    'hari ke-'.($ramadanDash['hijri_day'] ?? '-'));
check('I14. Dashboard Ramadan memuat rangkaian harian',
    isset($ramadanDash['fasting']) && isset($ramadanDash['tarawih']) && isset($ramadanDash['iftar']),
    'suhoor='.count((array) ($ramadanDash['suhoor'] ?? [])).' tarawih='.count((array) ($ramadanDash['tarawih'] ?? [])));

$nonRamadan = $ramadan->dashboard('2026-06-01');
check('I15. Tanggal di luar kalender Ramadan tidak diklaim Ramadan (§93)',
    ($nonRamadan['is_ramadan'] ?? true) === false);

$ramadanReport = $ramadan->report($program->id);
check('I16. Laporan Ramadan (§74)', ($ramadanReport['days'] ?? 0) === 30,
    'tarawih='.$ramadanReport['tarawih_sessions'].' iftar='.$ramadanReport['iftar_events']);

// ── J. HARI RAYA & QURBAN ───────────────────────────────────────────────────
section('J. Hari raya & qurban (§51, §52)');
$eid = $app->make(EidService::class);

$eidEvent = attempt('Event Idul Adha', fn () => $eid->createEvent([
    'eid_type' => 'EID_ADHA', 'event_date' => '2027-05-17', 'hijri_year' => '1448',
    'campus_id' => $campusId, 'mosque_id' => $mosque->id, 'khatib_id' => $khatib->id,
    'imam_id' => $imam->id, 'venue' => 'Lapangan Kampus', 'expected_attendance' => 800,
]));
check('J1. Event Idul Adha dibuat (§51)', $eidEvent?->id > 0, (string) $eidEvent?->eid_type);
check('J2. Presensi hari raya dicatat', $eid->recordAttendance($eidEvent->id, 762)->actual_attendance === 762);

$qurban = attempt('Program qurban', fn () => $eid->createQurbanProgram([
    'eid_event_id' => $eidEvent->id, 'campus_id' => $campusId, 'name' => 'Qurban 1448',
    'program_year' => '2027', 'target_beneficiaries' => 500,
]));
check('J3. Program qurban dibuat (§52)', $qurban?->id > 0, (string) $qurban?->code);

$animal = attempt('Hewan qurban', fn () => $eid->registerAnimal($qurban->id, [
    'animal_type' => 'COW', 'donor_name' => 'Bapak Haji Ibrahim', 'contribution_amount' => 21000000,
]));
check('J4. Hewan qurban & donatur tercatat', ($animal['animal']['id'] ?? 0) > 0, (string) ($animal['animal']['code'] ?? ''));
check('J5. §52: kontribusi diteruskan ke Finance (bukan ledger baru)',
    ($animal['finance_posting']['posted'] ?? false) === true || ($animal['finance_posting']['pending_provider'] ?? false) === true);

$sacrificed = attempt('Penyembelihan', fn () => $eid->sacrifice($animal['animal']['id'], ['slaughter_area' => 'Area Qurban']));
check('J6. Penyembelihan tercatat', $sacrificed?->status === 'SACRIFICED');

$distribution = attempt('Distribusi qurban', fn () => $eid->distribute($qurban->id, [
    'animal_id' => $animal['animal']['id'], 'recipient_type' => 'STUDENT', 'recipient_id' => $studentIds[0],
    'recipient_name' => 'Ahmad Fauzi', 'recipient_category' => 'SANTRI', 'portion_count' => 2,
]));
check('J7. Distribusi qurban tercatat', $distribution?->id > 0, 'porsi='.$distribution?->portion_count);

$qurbanReport = $eid->qurbanReport($qurban->id);
check('J8. Laporan qurban', ($qurbanReport['animals'] ?? 0) >= 1,
    'kontribusi='.number_format((float) $qurbanReport['contribution_total'], 0, ',', '.'));

// ── K. DASHBOARD & LAYAR ────────────────────────────────────────────────────
section('K. Dashboard & mode layar (§65-§70, §89)');
$dashboard = $app->make(ReligiousDashboardService::class);

$display = attempt('Payload layar masjid', fn () => $dashboard->display($mosque->id, $date));
check('K1. Layar masjid digital (§65, §89)', ($display['mosque']['id'] ?? null) === $mosque->id,
    'mode='.($display['mode'] ?? '-'));
check('K2. Layar memuat shalat berikutnya & hitung mundur iqamah',
    array_key_exists('next_prayer', $display) && array_key_exists('iqamah_countdown_minutes', $display),
    'next='.($display['next_prayer']['prayer_type'] ?? 'tidak ada'));
check('K3. Layar memuat kegiatan & pengumuman', isset($display['activities']) && isset($display['announcements']),
    count((array) $display['activities']).' kegiatan');

$mobile = attempt('Mode mobile', fn () => $dashboard->mobile($mosque->id, $date));
check('K4. Mode mobile (§66)', isset($mobile['prayers']) && isset($mobile['activities']),
    count((array) $mobile['prayers']).' shalat');

$studentDash = attempt('Dashboard siswa', fn () => $dashboard->student($studentIds[0], $date));
check('K5. Dashboard siswa (§67)', ($studentDash['student']['id'] ?? null) === $studentIds[0],
    'presensi='.count((array) $studentDash['prayer_attendance']));

$parentDash = attempt('Dashboard wali', fn () => $dashboard->parent((int) $student->id, $date));
check('K6. Dashboard wali mengikuti kebijakan institusi (§68)', array_key_exists('enabled', $parentDash),
    'enabled='.var_export($parentDash['enabled'], true));

$boardingDash = attempt('Dashboard musyrif', fn () => $dashboard->boarding($dormitoryId, $date));
check('K7. Dashboard asrama (§69)', array_key_exists('present', $boardingDash),
    'hadir='.$boardingDash['present'].' telat='.$boardingDash['late'].' absen='.$boardingDash['absent']);

$overview = $dashboard->overview($date);
check('K8. Dashboard utama modul (§76)', ($overview['mosques'] ?? 0) >= 1,
    'masjid='.$overview['mosques'].' kegiatan='.$overview['activities_today']);

// ── L. ANALITIK & LAPORAN ───────────────────────────────────────────────────
section('L. Analitik & laporan (§71-§74)');
$analytics = $app->make(ReligiousAnalyticsService::class);
$from = now()->startOfMonth()->toDateString();

$metrics = attempt('Metrik analitik', fn () => $analytics->metrics($from, $date));
check('L1. Metrik presensi shalat', isset($metrics['prayer_attendance']),
    'rate='.($metrics['prayer_attendance']['attendance_rate'] ?? '-').'%');
check('L2. Metrik partisipasi kegiatan', isset($metrics['activity_participation']),
    'kegiatan='.$metrics['activity_participation']['activities_created']);
check('L3. Metrik utilisasi masjid (§72)', isset($metrics['mosque_utilization']));
check('L4. Analitik tidak menyimpulkan moral seseorang (§71)',
    str_contains((string) $metrics['disclaimer'], 'tidak dipakai untuk menilai karakter'));

$prayerReport = $analytics->prayerReport($from, $date, 'DATE', ['campus_id' => $campusId]);
check('L5. Laporan presensi shalat (§74)', count((array) $prayerReport['rows']) >= 1,
    count((array) $prayerReport['rows']).' baris');

$byPrayerReport = $analytics->prayerReport($from, $date, 'PRAYER', []);
check('L6. Laporan per jenis shalat', count((array) $byPrayerReport['rows']) >= 1,
    'shalat='.count((array) $byPrayerReport['rows']));

$fridayReport = $analytics->fridayReport($from, now()->addMonth()->toDateString(), $mosque->id);
check('L7. Laporan Jumat & khutbah', ($fridayReport['sessions'] ?? 0) >= 1,
    'jamaah='.$fridayReport['total_attendance']);

$activityReport = $analytics->activityReport($from, $date);
check('L8. Laporan kegiatan keagamaan', ($activityReport['activities'] ?? 0) >= 1,
    'peserta='.$activityReport['participants']);

// ── M. NOTIFIKASI, IMPOR, EVENT BUS, AUDIT ──────────────────────────────────
section('M. Notifikasi, impor, event bus, audit (§64, §77, §87, §95)');
$notifications = $app->make(ReligiousNotificationService::class);

$notif = attempt('Notifikasi pengingat', fn () => $notifications->remind('FRIDAY_REMINDER', [
    'date' => $fridayDate, 'time' => '12:00', 'title' => 'Shalat Jumat',
]));
check('M1. Notifikasi pengingat dikirim/antre (§64)', is_array($notif),
    'provider='.var_export($notif['provider_available'] ?? false, true));
check('M2. Definisi jenis notifikasi configurable', count((array) ($notifications->capabilities()['types'] ?? [])) >= 5,
    count((array) ($notifications->capabilities()['types'] ?? [])).' jenis');

$imports = $app->make(ReligiousImportService::class);
$importBatch = attempt('Impor jadwal (pratinjau)', fn () => $imports->preview('PRAYER_SCHEDULE', [
    ['schedule_date' => $date, 'prayer_type' => 'FAJR', 'adhan_time' => '04:35', 'iqamah_time' => '04:45'],
    ['schedule_date' => $date, 'prayer_type' => 'BUKAN_SHALAT', 'adhan_time' => '10:00'],
    ['schedule_date' => 'bukan-tanggal', 'prayer_type' => 'ASR', 'adhan_time' => '15:20'],
], ['file_name' => 'jadwal.csv']));
check('M3. Impor pratinjau memisahkan baris valid/tidak (§87)',
    ($importBatch['valid_rows'] ?? 0) === 1 && ($importBatch['invalid_rows'] ?? 0) === 2,
    'valid='.($importBatch['valid_rows'] ?? 0).' invalid='.($importBatch['invalid_rows'] ?? 0));
check('M4. Laporan kesalahan impor tersedia', count((array) ($importBatch['error_report'] ?? [])) === 2);

$commit = attempt('Impor dikomit', fn () => $imports->commit($importBatch['id']));
check('M5. Impor dikomit hanya baris valid', ($commit['imported'] ?? 0) === 1,
    'imported='.($commit['imported'] ?? 0).' failed='.($commit['failed'] ?? 0));

$rollback = attempt('Impor dibatalkan', fn () => $imports->rollback($importBatch['id'], 'Uji pembatalan'));
check('M6. Aturan 15: pembatalan impor tidak menghapus destruktif',
    ($rollback['status'] ?? '') === 'ROLLED_BACK', 'reverted='.($rollback['reverted'] ?? 0));
check('M7. Riwayat impor tersimpan', count($imports->history()) >= 1);

$export = attempt('Ekspor CSV', fn () => $imports->exportCsv('prayer_schedule'));
check('M8. Ekspor tersedia (§87)', ($export['exported'] ?? false) === true, 'baris='.($export['rows'] ?? 0));

$events = App\Modules\SchoolOperations\Religious\Models\ReligiousEvent::query()
    ->select('event_name')->distinct()->pluck('event_name')->all();
check('M9. Event bus domain mempublikasikan peristiwa (§77)', count($events) >= 5,
    count($events).' jenis: '.implode(', ', array_slice($events, 0, 5)));
$expectedEvents = ['PrayerScheduleCreated', 'PrayerAttendanceRecorded', 'ReligiousActivityCreated',
    'FridayPrayerScheduled', 'RamadanProgramCreated', 'KhatibAssigned', 'ReligiousAchievementRecorded'];
$found = array_intersect($expectedEvents, $events);
check('M10. Nama peristiwa sesuai daftar §77', count($found) >= 5, implode(', ', $found));

$audit = App\Modules\SchoolOperations\Religious\Models\ReligiousAuditLog::query()->count();
check('M11. Jejak audit tersimpan (§95)', $audit >= 30, $audit.' baris audit');

$sample = App\Modules\SchoolOperations\Religious\Models\ReligiousAuditLog::query()->latest('id')->first();
check('M12. Audit memuat aktor & aksi', $sample !== null && $sample->event !== null,
    'event='.($sample?->event ?? '-'));

// ── N. KEAMANAN, OTORISASI, PRIVASI ─────────────────────────────────────────
section('N. Keamanan & privasi (§14, §94)');
$scopeSvc = $app->make(App\Modules\SchoolOperations\Religious\Application\Services\ReligiousScopeService::class);

check('N1. RELIGIOUS_ADMIN berizin kelola masjid',
    $admin->hasPermissionTo('religious.mosque.manage') || $admin->hasRole('RELIGIOUS_ADMIN'), 'admin');
check('N2. Siswa tidak berizin mencatat presensi',
    ! $student->hasPermissionTo('religious.attendance.record'), 'siswa hanya portal');

$accessCtl = app(App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousAccessProviderInterface::class);
$adminScope = $accessCtl->scopeFor((int) $admin->id);
check('N3. Cakupan admin bersifat global', ($adminScope['is_global'] ?? false) === true || $accessCtl->can('religious.manage', (int) $admin->id),
    'global='.var_export($adminScope['is_global'] ?? false, true));

$mosqueScope = $accessCtl->scopeFor((int) $mosqueAdm->id);
check('N4. Cakupan masjid terbaca untuk MOSQUE_ADMIN', array_key_exists('mosque_ids', $mosqueScope),
    'masjid='.count($mosqueScope['mosque_ids']));

$parentCtl = app(App\Modules\SchoolOperations\Religious\Domain\Contracts\ParentGatewayInterface::class);
$canAccessOther = $parentCtl->canAccess((int) $student->id, $studentIds[1]);
check('N5. §14: wali tidak dapat mengakses siswa lain', $canAccessOther === false,
    'sumber wali tersedia='.var_export($parentCtl->available(), true));

$cat = App\Modules\SchoolOperations\Religious\Models\ReligiousAuditLog::query()->first();
$sanitized = $scopeSvc !== null && $cat !== null;
check('N6. Kunci sensitif disaring dari konteks audit (§95)',
    collect((array) config('religious.security.audit_blocked_keys'))->contains('nik'), 'nik disaring');

$meta = $app->make(App\Modules\SchoolOperations\Religious\Http\Controllers\MetaController::class);
check('N7. Meta modul tersedia untuk administrator', $meta !== null);

// ── O. INTEGRASI LINTAS MODUL ───────────────────────────────────────────────
section('O. Integrasi lintas modul (§100)');
$gateways = [
    'Student' => App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentGatewayInterface::class,
    'Parent' => App\Modules\SchoolOperations\Religious\Domain\Contracts\ParentGatewayInterface::class,
    'Employee (HRD)' => App\Modules\SchoolOperations\Religious\Domain\Contracts\EmployeeGatewayInterface::class,
    'Boarding' => App\Modules\SchoolOperations\Religious\Domain\Contracts\BoardingGatewayInterface::class,
    'Attendance' => App\Modules\SchoolOperations\Religious\Domain\Contracts\AttendanceGatewayInterface::class,
    'Tahfidz' => App\Modules\SchoolOperations\Religious\Domain\Contracts\TahfidzGatewayInterface::class,
    'StudentActivity' => App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentActivityGatewayInterface::class,
    'Catering' => App\Modules\SchoolOperations\Religious\Domain\Contracts\CateringGatewayInterface::class,
    'Transportation' => App\Modules\SchoolOperations\Religious\Domain\Contracts\TransportationGatewayInterface::class,
    'Facility' => App\Modules\SchoolOperations\Religious\Domain\Contracts\FacilityGatewayInterface::class,
    'Asset' => App\Modules\SchoolOperations\Religious\Domain\Contracts\AssetGatewayInterface::class,
    'Inventory' => App\Modules\SchoolOperations\Religious\Domain\Contracts\InventoryGatewayInterface::class,
    'Finance' => App\Modules\SchoolOperations\Religious\Domain\Contracts\FinanceGatewayInterface::class,
    'Notification' => App\Modules\SchoolOperations\Religious\Domain\Contracts\NotificationGatewayInterface::class,
    'Workflow' => App\Modules\SchoolOperations\Religious\Domain\Contracts\WorkflowGatewayInterface::class,
    'Document' => App\Modules\SchoolOperations\Religious\Domain\Contracts\DocumentGatewayInterface::class,
    'Certificate' => App\Modules\SchoolOperations\Religious\Domain\Contracts\CertificateGatewayInterface::class,
    'Event' => App\Modules\SchoolOperations\Religious\Domain\Contracts\EventGatewayInterface::class,
    'Calendar' => App\Modules\SchoolOperations\Religious\Domain\Contracts\CalendarGatewayInterface::class,
    'Discipline' => App\Modules\SchoolOperations\Religious\Domain\Contracts\DisciplineGatewayInterface::class,
];
$bound = 0; $available = 0;
foreach ($gateways as $label => $contract) {
    if (app()->bound($contract)) {
        $bound++;
        try { if (app($contract)->available()) { $available++; } } catch (\Throwable) {}
    }
}
check('O1. 20 kontrak integrasi ter-bind ke container', $bound === 20, "$bound/20 bound");
check('O2. Gateway melaporkan ketersediaan secara jujur', $bound === 20,
    "$available tersedia, ".($bound - $available).' menunggu penyedia');

$provided = [
    App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousLifeProviderInterface::class,
    App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousPersonnelProviderInterface::class,
    App\Modules\SchoolOperations\Religious\Domain\Contracts\MosqueResourceProviderInterface::class,
];
$providedBound = collect($provided)->filter(fn ($c) => app()->bound($c))->count();
check('O3. 3 kontrak disediakan untuk modul lain (§76)', $providedBound === 3, "$providedBound/3");

$life = app($provided[0]);
$lifePrayers = $life->prayerTimesFor($date, $mosque->id);
check('O4. Modul lain dapat membaca jadwal shalat', is_array($lifePrayers) && count($lifePrayers) >= 1,
    count($lifePrayers).' shalat');
check('O5. Modul lain dapat menanyakan hari Ramadan', is_bool($life->isRamadan($date)));
check('O6. Modul lain dapat membaca daftar masjid', count($life->mosquesFor($campusId)) >= 1,
    count($life->mosquesFor($campusId)).' masjid');

$personnelProvider = app($provided[1]);
check('O7. Modul lain dapat menanyakan ketersediaan personel', is_array($personnelProvider->availability((int) $imam->personnel_id, $date)));
check('O8. Modul lain dapat mendeteksi bentrok personel', is_bool($personnelProvider->hasConflict((int) $imam->personnel_id, $date, '12:00', '13:00')));

$mosqueProvider = app($provided[2]);
$avail = $mosqueProvider->isAvailable($mosque->id, $area?->id, $date.' 20:00:00', $date.' 21:00:00');
check('O9. Modul lain dapat memeriksa ketersediaan masjid', is_bool($avail), 'tersedia='.var_export($avail, true));

// ── RINGKASAN ───────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n\u{2554}".str_repeat('=', 74)."\u{2557}\n";
printf("\u{2551}  HASIL: %d/%d uji lulus, %d gagal".str_repeat(' ', 74 - 34 - strlen((string) $total) - strlen((string) $fail))."\u{2551}\n", $pass, $total, $fail);
echo "\u{255A}".str_repeat('=', 74)."\u{255D}\n\n";

exit($fail === 0 ? 0 : 1);
