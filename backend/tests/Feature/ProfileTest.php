<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Profil 360°: izin dasar, izin per bagian, dan pagar keluarga.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_super_admin_sees_every_section_and_can_load_all_of_them(): void
    {
        $student = Student::create(['nis' => 'P1', 'full_name' => 'Siswa Profil']);
        $this->actingAsRole('super-admin');

        $res = $this->getJson("/api/v1/profiles/students/{$student->id}")->assertOk();
        $keys = collect($res->json('data.sections'))->pluck('key');
        $this->assertTrue($keys->contains('keuangan') && $keys->contains('kesehatan') && $keys->contains('konseling'));

        foreach ($keys as $key) {
            $this->getJson("/api/v1/profiles/students/{$student->id}/sections/{$key}")->assertOk();
        }
    }

    public function test_teacher_gets_academic_but_not_finance_health_or_counseling(): void
    {
        $student = Student::create(['nis' => 'P2', 'full_name' => 'Siswa Guru']);
        $this->actingAsRole('guru');

        $keys = collect($this->getJson("/api/v1/profiles/students/{$student->id}")->assertOk()->json('data.sections'))->pluck('key');
        $this->assertTrue($keys->contains('akademik'));
        $this->assertFalse($keys->contains('keuangan'));
        $this->assertFalse($keys->contains('konseling'));
        $this->assertFalse($keys->contains('dompet'));

        // Meski tab disembunyikan, endpoint bagian tetap menolak.
        $this->getJson("/api/v1/profiles/students/{$student->id}/sections/keuangan")->assertStatus(403);
        $this->getJson("/api/v1/profiles/students/{$student->id}/sections/konseling")->assertStatus(403);
        $this->getJson("/api/v1/profiles/students/{$student->id}/sections/tidak-ada")->assertStatus(404);
    }

    public function test_family_account_only_opens_own_child_and_never_guardian_or_employee_profiles(): void
    {
        $guardian = Guardian::create(['full_name' => 'Wali Profil']);
        $mine = Student::create(['nis' => 'P3', 'full_name' => 'Anak Saya']);
        $other = Student::create(['nis' => 'P4', 'full_name' => 'Anak Orang']);
        DB::table('student_guardians')->insert(['student_id' => $mine->id, 'guardian_id' => $guardian->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $user = User::factory()->create(['is_active' => true, 'userable_type' => $guardian->getMorphClass(), 'userable_id' => $guardian->id]);
        $user->syncRoles(['wali-murid']);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/profiles/students/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/profiles/students/{$other->id}")->assertStatus(404);
        $this->getJson("/api/v1/profiles/guardians/{$guardian->id}")->assertStatus(403);

        $employee = Employee::create(['full_name' => 'Pegawai', 'is_active' => true]);
        $this->getJson("/api/v1/profiles/employees/{$employee->id}")->assertStatus(403);
    }

    public function test_payroll_section_requires_hr_permissions(): void
    {
        $employee = Employee::create(['full_name' => 'Pak Gaji', 'is_active' => true]);
        $this->actingAsRole('guru');

        $keys = collect($this->getJson("/api/v1/profiles/employees/{$employee->id}")->assertOk()->json('data.sections'))->pluck('key');
        $this->assertFalse($keys->contains('gaji'));
        $this->getJson("/api/v1/profiles/employees/{$employee->id}/sections/gaji")->assertStatus(403);
    }
}
