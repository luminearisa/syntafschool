<?php

namespace Tests\Feature;

use App\Models\BoardingRoom;
use App\Models\Gender;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use App\Services\Boarding\BoardingPlacementService;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lapisan manajemen (§42–§44) + portal orang tua (§44–§45): dashboard,
 * monitoring status, laporan (JSON dan CSV satu sumber), jejak audit, serta
 * gerbang kepemilikan anak di portal wali murid.
 */
class BoardingDashboardReportPortalTest extends TestCase
{
    use RefreshDatabase;

    /** Varian helper: santri + kasur eksplisit supaya `beds.occupied` bergerak. */
    private function placedStudentWithBed(string $nis): \App\Models\Student
    {
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        $student = \App\Models\Student::create([
            'nis' => $nis,
            'full_name' => 'Santri '.$nis,
            'gender_id' => $g->id,
            'is_active' => true,
        ]);

        $roomId = (int) BoardingRoom::query()->where('code', 'A101')->value('id');
        $bedId = (int) \App\Models\BoardingBed::query()->where('room_id', $roomId)->orderBy('id')->value('id');

        app(BoardingPlacementService::class)->assign([
            'student_id' => $student->id,
            'room_id' => $roomId,
            'bed_id' => $bedId,
        ], User::factory()->create(['is_active' => true]));

        return $student;
    }

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

    private function placedStudent(string $nis, string $roomCode = 'A101'): Student
    {
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        $student = Student::create([
            'nis' => $nis,
            'full_name' => 'Santri '.$nis,
            'gender_id' => $g->id,
            'is_active' => true,
        ]);

        app(BoardingPlacementService::class)->assign([
            'student_id' => $student->id,
            'room_id' => BoardingRoom::query()->where('code', $roomCode)->value('id'),
        ], User::factory()->create(['is_active' => true]));

        return $student;
    }

