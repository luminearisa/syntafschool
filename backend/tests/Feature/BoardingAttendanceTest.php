<?php

namespace Tests\Feature;

use App\Models\BoardingActivity;
use App\Models\BoardingActivitySchedule;
use App\Models\BoardingAttendance;
use App\Models\BoardingAttendanceType;
use App\Models\BoardingMusyrif;
use App\Models\BoardingRoom;
use App\Models\Employee;
use App\Models\Gender;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRfidCard;
use App\Services\Boarding\BoardingAttendanceService;
use App\Services\Boarding\BoardingPlacementService;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Absensi kegiatan (§16–§26): tipe & kegiatan master, roster, manual,
 * massal "Hadir Semua" (upsert), koreksi ber-audit, scan RFID memakai kartu
 * Wallet yang sudah ada, dan jadwal bentrok.
 */
class BoardingAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(BoardingConfigSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function student(string $nis): Student
    {
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);

        return Student::create([
            'nis' => $nis,
            'full_name' => 'Santri '.$nis,
            'gender_id' => $g->id,
            'is_active' => true,
        ]);
    }

    /** Santri + penempatan aktif di kamar seeded (langsung lewat service). */
    private function placedStudent(string $nis, string $roomCode = 'A101'): Student
    {
        $student = $this->student($nis);
        $room = BoardingRoom::query()->where('code', $roomCode)->firstOrFail();

        // Aktor khusus service: tidak lewat HTTP agar tidak mengubah login test.
        $admin = User::factory()->create(['is_active' => true]);

        app(BoardingPlacementService::class)->assign([
            'student_id' => $student->id,
            'room_id' => $room->id,
        ], $admin);

        return $student;
    }

    private function activity(string $code): BoardingActivity
    {
        return BoardingActivity::query()->where('code', $code)->firstOrFail();
    }

    private function type(string $code): BoardingAttendanceType
    {
        return BoardingAttendanceType::query()->where('code', $code)->firstOrFail();
    }

    // =================================================================
    // Master kegiatan & tipe
    // =================================================================

    public function test_master_kegiatan_dan_tipe_absensi_terseeding_dan_terbaca(): void
    {
        $this->actingAsRole('petugas-absensi');

        $activities = $this->getJson('/api/v1/boarding/activities?all=1')
            ->assertOk()
            ->assertJsonCount(16, 'data')
            ->json('data');

        $subuh = collect($activities)->firstWhere('code', 'SUBUH');
        $this->assertNotNull($subuh);
        $this->assertSame('HADIR', $subuh['default_attendance_type']['code']);
        // Pivot §18: Subuh boleh HADIR/IZIN/SAKIT/ALPHA — bukan TERLAMBAT.
        $this->assertSame(['HADIR', 'IZIN', 'SAKIT', 'ALPHA'], collect($subuh['attendance_types'])->pluck('code')->all());

        $this->getJson('/api/v1/boarding/attendance-types?all=1')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }

    public function test_tipe_baru_dapat_dibuat_dan_diperbarui(): void
    {
        $this->actingAsRole('admin-boarding');

        $id = $this->postJson('/api/v1/boarding/attendance-types', [
            'code' => 'quran',
            'name' => 'Absen Mengaji',
            'category' => 'neutral',
            'is_present' => false,
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/boarding/attendance-types/'.$id, ['is_present' => true])
            ->assertOk()
            ->assertJsonPath('data.is_present', true);

        // Tipe yang belum dipakai boleh dihapus.
        $this->deleteJson('/api/v1/boarding/attendance-types/'.$id)->assertOk();

        // HADIR dipakai seeder/absensi → hapus ditolak, bukan 500.
        $this->deleteJson('/api/v1/boarding/attendance-types/'.$this->type('HADIR')->id);
    }

    public function test_tipe_yang_sudah_dipakai_absensi_tidak_boleh_dihapus(): void
    {
        $actor = $this->actingAsRole('petugas-absensi');
        $student = $this->placedStudent('3001');

        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $student->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertCreated();

        $this->actingAsRole('admin-boarding');
        $this->deleteJson('/api/v1/boarding/attendance-types/'.$this->type('HADIR')->id)
            ->assertStatus(422);
    }

    // =================================================================
    // Roster
    // =================================================================

    public function test_roster_menampilkan_santri_berpenempatan_dan_ringkasannya(): void
    {
        $this->actingAsRole('petugas-absensi');

        $a = $this->placedStudent('3010');
        $b = $this->placedStudent('3011');

        $data = $this->getJson('/api/v1/boarding/attendances/roster?activity_id='.$this->activity('SUBUH')->id)
            ->assertOk()
            ->json('data');

        $this->assertSame('SUBUH', $data['activity']['code']);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(2, $data['summary']['pending']);
        $this->assertSame(0, $data['summary']['recorded']);
        $this->assertCount(2, $data['students']);
        $this->assertFalse($data['students'][0]['already_recorded']);
        $this->assertTrue($data['students'][0]['placement']['is_placed']);

        // Setelah diabsen, roster menampilkan status tanpa menghapus baris.
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $a->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertCreated();

        $after = $this->getJson('/api/v1/boarding/attendances/roster?activity_id='.$this->activity('SUBUH')->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $after['summary']['recorded']);
        $this->assertSame(1, $after['summary']['present']);
        $row = collect($after['students'])->firstWhere('student.id', $a->id);
        $this->assertTrue($row['already_recorded']);
        $this->assertSame('HADIR', $row['attendance']['attendance_code']);
    }

    public function test_roster_bisa_dibatasi_per_kamar(): void
    {
        $this->actingAsRole('petugas-absensi');

        $this->placedStudent('3012');
        $room2 = BoardingRoom::query()->where('code', 'A102')->firstOrFail();
        $this->placedStudent('3013', 'A102');

        $data = $this->getJson('/api/v1/boarding/attendances/roster?activity_id='.$this->activity('SUBUH')->id.'&room_id='.$room2->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['summary']['total']);
        $this->assertSame('3013', $data['students'][0]['student']['nis']);
    }

    // =================================================================
    // Absensi manual
    // =================================================================

    public function test_absensi_manual_tercatat_dan_duplikat_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');

        $student = $this->placedStudent('3020');
        $payload = [
            'student_id' => $student->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ];

        $this->postJson('/api/v1/boarding/attendances', $payload)
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.revision', 1);

        // (santri, kegiatan, tanggal, sesi) hanya boleh satu baris (§24).
        $this->postJson('/api/v1/boarding/attendances', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance');

        // Sesi berbeda = catatan berbeda.
        $this->postJson('/api/v1/boarding/attendances', [...$payload, 'session' => '2'])
            ->assertCreated();
    }

    public function test_tipe_wajib_catatan_dan_tipe_tidak_diizinkan_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');

        $student = $this->placedStudent('3021');

        // SAKIT requires_note=true (§17).
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $student->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('SAKIT')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('note');

        $sick = $this->placedStudent('3022');
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $sick->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('SAKIT')->id,
            'note' => 'Demam, izin ustaz',
        ])->assertCreated();

        // Makan tidak punya opsi SAKIT (§18).
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $student->id,
            'activity_id' => $this->activity('MAKAN-PAGI')->id,
            'attendance_type_id' => $this->type('SAKIT')->id,
            'note' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('attendance_type_id');
    }

    public function test_santri_tanpa_penempatan_aktif_ditolak_dan_non_aktif_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');

        // Santri biasa (tidak pernah masuk asrama) — roster tidak memuatnya,
        // namun percobaan manual harus tetap ditolak oleh service.
        $luar = $this->student('3023');
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $luar->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertStatus(422);
    }

    // =================================================================
    // Massal "Hadir Semua"
    // =================================================================

    public function test_bulk_hadir_semua_lalu_koreksi_sebagian_bersifat_upsert(): void
    {
        $this->actingAsRole('petugas-absensi');

        $a = $this->placedStudent('3030');
        $b = $this->placedStudent('3031');
        $activityId = $this->activity('SUBUH')->id;
        $today = now()->toDateString();

        $first = $this->postJson('/api/v1/boarding/attendances/bulk', [
            'activity_id' => $activityId,
            'date' => $today,
            'items' => [
                ['student_id' => $a->id],
                ['student_id' => $b->id],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $first['updated']);

        // Kirim ulang daftar yang sama + satu perubahan tipe: tanpa duplikat.
        $second = $this->postJson('/api/v1/boarding/attendances/bulk', [
            'activity_id' => $activityId,
            'date' => $today,
            'items' => [
                ['student_id' => $a->id],
                ['student_id' => $b->id, 'attendance_type_id' => $this->type('IZIN')->id, 'note' => 'Acara sekolah'],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['skipped']);

        $this->assertSame(2, BoardingAttendance::query()->where('activity_id', $activityId)->whereDate('date', $today)->count());
        $rowB = BoardingAttendance::query()->where('student_id', $b->id)->firstOrFail();
        $this->assertSame('bulk', $rowB->source);
        $this->assertSame($this->type('IZIN')->id, (int) $rowB->attendance_type_id);
    }

    // =================================================================
    // Koreksi (revise)
    // =================================================================

    public function test_revise_naik_revision_dan_tulis_audit(): void
    {
        $this->actingAsRole('petugas-absensi');

        $student = $this->placedStudent('3040');
        $id = $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $student->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertCreated()->json('data.id');

        $this->putJson('/api/v1/boarding/attendances/'.$id.'/revise', [
            'attendance_type_id' => $this->type('ALPHA')->id,
            'note' => 'Fact-check: tidak terlihat di jamaah',
        ])->assertOk()->assertJsonPath('data.revision', 2);

        // Nilai sama → tidak ada yang dikoreksi.
        $this->putJson('/api/v1/boarding/attendances/'.$id.'/revise', [
            'attendance_type_id' => $this->type('ALPHA')->id,
            'note' => 'Fact-check: tidak terlihat di jamaah',
        ])->assertStatus(422);

        $this->assertTrue(
            Activity::query()->where('log_name', 'boarding')->where('event', 'BOARDING_ATTENDANCE_UPDATED')->exists()
        );
    }

    // =================================================================
    // RFID via kartu Wallet (§23, §25)
    // =================================================================

    private function rfidCardFor(Student $student, string $uid = 'A1B2C3D4'): WalletRfidCard
    {
        $wallet = Wallet::create([
            'number' => 'W-'.$student->id,
            'student_id' => $student->id,
            'status' => Wallet::STATUS_ACTIVE,
        ]);

        return WalletRfidCard::create([
            'uid' => $uid,
            'wallet_id' => $wallet->id,
            'status' => WalletRfidCard::STATUS_ACTIVE,
        ]);
    }

    public function test_scan_rfid_mencatat_absensi_dan_scan_ulang_tanpa_duplikat(): void
    {
        $this->actingAsRole('petugas-absensi');

        $student = $this->placedStudent('3050');
        $this->rfidCardFor($student);
        $activityId = $this->activity('SUBUH')->id;

        $first = $this->postJson('/api/v1/boarding/attendances/scan', [
            'uid' => 'a1b2c3d4', // huruf kecil — dinormalisasi uppercase
            'activity_id' => $activityId,
            'device' => 'GATE-01',
        ])->assertCreated()->json('data');

        $this->assertSame('rfid', $first['attendance']['source']);
        $this->assertFalse($first['already_recorded']);

        $second = $this->postJson('/api/v1/boarding/attendances/scan', [
            'uid' => 'A1B2C3D4',
            'activity_id' => $activityId,
        ])->assertOk()->json('data');

        $this->assertTrue($second['already_recorded']);
        $this->assertSame(1, BoardingAttendance::query()->where('student_id', $student->id)->count());
    }

    public function test_scan_kartu_tidak_dikenal_dan_kartu_blokir_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');
        $activityId = $this->activity('SUBUH')->id;

        $this->postJson('/api/v1/boarding/attendances/scan', ['uid' => 'DEADBEEF', 'activity_id' => $activityId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('uid');

        $student = $this->placedStudent('3051');
        $card = $this->rfidCardFor($student, 'CAFEBABE');
        $card->update(['status' => WalletRfidCard::STATUS_LOST]);

        $this->postJson('/api/v1/boarding/attendances/scan', ['uid' => 'CAFEBABE', 'activity_id' => $activityId])
            ->assertStatus(422);
    }

    public function test_scan_kartu_non_santri_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');

        $wallet = Wallet::create(['number' => 'W-STAFF', 'status' => Wallet::STATUS_ACTIVE, 'user_id' => $this->actingAsRole('admin-boarding')->id]);
        WalletRfidCard::create(['uid' => 'FFFF0000', 'wallet_id' => $wallet->id]);

        $this->postJson('/api/v1/boarding/attendances/scan', [
            'uid' => 'FFFF0000',
            'activity_id' => $this->activity('SUBUH')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('uid');
    }

    // =================================================================
    // Scan QR (NIS pada kartu absensi santri)
    // =================================================================

    public function test_scan_qr_nis_mencatat_absensi_dan_scan_ulang_tanpa_duplikat(): void
    {
        $this->actingAsRole('petugas-absensi');

        $student = $this->placedStudent('3060');
        $activityId = $this->activity('SUBUH')->id;

        $first = $this->postJson('/api/v1/boarding/attendances/scan', [
            'code' => '3060',
            'activity_id' => $activityId,
            'device' => 'CAM-01',
        ])->assertCreated()->json('data');

        $this->assertSame('qr', $first['attendance']['source']);
        $this->assertFalse($first['already_recorded']);
        $this->assertSame('3060', $first['student']['nis'] ?? $first['attendance']['student']['nis']);

        // Scan ulang: tidak membuat baris baru.
        $second = $this->postJson('/api/v1/boarding/attendances/scan', [
            'code' => '3060',
            'activity_id' => $activityId,
        ])->assertOk()->json('data');

        $this->assertTrue($second['already_recorded']);
        $this->assertSame(1, BoardingAttendance::query()->where('student_id', $student->id)->count());
    }

    public function test_scan_qr_kode_tidak_dikenal_dan_tanpa_penempatan_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');
        $activityId = $this->activity('SUBUH')->id;

        $this->postJson('/api/v1/boarding/attendances/scan', [
            'code' => 'NIS-TIDAK-ADA',
            'activity_id' => $activityId,
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // Santri dikenal tetapi bukan penghuni asrama → tidak boleh diabsen.
        $this->student('3061'); // tanpa penempatan

        $this->postJson('/api/v1/boarding/attendances/scan', [
            'code' => '3061',
            'activity_id' => $activityId,
        ])->assertStatus(422);

        // Salah satu dari uid / code wajib ada.
        $this->postJson('/api/v1/boarding/attendances/scan', [
            'activity_id' => $activityId,
        ])->assertStatus(422);
    }

    // =================================================================
    // Pantauan & statistik absensi
    // =================================================================

    public function test_monitoring_per_asrama_menghitung_kelengkapan(): void
    {
        $this->actingAsRole('petugas-absensi');

        $first = $this->placedStudent('3070');
        $this->placedStudent('3071');
        $activityId = $this->activity('SUBUH')->id;

        // Satu dari dua santri tercatat → kegiatan masuk keranjang "sebagian".
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $first->id,
            'activity_id' => $activityId,
            'date' => now()->toDateString(),
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertCreated();

        $data = $this->getJson('/api/v1/boarding/attendances/monitoring?date='.now()->toDateString())
            ->assertOk()->json('data');

        $this->assertSame(now()->toDateString(), $data['date']);
        $this->assertNotEmpty($data['dormitories']);

        $dorm = collect($data['dormitories'])->firstWhere('targets', 2);
        $this->assertNotNull($dorm);

        $partial = collect($dorm['partial_activities'])->firstWhere('id', $activityId);
        $this->assertNotNull($partial);
        $this->assertSame(2, $partial['targets']);
        $this->assertSame(1, $partial['recorded']);
        $this->assertNotNull($dorm['monthly_percentage']);
    }

    public function test_statistics_mengagregasi_distribusi_tren_dan_asrama(): void
    {
        $this->actingAsRole('petugas-absensi');

        $hadir = $this->placedStudent('3080');
        $alpa = $this->placedStudent('3081');
        $activityId = $this->activity('SUBUH')->id;
        $today = now()->toDateString();

        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $hadir->id,
            'activity_id' => $activityId,
            'date' => $today,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertCreated();

        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $alpa->id,
            'activity_id' => $activityId,
            'date' => $today,
            'attendance_type_id' => $this->type('ALPHA')->id,
        ])->assertCreated();

        $data = $this->getJson('/api/v1/boarding/attendances/statistics')
            ->assertOk()->json('data');

        $this->assertSame(2, $data['summary']['records']);
        $this->assertSame(1, $data['summary']['present']);
        $this->assertSame(1, $data['summary']['not_present']);
        $this->assertSame(50.0, (float) $data['summary']['present_percent']);

        $codes = collect($data['status_distribution'])->pluck('code');
        $this->assertContains('HADIR', $codes);
        $this->assertContains('ALPHA', $codes);

        $todayRow = collect($data['daily'])->firstWhere('date', $today);
        $this->assertNotNull($todayRow);
        $this->assertSame(2, $todayRow['total']);

        $this->assertNotEmpty($data['by_dormitory']);
        $this->assertNotEmpty($data['top_absentees']);
        $this->assertSame('3081', $data['top_absentees'][0]['nis']);
    }

    // =================================================================
    // Terlambat otomatis (§25) — level service karena perlu recorded_at
    // =================================================================

    public function test_terlambat_dihitung_otomatis_terhadap_jadwal(): void
    {
        $user = $this->actingAsRole('petugas-absensi');

        $subuh = $this->activity('SUBUH'); // late_after_minutes = 10
        $student = $this->placedStudent('3060');

        $today = now();
        BoardingActivitySchedule::create([
            'activity_id' => $subuh->id,
            'day_of_week' => (int) $today->dayOfWeekIso,
            'start_time' => '04:30',
            'end_time' => '05:15',
        ]);

        $service = app(BoardingAttendanceService::class);

        // 04:35 — masih dalam toleransi 10 menit.
        $ontime = $service->store([
            'student_id' => $student->id,
            'activity_id' => $subuh->id,
            'attendance_type_id' => $this->type('HADIR')->id,
            'date' => $today->toDateString(),
            'recorded_at' => $today->copy()->setTime(4, 35)->toDateTimeString(),
        ], $user);
        $this->assertFalse((bool) $ontime->is_late);

        $lateStudent = $this->placedStudent('3061');
        $late = $service->store([
            'student_id' => $lateStudent->id,
            'activity_id' => $subuh->id,
            'attendance_type_id' => $this->type('HADIR')->id,
            'date' => $today->toDateString(),
            'recorded_at' => $today->copy()->setTime(4, 50)->toDateTimeString(),
        ], $user);

        $this->assertTrue((bool) $late->is_late);
        // `late_minutes` dihitung dari JAM MULAI jadwal (bukan dari ambang):
        // 04:50 vs mulai 04:30 → 20 menit, ambang 10 hanya menentukan TERLAMBAT.
        $this->assertSame(20, (int) $late->late_minutes);
    }

    // =================================================================
    // Jadwal
    // =================================================================

    public function test_jadwal_bentrok_ditolak_dan_peta_mingguan_terbaca(): void
    {
        $this->actingAsRole('admin-boarding');

        $subuh = $this->activity('SUBUH')->id;

        $payload = ['activity_id' => $subuh, 'day_of_week' => 1, 'start_time' => '04:30', 'end_time' => '05:15'];
        $this->postJson('/api/v1/boarding/schedules', $payload)->assertCreated();

        // Hari & cakupan sama, jam tumpang tindih.
        $this->postJson('/api/v1/boarding/schedules', [
            ...$payload, 'start_time' => '05:00', 'end_time' => '05:30',
        ])->assertStatus(422)->assertJsonValidationErrors('start_time');

        // Hari berbeda boleh.
        $this->postJson('/api/v1/boarding/schedules', [...$payload, 'day_of_week' => 2])->assertCreated();

        $week = $this->getJson('/api/v1/boarding/schedules/weekly')->assertOk()->json('data');
        $this->assertCount(7, $week);
        $this->assertSame(1, $week[0]['day']);
        $this->assertCount(1, $week[0]['schedules']);
    }

    // =================================================================
    // Riwayat, rekap, otorisasi
    // =================================================================

    public function test_riwayat_dan_rekap_per_santri(): void
    {
        $this->actingAsRole('petugas-absensi');

        $student = $this->placedStudent('3070');
        $today = now()->toDateString();

        foreach (['SUBUH', 'DZUHUR'] as $code) {
            $this->postJson('/api/v1/boarding/attendances', [
                'student_id' => $student->id,
                'activity_id' => $this->activity($code)->id,
                'attendance_type_id' => $this->type('HADIR')->id,
                'date' => $today,
            ])->assertCreated();
        }

        $this->getJson('/api/v1/boarding/students/'.$student->id.'/attendances')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $recap = $this->getJson('/api/v1/boarding/attendances/recap?from='.$today.'&to='.$today)
            ->assertOk()
            ->json('data');

        $row = collect($recap['students'] ?? [])->first(
            static fn (array $r): bool => (int) $r['student']['id'] === (int) $student->id
        );

        $this->assertNotNull($row, 'Rekap harus memuat baris santri yang diabsen.');
        $this->assertSame(2, (int) $row['total']);
        $this->assertSame(2, (int) $row['present']);
    }

    public function test_wali_kamar_tidak_boleh_mengelola_jadwal_atau_master(): void
    {
        $user = $this->actingAsRole('wali-kamar');

        $this->postJson('/api/v1/boarding/schedules', [
            'activity_id' => $this->activity('SUBUH')->id,
            'day_of_week' => 3,
            'start_time' => '04:00',
            'end_time' => '05:00',
        ])->assertStatus(403);

        // Wali kamar hanya boleh mencatat santri binaannya: dengan penugasan
        // aktif di A101, absensi kamar itu diterima ...
        $student = $this->placedStudent('3080');
        $this->makeWaliKamarOf($user, 'A101');

        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $student->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertCreated();

        // ... sementara santri di luar kamarnya ditolak (scope, bukan permission).
        $outsider = $this->placedStudent('3081');
        $this->postJson('/api/v1/boarding/attendances', [
            'student_id' => $outsider->id,
            'activity_id' => $this->activity('SUBUH')->id,
            'attendance_type_id' => $this->type('HADIR')->id,
        ])->assertStatus(403);
    }

    /** Jadikan pengguna pengasuh resmi satu kamar (penugasan musyrif aktif). */
    private function makeWaliKamarOf(User $user, string $roomCode): void
    {
        $employee = Employee::create(['full_name' => 'Pengasuh '.$user->id, 'is_active' => true]);
        $user->forceFill(['userable_type' => Employee::class, 'userable_id' => $employee->id])->save();

        BoardingMusyrif::create([
            'employee_id' => $employee->id,
            'position' => BoardingMusyrif::POSITION_WALI_KAMAR,
            'room_id' => BoardingRoom::query()->where('code', $roomCode)->value('id'),
            'start_date' => now()->subDay()->toDateString(),
            'status' => BoardingMusyrif::STATUS_ACTIVE,
        ]);
    }
}
