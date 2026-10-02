<?php

namespace App\Modules\SchoolOperations\Transportation\Database\Seeders;

use App\Modules\SchoolOperations\Transportation\Application\Services\TransportationScopeService;
use App\Modules\SchoolOperations\Transportation\Models\{TransportationService, TransportationVehicleType};
use Illuminate\Database\Seeder;

/**
 * Data fondasi modul transportasi: jenis layanan, jenis kendaraan, peran, dan izin.
 * Tidak membuat data operasional (kendaraan, pengemudi, rute) — itu milik sekolah.
 */
class TransportationMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedServices();
        $this->seedVehicleTypes();
        $this->seedRolesAndPermissions();
    }

    protected function seedServices(): void
    {
        foreach ((array) config('transportation.service_types', []) as $code => $definition) {
            TransportationService::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'] ?? $code,
                    'service_type' => $code,
                    'description' => $definition['description'] ?? null,
                    'billable' => (bool) ($definition['billable'] ?? false),
                    'active' => true,
                ]
            );
        }
    }

    protected function seedVehicleTypes(): void
    {
        $order = 0;
        foreach ((array) config('transportation.vehicle_types', []) as $code => $definition) {
            TransportationVehicleType::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'] ?? $code,
                    'license_requirement' => $definition['license_requirement'] ?? null,
                    'default_passenger_capacity' => $definition['passenger_capacity'] ?? null,
                    'default_standing_capacity' => $definition['standing_capacity'] ?? null,
                    'active' => true,
                    'sort_order' => ++$order,
                ]
            );
        }
    }

    protected function seedRolesAndPermissions(): void
    {
        $permissionModel = 'Spatie\\Permission\\Models\\Permission';
        $roleModel = 'Spatie\\Permission\\Models\\Role';

        if (! class_exists($permissionModel) || ! class_exists($roleModel)) {
            return;
        }

        foreach (TransportationScopeService::PERMISSIONS as $permission) {
            $permissionModel::findOrCreate($permission, 'web');
        }

        $all = $permissionModel::query()->where('name', 'like', 'transportation.%')->get();

        foreach (TransportationScopeService::ROLE_PERMISSIONS as $role => $permissions) {
            $roleInstance = $roleModel::findOrCreate($role, 'web');

            if ($permissions === '*') {
                $roleInstance->syncPermissions($all);

                continue;
            }

            $roleInstance->syncPermissions($permissions);
        }

        // Peran tingkat yayasan/administrator mendapat seluruh izin modul.
        foreach (['FOUNDATION_ADMIN', 'SUPER_ADMIN', 'HR_DIRECTOR'] as $role) {
            $instance = $roleModel::query()->where('name', $role)->first();
            if ($instance) {
                $instance->givePermissionTo($all);
            }
        }
    }
}