    /** Rantai orang tua: guardian existing + akun PPDB + relasi student_guardians. */
    private function makeParentOf(User $parent, Student $student): void
    {
        $guardian = Guardian::create(['full_name' => 'Bapak '.$student->full_name, 'address' => 'Jl. Test 1']);

        DB::table('student_guardians')->insert([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PpdbParentAccount::create(['user_id' => $parent->id, 'guardian_id' => $guardian->id]);
    }

    // ================================================ dashboard

    public function test_dashboard_merangkum_semua_area(): void
    {
        $student = $this->placedStudentWithBed('7001');

        $this->actingAsRole('petugas-kedisiplinan');
        $this->postJson('/api/v1/boarding/violations', [
            'student_id' => $student->id,
            'violation_type_id' => DB::table('boarding_violation_types')->where('code', 'TRLMBT')->value('id'),
            'date' => now()->toDateString(),
        ])->assertCreated();

        $this->actingAsRole('kepala-asrama');
        $stats = $this->getJson('/api/v1/boarding/dashboard')
            ->assertOk()
            ->assertJsonPath('data.students.in_boarding', 1)
            ->assertJsonPath('data.students.status_counts.in_dorm', 1)
            ->assertJsonPath('data.discipline.violations_month', 1)
            ->json('data');

        $this->assertSame('Poin Pelanggaran', $stats['point_label']);
        $this->assertArrayHasKey('late_returns_month', $stats['permissions']);
        $this->assertArrayHasKey('in_isolation', $stats['health']);
        $this->assertArrayHasKey('this_month', $stats['achievements']);
        // Dua asrama contoh × 4 kamar × 8 ranjang = 64; satu terisi santri di atas.
        $this->assertSame(64, $stats['beds']['total']);
        $this->assertSame(1, $stats['beds']['occupied']);
    }

    public function test_dashboard_tanpa_hak_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi');
        $this->getJson('/api/v1/boarding/dashboard')->assertForbidden();
        $this->getJson('/api/v1/boarding/monitoring')->assertForbidden();
    }

    // ================================================ monitoring (§42)

    public function test_monitoring_status_semua_santri_dan_filter(): void
    {
        $a = $this->placedStudent('7002', 'A101');
        $b = $this->placedStudent('7003', 'A102');

        $this->actingAsRole('kepala-asrama');

        $overview = $this->getJson('/api/v1/boarding/monitoring')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.counts.in_dorm', 2)
            ->json('data');

        $this->assertSame('Di Dalam Asrama', $overview['rows'][0]['status_label']);
        $this->assertContains($a->nis, array_column($overview['rows'], 'nis'));
        $this->assertContains($b->nis, array_column($overview['rows'], 'nis'));

        $this->getJson('/api/v1/boarding/monitoring?status=sick')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->getJson('/api/v1/boarding/monitoring?search=7003')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.rows.0.room_code', 'A102');

        $this->getJson('/api/v1/boarding/monitoring/students/'.$a->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_dorm')
            ->assertJsonPath('data.room_code', 'A101');

        $bulk = $this->getJson('/api/v1/boarding/monitoring/bulk?student_ids='.$a->id.','.$b->id)
            ->assertOk()
            ->json('data');
        $this->assertSame('in_dorm', $bulk['statuses'][$a->id]);
        $this->assertCount(2, $bulk['summaries']);
    }

    public function test_monitoring_wali_kamar_hanya_kamarnya(): void
    {
        $binaan = $this->placedStudent('7004', 'A101');
        $this->placedStudent('7005', 'A102');

        $user = User::factory()->create(['email' => 'wali.kamar3@schoolerp.test', 'is_active' => true]);
        $user->syncRoles(['wali-kamar']);

        $employee = \App\Models\Employee::create(['full_name' => 'Pengasuh '.$user->id, 'is_active' => true]);
        $user->forceFill(['userable_type' => \App\Models\Employee::class, 'userable_id' => $employee->id])->save();
        \App\Models\BoardingMusyrif::create([
            'employee_id' => $employee->id,
            'position' => \App\Models\BoardingMusyrif::POSITION_WALI_KAMAR,
            'room_id' => BoardingRoom::query()->where('code', 'A101')->value('id'),
            'start_date' => now()->subDay()->toDateString(),
            'status' => \App\Models\BoardingMusyrif::STATUS_ACTIVE,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/boarding/monitoring')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.rows.0.student_id', $binaan->id);

        // Santri di luar binaan → kartunya ditolak (403), id asing → 404.
        $luar = $this->placedStudent('7006', 'A102');
        $this->getJson('/api/v1/boarding/monitoring/students/'.$luar->id)->assertForbidden();
        $this->getJson('/api/v1/boarding/monitoring/students/999999')->assertNotFound();
    }

    // ================================================ laporan (§43–44)

    public function test_jenis_laporan_dan_baris_violations(): void
    {
        $this->actingAsRole('kepala-asrama');

        $this->getJson('/api/v1/boarding/reports')
            ->assertOk()
            ->assertJsonFragment(['kind' => 'violations', 'label' => 'Laporan Pelanggaran'])
            ->assertJsonFragment(['kind' => 'points']);

        $this->getJson('/api/v1/boarding/reports/bukan-jenis')->assertNotFound();
    }

    public function test_laporan_violations_dan_points_isi_dan_ekspor_csv(): void
    {
        $student = $this->placedStudent('7006');

        $this->actingAsRole('petugas-kedisiplinan');
        $this->postJson('/api/v1/boarding/violations', [
            'student_id' => $student->id,
            'violation_type_id' => DB::table('boarding_violation_types')->where('code', 'KELUAR-TANPA-IZIN')->value('id'),
            'date' => now()->toDateString(),
            'narrative' => 'Keluar tanpa izin malam hari.',
        ])->assertCreated();

        $this->actingAsRole('kepala-asrama');

        $report = $this->getJson('/api/v1/boarding/reports/violations')->assertOk()->json('data');
        $this->assertSame(1, $report['total']);
        $this->assertSame('Santri 7006', $report['rows'][0]['student']);
        $this->assertSame(20, $report['rows'][0]['points']);
        $this->assertSame('date', array_key_first($report['headers']));
        $this->assertSame('Tanggal', $report['headers']['date']);

        $points = $this->getJson('/api/v1/boarding/reports/points')->assertOk()->json('data');
        $this->assertSame(20, $points['rows'][0]['total_points']);

        $export = $this->getJson('/api/v1/boarding/reports/violations/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $export->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'BOM Excel wajib ada.');
        $this->assertStringContainsString('Tanggal;Santri;NIS', $content);
        $this->assertStringContainsString('"Santri 7006";7006', $content);
        $this->assertStringContainsString(
            'filename="boarding-violations-',
            $export->headers->get('content-disposition'),
        );
    }

    public function test_laporan_tidak_terbuka_bagitugas_tanpa_hak_report(): void
    {
        $binaan = $this->placedStudent('7007', 'A101');

        $this->actingAsRole('petugas-kedisiplinan');
        $this->postJson('/api/v1/boarding/violations', [
            'student_id' => $binaan->id,
            'violation_type_id' => (int) DB::table('boarding_violation_types')->where('code', 'TRLMBT')->value('id'),
            'date' => now()->toDateString(),
        ])->assertCreated();

        // petugas-kedisiplinan & wali-kamar tidak memegang report.view.
        $this->getJson('/api/v1/boarding/reports/violations')->assertForbidden();
        $this->getJson('/api/v1/boarding/reports/violations/export')->assertForbidden();
    }

    // ================================================ jejak audit

    public function test_jejak_audit_hanya_untuk_pembegang_audit_view(): void
    {
        $student = $this->placedStudent('7009');

        $this->actingAsRole('petugas-kedisiplinan');
        $violationId = $this->postJson('/api/v1/boarding/violations', [
            'student_id' => $student->id,
            'violation_type_id' => DB::table('boarding_violation_types')->where('code', 'TRLMBT')->value('id'),
            'date' => now()->toDateString(),
        ])->assertCreated()->json('data.violation.id');

        // kepala-asrama TIDAK punya boarding.audit.view.
        $this->actingAsRole('kepala-asrama');
        $this->getJson('/api/v1/boarding/audit-logs')->assertForbidden();

        $this->actingAsRole('admin-boarding');
        $log = $this->getJson('/api/v1/boarding/audit-logs?subject_type=BoardingViolation')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->json('data');

        $this->assertSame('BOARDING_VIOLATION_CREATED', $log['data'][0]['event']);
        $this->assertSame((int) $violationId, $log['data'][0]['subject_id']);
        $this->assertNotEmpty($log['data'][0]['causer']);

        // Filter event persis juga bekerja.
        $this->getJson('/api/v1/boarding/audit-logs?event=BOARDING_VIOLATION_CREATED')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    // ================================================ portal orang tua

    public function test_portal_hanya_menampilkan_anak_milik_sendiri(): void
    {
        $anak = $this->placedStudent('7010');
        $orangLain = $this->placedStudent('7011');

        $this->actingAsRole('petugas-kedisiplinan');
        $violationId = $this->postJson('/api/v1/boarding/violations', [
            'student_id' => $anak->id,
            'violation_type_id' => DB::table('boarding_violation_types')->where('code', 'TRLMBT')->value('id'),
            'date' => now()->toDateString(),
        ])->assertCreated()->json('data.violation.id');

        $parent = User::factory()->create(['email' => 'ortu7010@schoolerp.test', 'is_active' => true]);
        $parent->syncRoles(['wali-murid']);
        $this->makeParentOf($parent, $anak);

        Sanctum::actingAs($parent, ['*']);

        // Orang tua tidak punya permission boarding sama sekali.
        $this->getJson('/api/v1/boarding/violations')->assertForbidden();
        $this->getJson('/api/v1/boarding/dashboard')->assertForbidden();

        $this->getJson('/api/v1/boarding/portal/children')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $anak->id)
            ->assertJsonPath('data.0.room_code', 'A101')
            ->assertJsonPath('data.0.status', 'in_dorm');

        $this->getJson('/api/v1/boarding/portal/children/'.$anak->id.'/violations?all=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $violationId);

        $summary = $this->getJson('/api/v1/boarding/portal/children/'.$anak->id.'/summary')
            ->assertOk()
            ->assertJsonPath('data.points.total_points', 5)
            ->assertJsonPath('data.points.violation_count', 1)
            ->assertJsonPath('data.recent_violations.0.id', $violationId)
            ->assertJsonPath('data.unread_notifications', 0)
            ->json('data');
        $this->assertSame('A101', $summary['placement']['room_code']);

        // Endpoint area lain tetap terbuka untuk anak sendiri.
        foreach (['points', 'achievements', 'health', 'attendances', 'permissions', 'homecomings', 'visitors'] as $area) {
            $this->getJson('/api/v1/boarding/portal/children/'.$anak->id.'/'.$area)->assertOk();
        }

        // Anak orang lain → 404, bukan 403: tidak membocorkan keberadaan data.
        $this->getJson('/api/v1/boarding/portal/children/'.$orangLain->id.'/summary')->assertNotFound();
        $this->getJson('/api/v1/boarding/portal/children/'.$orangLain->id.'/violations?all=1')->assertNotFound();

        // Query student_id palsu di-override — hasil tetap milik anak sendiri.
        $this->getJson('/api/v1/boarding/portal/children/'.$anak->id.'/violations?all=1&student_id='.$orangLain->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $violationId);
    }

    public function test_portal_menampilkan_notifikasi_yang_belum_dibaca(): void
    {
        $anak = $this->placedStudent('7012');
        $parent = User::factory()->create(['email' => 'ortu7012@schoolerp.test', 'is_active' => true]);
        $parent->syncRoles(['wali-murid']);
        $this->makeParentOf($parent, $anak);

        $this->actingAsRole('petugas-kedisiplinan');
        $this->postJson('/api/v1/boarding/violations', [
            'student_id' => $anak->id,
            'violation_type_id' => DB::table('boarding_violation_types')->where('code', 'KELUAR-TANPA-IZIN')->value('id'),
            'date' => now()->toDateString(),
        ])->assertCreated();

        Sanctum::actingAs($parent, ['*']);
        $this->getJson('/api/v1/boarding/portal/children/'.$anak->id.'/summary')
            ->assertOk()
            ->assertJsonPath('data.unread_notifications', 1);
    }

    public function test_portal_counter_izin_dan_pulang_hanya_yang_berjalan(): void
    {
        $anak = $this->placedStudent('7013');
        $parent = User::factory()->create(['email' => 'ortu7013@schoolerp.test', 'is_active' => true]);
        $parent->syncRoles(['wali-murid']);
        $this->makeParentOf($parent, $anak);

        $payload = fn (string $start, string $expected): array => [
            'student_id' => $anak->id,
            'permission_type' => 'out',
            'destination' => 'Rumah nenek',
            'reason' => 'Acara keluarga',
            'start_at' => $start,
            'expected_return_at' => $expected,
        ];

        $this->actingAsRole('petugas-perizinan');

        // Izin A: diajukan → disetujui → keluar → kembali. Status `returned`
        // sudah TUTUP, tidak boleh dihitung sebagai "berjalan".
        $a = $this->postJson('/api/v1/boarding/permissions', $payload(
            now()->subHours(6)->format('Y-m-d H:i'),
            now()->subHours(2)->format('Y-m-d H:i'),
        ))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$a.'/submit')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$a.'/approve')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$a.'/check-out', ['at' => now()->subHours(3)->format('Y-m-d H:i')])->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$a.'/check-in', ['at' => now()->subHours(2)->format('Y-m-d H:i')])->assertOk();

        // Izin B: baru diajukan → `submitted` masih TERBUKA, ikut dihitung.
        $b = $this->postJson('/api/v1/boarding/permissions', $payload(
            now()->addHour()->format('Y-m-d H:i'),
            now()->addHours(5)->format('Y-m-d H:i'),
        ))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$b.'/submit')->assertOk();

        Sanctum::actingAs($parent, ['*']);
        $this->getJson('/api/v1/boarding/portal/children/'.$anak->id.'/summary')
            ->assertOk()
            ->assertJsonPath('data.counts.permissions', 1)
            ->assertJsonPath('data.counts.homecomings', 0);
    }
}
