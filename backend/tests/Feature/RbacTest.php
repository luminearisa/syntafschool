<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@syntaf.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    public function test_super_admin_can_access_all_rbac_endpoints(): void
    {
        $admin = $this->makeUser('super-admin');
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/users')->assertOk();
        $this->getJson('/api/v1/roles')->assertOk();
        $this->getJson('/api/v1/permissions')->assertOk();
    }

    public function test_guru_is_forbidden_from_user_management(): void
    {
        $guru = $this->makeUser('guru');
        Sanctum::actingAs($guru, ['*']);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_guru_without_master_data_permission_is_forbidden(): void
    {
        $guru = $this->makeUser('guru');
        Sanctum::actingAs($guru, ['*']);

        $this->getJson('/api/v1/master/subjects')->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }

    public function test_super_admin_can_create_user_with_role_via_api(): void
    {
        $admin = $this->makeUser('super-admin');
        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'name' => 'Guru Baru',
            'username' => 'gurubaru',
            'email' => 'guru.baru@syntaf.test',
            'password' => 'password',
            'is_active' => true,
            'roles' => ['guru'],
        ];

        $this->postJson('/api/v1/users', $payload)
            ->assertCreated()
            ->assertJsonPath('data.email', 'guru.baru@syntaf.test');

        $created = User::where('email', 'guru.baru@syntaf.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('guru'));
    }

    public function test_super_admin_can_create_role_with_permissions(): void
    {
        $admin = $this->makeUser('super-admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/roles', [
            'name' => 'operator-perpus',
            'permissions' => ['library.view', 'library.create'],
        ])->assertCreated();

        $role = Role::where('name', 'operator-perpus')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('library.view'));
    }
}
