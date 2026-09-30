<?php

namespace Tests\Feature\Uks;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Database\Seeders\UksPermissionSeeder;
use App\Modules\UKS\Models\UksOfficer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian modul UKS: peran, izin, dan helper pembuat data
 * (siswa, kelas, guru, wali, petugas) yang saling tertaut.
 */
abstract class UksTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected const ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'petugas-kesehatan',
        'guru', 'wali-kelas', 'staf', 'siswa', 'wali-murid',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(UksPermissionSeeder::class);
    }

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Akun yang terhubung ke sebuah entitas (siswa/pegawai/wali) lewat userable. */
    protected function accountFor(string $role, object $entity, array $attributes = []): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'userable_type' => $entity->getMorphClass(),
            'userable_id' => $entity->id,
            ...$attributes,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeStudent(string $name, ?SchoolClass $class = null): Student
    {
        $student = Student::query()->create([
            'nis' => 'NIS'.random_int(100000, 999999),
            'full_name' => $name,
        ]);

        if ($class !== null) {
            ClassStudent::query()->create([
                'school_class_id' => $class->id,
                'student_id' => $student->id,
                'academic_year_id' => $class->academic_year_id,
                'status' => 'aktif',
            ]);
        }

        return $student;
    }

    protected function makeClass(?Employee $homeroom = null): SchoolClass
    {
        $year = AcademicYear::query()->firstOrCreate(
            ['name' => '2026/2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );

        return SchoolClass::query()->create([
            'academic_year_id' => $year->id,
            'name' => 'VII '.random_int(1, 9),
            'code' => 'K'.random_int(1000, 9999),
            'homeroom_teacher_id' => $homeroom?->id,
            'is_active' => true,
        ]);
    }

    protected function makeEmployee(string $name, string $type = 'guru'): Employee
    {
        return Employee::query()->create([
            'full_name' => $name,
            'employee_type' => $type,
            'is_teacher' => $type === 'guru',
            'is_active' => true,
        ]);
    }

    protected function makeOfficer(string $role = 'PETUGAS'): array
    {
        $employee = $this->makeEmployee('Petugas UKS '.random_int(1, 999), 'staf');
        $officer = UksOfficer::query()->create([
            'employee_id' => $employee->id,
            'role' => $role,
            'is_active' => true,
            'appointed_at' => now()->toDateString(),
        ]);
        $user = $this->accountFor('petugas-kesehatan', $employee);

        return ['employee' => $employee, 'officer' => $officer, 'user' => $user];
    }

    protected function makeGuardian(Student $student, bool $primary = true): array
    {
        $guardian = Guardian::query()->create([
            'full_name' => 'Wali '.$student->full_name,
            'phone' => '0812'.random_int(10000000, 99999999),
        ]);

        $student->guardians()->attach($guardian->id, [
            'is_primary' => $primary,
        ]);

        $user = $this->accountFor('wali-murid', $guardian);

        return ['guardian' => $guardian, 'user' => $user];
    }
}
