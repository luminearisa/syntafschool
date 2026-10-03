<?php

namespace App\Modules\SchoolOperations\Laundry\Database\Seeders;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryScopeService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryService;
use Illuminate\Database\Seeder;

/**
 * Data fondasi modul laundry: layanan default (dari config) + peran & izin.
 * Tidak membuat data operasional (order/policy/harga) — itu milik sekolah.
 */
class LaundryMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedServices();
        $this->seedRolesAndPermissions();
    }

    protected function seedServices(): void
    {
        $order = 0;
        foreach ((array) config('laundry.services', []) as $code => $definition) {
            LaundryService::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'] ?? $code,
                    'service_type' => $code,
                    'description' => $definition['description'] ?? null,
                    'pricing_model' => $definition['pricing_model'] ?? 'WEIGHT',
                    'unit' => $definition['unit'] ?? 'KG',
                    'processing_hours' => $definition['processing_hours'] ?? null,
                    'is_active' => true,
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

        foreach (LaundryScopeService::PERMISSIONS as $permission) {
            $permissionModel::findOrCreate($permission, 'web');
        }

        $all = $permissionModel::query()->where('name', 'like', 'laundry.%')->get();

        foreach (LaundryScopeService::ROLE_PERMISSIONS as $role => $permissions) {
            $roleInstance = $roleModel::findOrCreate($role, 'web');

            if ($permissions === '*') {
                $roleInstance->syncPermissions($all);

                continue;
            }

            $roleInstance->syncPermissions(
                $all->filter(fn ($permission) => in_array($permission->name, $permissions, true))->values()
            );
        }

        // Admin sistem mendapat seluruh izin laundry.
        foreach (['SUPER_ADMIN', 'ADMIN'] as $adminRole) {
            $instance = $roleModel::query()->where('name', $adminRole)->first();
            if ($instance) {
                $instance->givePermissionTo($all);
            }
        }
    }
}
