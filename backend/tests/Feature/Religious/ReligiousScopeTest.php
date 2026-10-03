<?php

namespace Tests\Feature\Religious;

use App\Models\User;
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\Mosque;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReligiousScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_cakupan_kampus_membatasi_daftar_dan_tanpa_cakupan_menghasilkan_kosong(): void
    {
        $first = $this->createMosque('MOS-SCOPE-01', 11);
        $second = $this->createMosque('MOS-SCOPE-02', 22);
        $user = User::factory()->create(['is_active' => true]);
        $user->setAttribute('campus_id', 11);

        $context = app(ReligiousContext::class);
        $visible = $context->applyScope(Mosque::query(), $user, 'campus_id', 'id')->pluck('id')->all();

        $this->assertSame([$first->id], $visible);
        $unscopedUser = User::factory()->create(['is_active' => true]);
        $this->assertSame([], $context->applyScope(Mosque::query(), $unscopedUser, 'campus_id', 'id')->pluck('id')->all());
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_scope_menggabungkan_kampus_dan_masjid_yang_ditugaskan(): void
    {
        $campusMosque = $this->createMosque('MOS-SCOPE-CAMPUS', 11);
        $assignedMosque = $this->createMosque('MOS-SCOPE-ASSIGNED', 22);
        Schema::create('mosque_staff', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('mosque_id');
        });
        $user = User::factory()->create(['is_active' => true]);
        $user->setAttribute('campus_id', 11);
        DB::table('mosque_staff')->insert([
            'user_id' => $user->id,
            'mosque_id' => $assignedMosque->id,
        ]);

        $visible = app(ReligiousContext::class)
            ->applyScope(Mosque::query(), $user, 'campus_id', 'id')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$campusMosque->id, $assignedMosque->id], $visible);
    }

    public function test_peran_global_tetap_dapat_melihat_seluruh_masjid(): void
    {
        $first = $this->createMosque('MOS-GLOBAL-01', 11);
        $second = $this->createMosque('MOS-GLOBAL-02', 22);
        $role = Role::findOrCreate('RELIGIOUS_ADMIN', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        $visible = app(ReligiousContext::class)
            ->applyScope(Mosque::query(), $user, 'campus_id', 'id')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $visible);
    }

    private function createMosque(string $code, int $campusId): Mosque
    {
        return Mosque::query()->create([
            'campus_id' => $campusId,
            'code' => $code,
            'name' => $code,
            'gender_access' => 'MIXED',
            'operational_status' => 'ACTIVE',
            'active' => true,
        ]);
    }
}
