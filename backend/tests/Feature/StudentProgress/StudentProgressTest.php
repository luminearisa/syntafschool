<?php

namespace Tests\Feature\StudentProgress;

use App\Models\AcademicYear;
use App\Models\AchievementType;
use App\Models\Employee;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Models\ViolationType;
use App\Modules\StudentProgress\Database\Seeders\StudentProgressPermissionSeeder;
use App\Modules\StudentProgress\Models\AcademicAlert;
use App\Modules\StudentProgress\Models\StudentAchievement;
use App\Modules\StudentProgress\Models\StudentViolation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modul StudentProgress — Prestasi & Pelanggaran siswa.
 *
 * Menguji RBAC, CRUD, pewarisan poin dari master jenis pelanggaran, ringkasan
 * total poin, ambang peringatan (academic_alerts), transisi status, dan
 * soft-delete.
 */
class StudentProgressTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private AchievementType $achievementType;

    private ViolationType $violationType;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StudentProgressPermissionSeeder::class);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->semester = Semester::create([
            'academic_year_id' => $year->id,
            'name' => 'Ganjil',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-20',
            'is_active' => true,
        ]);

        $this->student = Student::create(['nis' => '26001', 'full_name' => 'Aisyah']);
        $this->achievementType = AchievementType::create([
            'name' => 'Olimpiade Sains (OSN)',
            'level' => 'Nasional',
        ]);
        $this->violationType = ViolationType::create([
            'name' => 'Terlambat masuk kelas',
            'category' => 'Ringan',
            'point' => 5,
            'is_active' => true,
        ]);
    }

    private function actingAsRole(string $role = 'admin-sekolah'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function achievementPayload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student->id,
            'achievement_type_id' => $this->achievementType->id,
            'title' => 'Juara 1 OSN Fisika',
            'date' => '2026-09-01',
            'level' => 'Nasional',
            'rank' => 'Juara 1',
            'organizer' => 'Kemendikbud',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function violationPayload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student->id,
            'violation_type_id' => $this->violationType->id,
            'date' => '2026-09-01',
            'description' => 'Datang terlambat 10 menit',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Prestasi — CRUD & RBAC
    // -----------------------------------------------------------------

    public function test_admin_sekolah_can_create_and_list_achievements(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/student-achievements', $this->achievementPayload())
            ->assertCreated()
            ->json('data');

        $this->assertSame('Juara 1 OSN Fisika', $created['title']);
        $this->assertSame('Nasional', $created['level']);
        $this->assertSame($this->student->id, $created['student_id']);

        $this->getJson('/api/v1/student-achievements')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_guru_can_view_achievements_but_cannot_create(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/student-achievements')->assertOk();

        $this->postJson('/api/v1/student-achievements', $this->achievementPayload())
            ->assertForbidden();
    }

    public function test_wali_kelas_can_create_achievement(): void
    {
        $this->actingAsRole('wali-kelas');

        $this->postJson('/api/v1/student-achievements', $this->achievementPayload())
            ->assertCreated();
    }

    public function test_achievement_can_be_updated(): void
    {
        $this->actingAsRole('admin-sekolah');

        $achievement = StudentAchievement::create([
            'student_id' => $this->student->id,
            'achievement_type_id' => $this->achievementType->id,
            'title' => 'Juara 2',
            'level' => 'Kabupaten',
        ]);

        $updated = $this->patchJson("/api/v1/student-achievements/{$achievement->id}", [
            'title' => 'Juara 1 Kabupaten',
            'level' => 'Provinsi',
        ])->assertOk()->json('data');

        $this->assertSame('Juara 1 Kabupaten', $updated['title']);
        $this->assertSame('Provinsi', $updated['level']);
    }

    public function test_achievement_can_be_soft_deleted(): void
    {
        $this->actingAsRole('admin-sekolah');

        $achievement = StudentAchievement::create([
            'student_id' => $this->student->id,
            'achievement_type_id' => $this->achievementType->id,
            'title' => 'Salah input',
        ]);

        $this->deleteJson("/api/v1/student-achievements/{$achievement->id}")->assertOk();

        $this->assertSoftDeleted('student_achievements', ['id' => $achievement->id]);
        $this->getJson("/api/v1/student-achievements/{$achievement->id}")->assertNotFound();
    }

    public function test_achievement_rejects_unknown_level(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-achievements', $this->achievementPayload(['level' => 'Dunia']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    public function test_achievement_lookups_returns_references(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->getJson('/api/v1/student-achievements/lookups')
            ->assertOk()
            ->assertJsonPath('data.achievement_types.0.name', 'Olimpiade Sains (OSN)')
            ->assertJsonStructure(['data' => ['students', 'achievement_types', 'levels']]);
    }

    public function test_guest_cannot_access_achievements(): void
    {
        $this->getJson('/api/v1/student-achievements')->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Pelanggaran — pencatatan & poin
    // -----------------------------------------------------------------

    public function test_point_defaults_from_violation_type(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()
            ->json('data.violation');

        $this->assertSame(5, $created['point']);
        $this->assertSame(StudentViolation::STATUS_RECORDED, $created['status']);
    }

    public function test_explicit_point_overrides_type_point(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 25]))
            ->assertCreated()
            ->json('data.violation');

        $this->assertSame(25, $created['point']);
    }

    public function test_guru_can_create_violation_but_cannot_verify(): void
    {
        $this->actingAsRole('guru');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()
            ->json('data.violation');

        $this->postJson("/api/v1/student-violations/{$created['id']}/verify")->assertForbidden();
    }

    public function test_wali_kelas_can_verify_violation(): void
    {
        $this->actingAsRole('wali-kelas');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()
            ->json('data.violation');

        $verified = $this->postJson("/api/v1/student-violations/{$created['id']}/verify")
            ->assertOk()
            ->json('data');

        $this->assertSame(StudentViolation::STATUS_VERIFIED, $verified['status']);
    }

    public function test_violation_can_be_soft_deleted(): void
    {
        $this->actingAsRole('admin-sekolah');

        $violation = StudentViolation::create([
            'student_id' => $this->student->id,
            'violation_type_id' => $this->violationType->id,
            'date' => '2026-09-01',
            'point' => 5,
            'status' => StudentViolation::STATUS_RECORDED,
        ]);

        $this->deleteJson("/api/v1/student-violations/{$violation->id}")->assertOk();

        $this->assertSoftDeleted('student_violations', ['id' => $violation->id]);
    }

    public function test_violation_update_repoints_when_type_changed(): void
    {
        $this->actingAsRole('admin-sekolah');

        $berat = ViolationType::create([
            'name' => 'Membawa barang terlarang',
            'category' => 'Berat',
            'point' => 50,
            'is_active' => true,
        ]);

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()
            ->json('data.violation');

        $updated = $this->patchJson("/api/v1/student-violations/{$created['id']}", [
            'violation_type_id' => $berat->id,
        ])->assertOk()->json('data');

        $this->assertSame(50, $updated['point']);
        $this->assertSame($berat->id, $updated['violation_type_id']);
    }

    public function test_violation_lookups_returns_types(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->getJson('/api/v1/student-violations/lookups')
            ->assertOk()
            ->assertJsonStructure(['data' => ['students', 'violation_types']])
            ->assertJsonPath('data.violation_types.0.name', 'Terlambat masuk kelas');
    }

    // -----------------------------------------------------------------
    // Ringkasan poin
    // -----------------------------------------------------------------

    public function test_summary_total_points_is_correct(): void
    {
        $this->actingAsRole('admin-sekolah');

        StudentViolation::create([
            'student_id' => $this->student->id,
            'violation_type_id' => $this->violationType->id,
            'date' => '2026-09-01',
            'point' => 5,
        ]);
        StudentViolation::create([
            'student_id' => $this->student->id,
            'violation_type_id' => $this->violationType->id,
            'date' => '2026-09-10',
            'point' => 10,
        ]);

        $summary = $this->getJson('/api/v1/student-violations/summary?student_id='.$this->student->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(15, $summary['total_points']);
        $this->assertSame(100, $summary['threshold']);
        $this->assertSame('info', $summary['level']);
        $this->assertFalse($summary['exceeded']);
        $this->assertSame(15, $summary['breakdown'][0]['points']);
    }

    public function test_summary_can_be_scoped_by_semester(): void
    {
        $this->actingAsRole('admin-sekolah');

        // Di luar semester (tahun sebelumnya) dan di dalam semester aktif.
        StudentViolation::create([
            'student_id' => $this->student->id,
            'violation_type_id' => $this->violationType->id,
            'date' => '2025-01-15',
            'point' => 70,
        ]);
        StudentViolation::create([
            'student_id' => $this->student->id,
            'violation_type_id' => $this->violationType->id,
            'date' => '2026-09-01',
            'point' => 30,
        ]);

        $all = $this->getJson('/api/v1/student-violations/summary?student_id='.$this->student->id)
            ->assertOk()->json('data');
        $this->assertSame(100, $all['total_points']);

        $scoped = $this->getJson('/api/v1/student-violations/summary?student_id='.$this->student->id.'&semester_id='.$this->semester->id)
            ->assertOk()->json('data');
        $this->assertSame(30, $scoped['total_points']);
    }

    public function test_kepala_sekolah_can_view_point_summary_but_not_create_achievement(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $this->getJson('/api/v1/student-violations/summary?student_id='.$this->student->id)->assertOk();
        $this->postJson('/api/v1/student-achievements', $this->achievementPayload())->assertForbidden();
    }

    public function test_guru_cannot_view_point_summary(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/student-violations/summary?student_id='.$this->student->id)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Ambang poin & alert
    // -----------------------------------------------------------------

    public function test_threshold_crossing_creates_warning_alert(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 100]))
            ->assertCreated()
            ->assertJsonPath('data.alert.level', AcademicAlert::LEVEL_WARNING);

        $this->assertDatabaseHas('academic_alerts', [
            'student_id' => $this->student->id,
            'semester_id' => $this->semester->id,
            'type' => AcademicAlert::TYPE_VIOLATION,
            'level' => AcademicAlert::LEVEL_WARNING,
        ]);
    }

    public function test_critical_threshold_creates_critical_alert(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 200]))
            ->assertCreated()
            ->assertJsonPath('data.alert.level', AcademicAlert::LEVEL_CRITICAL);

        $this->assertDatabaseHas('academic_alerts', [
            'student_id' => $this->student->id,
            'level' => AcademicAlert::LEVEL_CRITICAL,
        ]);
    }

    public function test_threshold_alert_is_idempotent_per_level(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 100]))
            ->assertCreated()
            ->assertJsonPath('data.alert.level', AcademicAlert::LEVEL_WARNING);

        // Pelanggaran tanpa poin tidak menaikkan level → tidak ada alert baru.
        $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 0]))
            ->assertCreated()
            ->assertJsonPath('data.alert', null);

        $this->assertDatabaseCount('academic_alerts', 1);
    }

    public function test_threshold_below_limit_creates_no_alert(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 50]))
            ->assertCreated()
            ->assertJsonPath('data.alert', null);

        $this->assertDatabaseCount('academic_alerts', 0);
    }

    public function test_custom_threshold_can_be_supplied(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-violations', $this->violationPayload(['point' => 20, 'threshold' => 10]))
            ->assertCreated()
            ->assertJsonPath('data.alert.level', AcademicAlert::LEVEL_WARNING);
    }

    // -----------------------------------------------------------------
    // Transisi status
    // -----------------------------------------------------------------

    public function test_status_transitions_recorded_verified_resolved(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()
            ->json('data.violation');
        $this->assertSame(StudentViolation::STATUS_RECORDED, $created['status']);

        $verified = $this->postJson("/api/v1/student-violations/{$created['id']}/verify")
            ->assertOk()->json('data');
        $this->assertSame(StudentViolation::STATUS_VERIFIED, $verified['status']);

        $resolved = $this->postJson("/api/v1/student-violations/{$created['id']}/resolve")
            ->assertOk()->json('data');
        $this->assertSame(StudentViolation::STATUS_RESOLVED, $resolved['status']);
    }

    public function test_resolve_requires_verified_status(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()->json('data.violation');

        $this->postJson("/api/v1/student-violations/{$created['id']}/resolve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_verify_cannot_be_repeated(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()->json('data.violation');

        $this->postJson("/api/v1/student-violations/{$created['id']}/verify")->assertOk();
        $this->postJson("/api/v1/student-violations/{$created['id']}/verify")
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------
    // Pelapor
    // -----------------------------------------------------------------

    public function test_reported_by_defaults_to_linked_employee(): void
    {
        $employee = Employee::create(['full_name' => 'Pak Budi']);

        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill([
            'userable_type' => Employee::class,
            'userable_id' => $employee->id,
        ])->save();
        $user->syncRoles(['admin-sekolah']);
        Sanctum::actingAs($user, ['*']);

        $created = $this->postJson('/api/v1/student-violations', $this->violationPayload())
            ->assertCreated()->json('data.violation');

        $this->assertSame($employee->id, $created['reported_by']);
        $this->assertSame('Pak Budi', $created['reporter_name']);
    }
}
