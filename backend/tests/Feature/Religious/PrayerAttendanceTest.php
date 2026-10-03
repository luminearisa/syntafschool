<?php

namespace Tests\Feature\Religious;

use App\Models\User;
use App\Modules\SchoolOperations\Religious\Application\Services\PrayerAttendanceService;
use App\Modules\SchoolOperations\Religious\Models\Mosque;
use App\Modules\SchoolOperations\Religious\Models\PrayerAttendancePolicy;
use App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord;
use App\Modules\SchoolOperations\Religious\Models\PrayerSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrayerAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('religious.attendance.record', 'web');
        Permission::findOrCreate('religious.attendance.approve', 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_status_terlambat_dihitung_otomatis_dari_jendela_presensi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 06:30:00'));
        $user = $this->actingAsAttendanceRecorder();
        $context = $this->createScheduleContext('FAJR', '06:00:00');

        $response = $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
            'student_id' => 501,
            'mosque_id' => $context['mosque']->id,
            'prayer_schedule_id' => $context['schedule']->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('prayer_attendance_records', [
            'student_id' => 501,
            'prayer_type' => 'FAJR',
            'status' => 'LATE',
            'recorded_by' => $user->id,
        ]);
    }

    public function test_pengguna_tidak_dapat_mengirim_override_presensi_sendiri(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 03:00:00'));
        $this->actingAsAttendanceRecorder();
        $context = $this->createScheduleContext('FAJR', '06:00:00');

        $response = $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
            'student_id' => 502,
            'mosque_id' => $context['mosque']->id,
            'prayer_schedule_id' => $context['schedule']->id,
            'authorized' => true,
            'authorization_reason' => 'Koreksi presensi yang disetujui.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('prayer_attendance_records', 0);
    }

    public function test_override_massal_juga_dibatasi_izin_persetujuan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 03:00:00'));
        $this->actingAsAttendanceRecorder();
        $context = $this->createScheduleContext('FAJR', '06:00:00');

        $this->postJson('/api/v1/religious/prayer-attendance/bulk', [
            'records' => [[
                'prayer_type' => 'FAJR',
                'attendance_date' => '2026-06-08',
                'student_id' => 508,
                'mosque_id' => $context['mosque']->id,
                'prayer_schedule_id' => $context['schedule']->id,
            ]],
            'authorized' => true,
            'authorization_reason' => 'Koreksi massal yang disetujui.',
        ])->assertForbidden();

        $this->assertDatabaseCount('prayer_attendance_records', 0);
    }

    public function test_override_di_luar_jendela_memerlukan_alasan_dan_izin_persetujuan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 03:00:00'));
        $this->actingAsAttendanceRecorder();
        $context = $this->createScheduleContext('FAJR', '06:00:00');

        $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
            'student_id' => 503,
            'mosque_id' => $context['mosque']->id,
            'prayer_schedule_id' => $context['schedule']->id,
            'authorized' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('authorization_reason');

        $this->assertDatabaseCount('prayer_attendance_records', 0);
    }

    public function test_presensi_ditolak_jika_kebijakan_aktif_tetapi_jadwal_yang_cocok_tidak_ada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 06:30:00'));
        $this->actingAsAttendanceRecorder();
        $mosque = $this->createMosque();
        $this->createPolicy(['FAJR']);

        $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
            'student_id' => 504,
            'mosque_id' => $mosque->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('prayer_attendance_records', 0);
    }

    public function test_jadwal_untuk_jenis_shalat_atau_lokasi_lain_tidak_dapat_dipakai(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 06:30:00'));
        $this->actingAsAttendanceRecorder(canApprove: true);
        $context = $this->createScheduleContext('FAJR', '06:00:00');
        $wrongSchedule = PrayerSchedule::query()->create([
            'schedule_date' => '2026-06-08',
            'mosque_id' => $context['mosque']->id,
            'prayer_type' => 'ISHA',
            'adhan_time' => '06:00:00',
            'status' => 'SCHEDULED',
            'source' => 'MANUAL',
        ]);

        $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
            'student_id' => 506,
            'mosque_id' => $context['mosque']->id,
            'prayer_schedule_id' => $wrongSchedule->id,
            'authorized' => true,
            'authorization_reason' => 'Jadwal yang salah tetap tidak dapat di-override.',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('prayer_attendance_records', 0);
    }

    public function test_presensi_setelah_tengah_malam_menggunakan_jendela_hari_sebelumnya(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 00:30:00'));
        $this->actingAsAttendanceRecorder();
        $context = $this->createScheduleContext('ISHA', '19:15:00', ['window_after_minutes' => 600]);

        $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'ISHA',
            'attendance_date' => '2026-06-08',
            'student_id' => 507,
            'mosque_id' => $context['mosque']->id,
            'prayer_schedule_id' => $context['schedule']->id,
        ])->assertCreated();

        $record = PrayerAttendanceRecord::query()->where('student_id', 507)->firstOrFail();
        $this->assertSame('ISHA', $record->prayer_type);
        $this->assertSame('2026-06-08', $record->attendance_date->toDateString());
        $this->assertSame('LATE', $record->status);
    }

    public function test_override_petugas_berwenang_dicatat_dengan_alasan_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 03:00:00'));
        $user = $this->actingAsAttendanceRecorder(canApprove: true);
        $context = $this->createScheduleContext('FAJR', '06:00:00');

        $this->postJson('/api/v1/religious/prayer-attendance', [
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
            'student_id' => 505,
            'mosque_id' => $context['mosque']->id,
            'prayer_schedule_id' => $context['schedule']->id,
            'authorized' => true,
            'authorization_reason' => 'Koreksi presensi yang disetujui.',
        ])->assertCreated();

        $this->assertDatabaseHas('religious_audit_logs', [
            'event' => 'religious.prayer_attendance.recorded',
            'user_id' => $user->id,
            'reason' => 'Koreksi presensi yang disetujui.',
        ]);
    }

    public function test_kebijakan_menghormati_jenis_shalat_dan_masa_berlaku(): void
    {
        $policy = $this->createPolicy(['FAJR'], [
            'effective_from' => '2026-06-01',
            'effective_until' => '2026-06-10',
        ]);
        $service = app(PrayerAttendanceService::class);

        $this->assertNull($service->policyFor([
            'prayer_type' => 'ISHA',
            'attendance_date' => '2026-06-08',
        ]));
        $this->assertNull($service->policyFor([
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-11',
        ]));
        $this->assertSame($policy->id, $service->policyFor([
            'prayer_type' => 'FAJR',
            'attendance_date' => '2026-06-08',
        ])?->id);
    }

    public function test_cakupan_yang_lebih_spesifik_mengalahkan_kebijakan_global(): void
    {
        $global = $this->createPolicy(['FAJR'], ['priority' => 100]);
        $campus = $this->createPolicy([], [
            'scope_type' => 'CAMPUS',
            'campus_id' => 27,
            'priority' => 1,
        ]);

        $selected = app(PrayerAttendanceService::class)->policyFor([
            'prayer_type' => 'FAJR',
            'campus_id' => 27,
        ]);

        $this->assertNotSame($global->id, $selected?->id);
        $this->assertSame($campus->id, $selected?->id);
    }

    private function actingAsAttendanceRecorder(bool $canApprove = false): User
    {
        $permissions = ['religious.attendance.record'];
        if ($canApprove) {
            $permissions[] = 'religious.attendance.approve';
        }

        $role = Role::findOrCreate('RELIGIOUS_ATTENDANCE_TEST', 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function createScheduleContext(string $prayerType, string $adhanTime, array $policyOverrides = []): array
    {
        $mosque = $this->createMosque();
        $this->createPolicy([$prayerType], $policyOverrides);
        $schedule = PrayerSchedule::query()->create([
            'schedule_date' => '2026-06-08',
            'mosque_id' => $mosque->id,
            'prayer_type' => $prayerType,
            'adhan_time' => $adhanTime,
            'status' => 'SCHEDULED',
            'source' => 'MANUAL',
        ]);

        return compact('mosque', 'schedule');
    }

    private function createMosque(): Mosque
    {
        return Mosque::query()->create([
            'code' => 'MOS-'.Str::upper(Str::random(8)),
            'name' => 'Masjid Uji',
            'timezone' => 'Asia/Jakarta',
            'gender_access' => 'MIXED',
            'operational_status' => 'ACTIVE',
            'active' => true,
        ]);
    }

    private function createPolicy(array $prayerTypes, array $overrides = []): PrayerAttendancePolicy
    {
        return PrayerAttendancePolicy::query()->create(array_merge([
            'code' => 'POL-'.Str::upper(Str::random(8)),
            'name' => 'Kebijakan Uji',
            'scope_type' => 'GLOBAL',
            'prayer_types' => $prayerTypes,
            'window_before_minutes' => 15,
            'window_after_minutes' => 60,
            'late_threshold_minutes' => 10,
            'status' => 'ACTIVE',
            'active' => true,
            'priority' => 10,
        ], $overrides));
    }
}
