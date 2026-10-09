<?php

namespace Tests\Feature\Asset;

use App\Models\School;
use App\Models\User;
use App\Modules\Asset\Application\Services\AssetRegisterService;
use App\Modules\Asset\Application\Support\AssetTenantResolver;
use App\Modules\Asset\Database\Seeders\AssetMasterSeeder;
use App\Modules\Asset\Database\Seeders\AssetPermissionSeeder;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCustodian;
use App\Modules\Asset\Models\AssetType;
use App\Modules\Asset\Models\Building;
use App\Modules\Asset\Models\Campus;
use App\Modules\Asset\Models\Facility;
use App\Modules\Asset\Models\FacilityType;
use App\Modules\Asset\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dunia kecil yang realistis untuk menguji modul Asset & Sarpras: satu kampus
 * dengan gedung, lantai, dan dua ruang; beberapa pengguna dengan peran berbeda;
 * serta kategori aset yang dapat disusutkan.
 *
 * Dua ruang sengaja dibuat supaya setiap uji pemindahan benar-benar menguji
 * "dari ruang A ke ruang B", bukan memindahkan sesuatu ke tempat yang sama.
 */
abstract class AssetTestCase extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Building $building;

    protected Location $ruangA;

    protected Location $ruangB;

    protected AssetCategory $categoryElektronik;

    protected User $admin;

    protected User $officer;

    protected User $technician;

    protected User $guru;

    protected User $kepalaSekolah;

    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(AssetPermissionSeeder::ROLES) as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(AssetPermissionSeeder::class);

        $this->admin = $this->makeUser('admin-aset@example.test', 'ASSET_ADMIN');
        $this->officer = $this->makeUser('petugas-aset@example.test', 'ASSET_OFFICER');
        $this->technician = $this->makeUser('teknisi@example.test', 'TECHNICIAN');
        $this->guru = $this->makeUser('guru@example.test', 'DEPARTMENT_MANAGER');
        $this->kepalaSekolah = $this->makeUser('kepsek@example.test', 'DEPARTMENT_MANAGER');
        $this->finance = $this->makeUser('keuangan@example.test', 'FINANCE');

        $this->seed(AssetMasterSeeder::class);

        $this->campus = Campus::query()->create(['code' => 'KP-A', 'name' => 'Kampus A', 'status' => 'ACTIVE']);
        $this->building = Building::query()->create(['campus_id' => $this->campus->id, 'code' => 'GD-A', 'name' => 'Gedung A', 'floors_count' => 2]);
        $lantai = $this->building->floors()->create(['level' => 1, 'name' => 'Lantai 1']);
        $lantaiDua = $this->building->floors()->create(['level' => 2, 'name' => 'Lantai 2']);

        $this->ruangA = Location::query()->create([
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'building_floor_id' => $lantai->id,
            'code' => 'R-101', 'name' => 'Ruang 101', 'location_type' => 'CLASSROOM', 'level' => 4, 'capacity' => 32, 'path' => 'KP-A/GD-A/L1/R-101',
        ]);

        $this->ruangB = Location::query()->create([
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'building_floor_id' => $lantaiDua->id,
            'code' => 'LAB-1', 'name' => 'Laboratorium 1', 'location_type' => 'LAB', 'level' => 4, 'capacity' => 30, 'path' => 'KP-A/GD-A/L2/LAB-1',
        ]);

        $this->categoryElektronik = AssetCategory::query()->where('code', 'IT')->firstOrFail();
    }

    protected function makeUser(string $email, string $role): User
    {
        $user = User::query()->create([
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        $user->assignRole($role);

        return $user->refresh();
    }

    protected function actAs(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /** Aset siap pakai: terdaftar, terlabel, dan berada di Ruang 101. */
    protected function makeAsset(array $overrides = [], ?User $actor = null): Asset
    {
        $actor ??= $this->admin;

        $type = AssetType::query()->where('category_id', $this->categoryElektronik->id)->first();

        $asset = app(AssetRegisterService::class)->register($actor, array_merge([
            'name' => 'Laptop Lenovo ThinkPad',
            'category_id' => $this->categoryElektronik->id,
            'asset_type_id' => $type?->id,
            'location_id' => $this->ruangA->id,
            'acquisition_cost' => '8500000',
            'residual_value' => '500000',
            'useful_life_months' => 48,
            'purchase_date' => now()->subMonths(6)->toDateString(),
            'received_date' => now()->subMonths(6)->toDateString(),
            'commission_date' => now()->subMonths(6)->toDateString(),
            'serial_number' => 'SN-'.random_int(100000, 999999),
            'warranty_start' => now()->subMonths(6)->toDateString(),
            'warranty_end' => now()->addMonths(6)->toDateString(),
            'force_asset' => true,
        ], $overrides));

        return $asset->refresh();
    }

    protected function makeFacility(array $overrides = []): Facility
    {
        $type = FacilityType::query()->where('code', 'CLASSROOM')->first();

        $facility = Facility::query()->create(array_merge([
            'campus_id' => $this->campus->id,
            'location_id' => $this->ruangA->id,
            'facility_type_id' => $type?->id,
            'facility_code' => 'FCL-'.random_int(1000, 9999),
            'name' => 'Ruang 101',
            'capacity_total' => 32,
            'status' => 'ACTIVE',
            'availability' => 'AVAILABLE',
            'min_booking_minutes' => 30,
            'max_booking_minutes' => 480,
            'advance_booking_days' => 90,
            'requires_approval' => true,
        ], $overrides));

        foreach (range(0, 6) as $day) {
            $facility->operatingHours()->create([
                'day_of_week' => $day,
                'open_time' => '07:00',
                'close_time' => '18:00',
                'is_closed' => $day === 0,
            ]);
        }

        return $facility->refresh();
    }

    protected function makeCustodian(string $name = 'Budi Petugas'): AssetCustodian
    {
        return AssetCustodian::query()->create([
            'name' => $name,
            'custodian_type' => 'STAFF',
            'user_id' => $this->officer->id,
            'is_active' => true,
        ]);
    }

    protected function makeSchool(string $name): School
    {
        return School::query()->create(['name' => $name, 'jenjang' => 'SMP', 'status' => 'Swasta']);
    }

    /** Jalankan callback seolah permintaan datang dari unit sekolah tertentu. */
    protected function asTenant(?int $schoolId, callable $callback): mixed
    {
        $resolver = app(AssetTenantResolver::class);
        $previous = config('asset.tenant.enabled');
        config(['asset.tenant.enabled' => $schoolId !== null]);
        $resolver->force($schoolId);

        try {
            return $callback();
        } finally {
            $resolver->force(null);
            config(['asset.tenant.enabled' => $previous]);
        }
    }

    protected function statusOf(Asset $asset): string
    {
        return $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;
    }
}
