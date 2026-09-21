<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@syntaf.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Guru Contoh',
            'nip' => '199001012020011001',
            'employee_type' => 'guru',
            'employment_status' => 'PNS',
            'birth_place' => 'Yogyakarta',
            'birth_date' => '1990-01-01',
            'join_date' => '2020-01-01',
            'is_active' => true,
        ], $overrides);
    }

    public function test_super_admin_can_list_employees(): void
    {
        $this->actingAsRole('super-admin');
        Employee::create($this->validPayload());

        $this->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_super_admin_can_create_employee(): void
    {
        $this->actingAsRole('super-admin');

        $this->postJson('/api/v1/employees', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Guru Contoh')
            ->assertJsonPath('data.nip', '199001012020011001');

        $this->assertDatabaseHas('employees', ['nip' => '199001012020011001']);
    }

    public function test_guru_is_forbidden_from_employee_management(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->postJson('/api/v1/employees', $this->validPayload())->assertForbidden();
    }

    public function test_staf_can_view_but_not_create_employee(): void
    {
        $this->actingAsRole('staf');

        $this->getJson('/api/v1/employees')->assertOk();
        $this->postJson('/api/v1/employees', $this->validPayload())->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    }

    public function test_duplicate_nip_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        Employee::create($this->validPayload());

        $this->postJson('/api/v1/employees', $this->validPayload(['full_name' => 'Pegawai Lain']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nip');
    }

    public function test_employee_type_guru_forces_is_teacher_true(): void
    {
        $this->actingAsRole('super-admin');

        $this->postJson('/api/v1/employees', $this->validPayload([
            'employee_type' => 'guru',
            'is_teacher' => false,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.is_teacher', true);
    }

    public function test_invalid_employment_status_is_rejected(): void
    {
        $this->actingAsRole('super-admin');

        $this->postJson('/api/v1/employees', $this->validPayload([
            'employment_status' => 'Kontrak',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employment_status');
    }

    public function test_super_admin_can_update_employee(): void
    {
        $this->actingAsRole('super-admin');
        $employee = Employee::create($this->validPayload());

        $this->putJson("/api/v1/employees/{$employee->id}", $this->validPayload([
            'full_name' => 'Nama Diperbarui',
            'position' => 'Guru Matematika',
        ]))
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Nama Diperbarui');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'full_name' => 'Nama Diperbarui',
        ]);
    }

    public function test_super_admin_can_toggle_employee_active(): void
    {
        $this->actingAsRole('super-admin');
        $employee = Employee::create($this->validPayload(['is_active' => true]));

        $this->patchJson("/api/v1/employees/{$employee->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_super_admin_can_delete_employee(): void
    {
        $this->actingAsRole('super-admin');
        $employee = Employee::create($this->validPayload());

        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertOk();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_employee_seeder_populates_data(): void
    {
        $this->seed(EmployeeSeeder::class);

        $this->assertSame(10, Employee::count());
        $this->assertTrue(Employee::where('employee_type', 'guru')->count() > 0);
        $this->assertTrue(Employee::where('employee_type', 'staf')->count() > 0);
    }
}
