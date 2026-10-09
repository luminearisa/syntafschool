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

        // Admin sistem mendapat seluruh izin laundry — cakup kedua konvensi
        // penamaan peran yang dipakai basis kode ini: `SUPER_ADMIN`/`ADMIN`
        // (modul berbasis konstanta) dan `super-admin`/`admin-sekolah`
        // (RolePermissionSeeder inti, yang menjadi peran aktual akun admin).
        foreach (['SUPER_ADMIN', 'ADMIN', 'super-admin', 'admin-sekolah'] as $adminRole) {
            $instance = $roleModel::query()->where('name', $adminRole)->first();
            if ($instance) {
                $instance->givePermissionTo($all);
            }
        }

        // Peran inti `siswa`/`wali-murid` (RolePermissionSeeder) adalah peran
        // aktual akun santri/wali di instalasi nyata — tanpa `laundry.portal.view`,
        // portal /me/laundry dan /parent/laundry menolak 403 untuk semua santri
        // dan wali meskipun peran modul STUDENT/PARENT sudah dibuat.
        $portal = $all->filter(fn ($permission) => $permission->name === 'laundry.portal.view')->values();
        foreach (['siswa', 'wali-murid'] as $portalRole) {
            $instance = $roleModel::query()->where('name', $portalRole)->first();
            if ($instance) {
                $instance->givePermissionTo($portal);
            }
        }
    }
}
