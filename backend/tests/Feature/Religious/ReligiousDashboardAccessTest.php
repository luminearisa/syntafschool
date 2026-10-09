<?php

namespace Tests\Feature\Religious;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReligiousDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_portal_dan_meta_tidak_terbuka_ke_semua_pengguna_terautentikasi(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/religious/meta')->assertForbidden();
        $this->getJson('/api/v1/religious/integrations')->assertForbidden();
        $this->getJson('/api/v1/religious/mosques/1/display')->assertForbidden();
        $this->getJson('/api/v1/religious/mobile')->assertForbidden();
        $this->getJson('/api/v1/religious/parent/dashboard')->assertForbidden();
    }

    public function test_siswa_dapat_membuka_dashboard_sendiri_dari_tautan_userable_saja(): void
    {
        $student = Student::query()->create([
            'nis' => 'REL-DASH-001',
            'full_name' => 'Siswa Portal',
            'is_active' => true,
        ]);
        $otherStudent = Student::query()->create([
            'nis' => 'REL-DASH-002',
            'full_name' => 'Siswa Lain',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'is_active' => true,
            'userable_type' => $student->getMorphClass(),
            'userable_id' => $student->id,
        ]);
        $user->givePermissionTo(Permission::findOrCreate('religious.portal.student', 'web'));
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/religious/students/'.$student->id.'/dashboard')
            ->assertOk()
            ->assertJsonPath('data.student.id', $student->id);

        $this->getJson('/api/v1/religious/students/'.$otherStudent->id.'/dashboard')->assertForbidden();
    }

    public function test_super_admin_tidak_terhalang_403_di_endpoint_religious(): void
    {
        // Regresi: izin `religious.*` hanya dibuat ReligiousMasterSeeder. Bila
        // seeder itu belum dijalankan, super-admin harus tetap lolos lewat
        // bypass Gate (bukan 403) — bug yang membuat modul Religious/Masjid
        // tidak dapat dipakai administrator.
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/religious/meta')->assertOk();
        $this->getJson('/api/v1/religious/integrations')->assertOk();
    }
}
