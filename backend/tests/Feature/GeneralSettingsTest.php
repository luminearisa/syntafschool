<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role = 'admin-sekolah'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function schoolPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Syntaf Islamic School',
            'npsn' => '12345678',
            'jenjang' => 'SMA',
            'status' => 'Swasta',
            'accreditation' => 'A',
            'address' => 'Jl. Pendidikan No. 1',
            'phone' => '021123456',
            'email' => 'info@syntaf.sch.id',
            'website' => 'https://syntaf.sch.id',
            'headmaster_name' => 'Ahmad Hidayat',
            'is_active' => true,
        ], $overrides);
    }

    public function test_profil_sekolah_dapat_dibuat_dan_dibaca_melalui_pengaturan_umum(): void
    {
        $this->actingAsRole();

        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.school', null);

        $this->putJson('/api/v1/settings/general', [
            'school' => $this->schoolPayload(),
        ])->assertOk()
            ->assertJsonPath('data.school.name', 'Syntaf Islamic School');

        $this->assertSame(1, School::query()->count());
    }

    public function test_pengaturan_umum_memperbarui_sekolah_yang_sudah_ada(): void
    {
        $this->actingAsRole();
        $school = School::create($this->schoolPayload());

        $this->putJson('/api/v1/settings/general', [
            'school' => $this->schoolPayload(['id' => $school->id, 'name' => 'Syntaf School Baru']),
        ])->assertOk()->assertJsonPath('data.school.name', 'Syntaf School Baru');

        $this->assertSame(1, School::query()->count());
    }

    public function test_hanya_pemegang_permission_setting_yang_dapat_membaca_dan_mengubah(): void
    {
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/settings/general')->assertForbidden();
        $this->putJson('/api/v1/settings/general', ['school' => $this->schoolPayload()])->assertForbidden();
    }

    public function test_jenjang_sekolah_harus_valid(): void
    {
        $this->actingAsRole();
        $this->putJson('/api/v1/settings/general', [
            'school' => $this->schoolPayload(['jenjang' => 'Universitas']),
        ])->assertUnprocessable()->assertJsonValidationErrors('school.jenjang');
    }
}
