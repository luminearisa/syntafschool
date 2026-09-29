<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Database\Seeders\SupGradeScaleSeeder;
use App\Modules\Supervisi\Database\Seeders\SupPermissionSeeder;
use App\Modules\Supervisi\Models\SupSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian modul supervisi.
 *
 * Menyediakan peran ERP, permission `supervisi.*`, skala predikat, dan helper
 * pegawai + akun sehingga setiap berkas tes cukup menulis skenario yang diuji.
 */
abstract class SupTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sengaja tidak memakai `RolePermissionSeeder` penuh (ratusan permission
        // lintas modul) agar pengujian supervisi tetap cepat: cukup peran yang
        // dipakai tata kelola supervisi + permission `supervisi.*`.
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(SupPermissionSeeder::class);
        $this->seed(SupGradeScaleSeeder::class);
    }

    /** Peran ERP yang relevan bagi tata kelola supervisi. */
    protected const ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'kepala-unit', 'auditor',
        'guru', 'staf', 'wali-kelas', 'bendahara',
    ];

    /** Akun ERP dengan peran tertentu, langsung terautentikasi. */
    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Data pegawai (tanpa akun). */
    protected function employee(array $attributes = []): Employee
    {
        return Employee::query()->create([
            'full_name' => $attributes['full_name'] ?? 'Pegawai '.fake()->unique()->numerify('###'),
            'position' => $attributes['position'] ?? 'Guru Mata Pelajaran',
            'employee_type' => $attributes['employee_type'] ?? 'guru',
            'employment_status' => 'Honorer',
            'is_teacher' => ($attributes['employee_type'] ?? 'guru') === 'guru',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /** Pegawai yang sekaligus memiliki akun dengan peran tertentu. */
    protected function employeeWithAccount(Employee $employee, string $role = 'guru'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['userable_type' => Employee::class, 'userable_id' => $employee->id])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /** Daftarkan pegawai sebagai supervisor lewat service (aturan produksi). */
    protected function registerSupervisor(array $attributes = []): SupSupervisor
    {
        $employee = $attributes['employee'] ?? $this->employee();

        return app(\App\Modules\Supervisi\Application\Services\SupervisorDirectoryService::class)
            ->register($employee, $attributes + [
                'kind' => 'KEPALA_SEKOLAH',
                'can_finalize' => true,
            ]);
    }
}
