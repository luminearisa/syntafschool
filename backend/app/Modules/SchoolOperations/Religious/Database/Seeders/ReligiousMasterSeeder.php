<?php

namespace App\Modules\SchoolOperations\Religious\Database\Seeders;

use App\Modules\SchoolOperations\Religious\Application\Services\{
    KhutbahService, PrayerScheduleService, ReligiousLearningService, ReligiousScopeService
};
use Illuminate\Database\Seeder;

/**
 * Data fondasi modul Religious & Mosque Management (§4, §10, §30, §41).
 *
 * Hanya mengisi data acuan: izin, peran, jenis shalat, jenis penilaian, dan
 * kategori khutbah. TIDAK membuat data operasional (masjid, jadwal, petugas) —
 * itu milik sekolah/pesantren.
 */
class ReligiousMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRolesAndPermissions();
        $this->seedPrayerTypes();
        $this->seedAssessmentTypes();
        $this->seedKhutbahCategories();
    }

    protected function seedRolesAndPermissions(): void
    {
        $permissionModel = 'Spatie\\Permission\\Models\\Permission';
        $roleModel = 'Spatie\\Permission\\Models\\Role';

        if (! class_exists($permissionModel) || ! class_exists($roleModel)) {
            return;
        }

        foreach (ReligiousScopeService::PERMISSIONS as $permission) {
            $permissionModel::findOrCreate($permission, 'web');
        }

        $all = $permissionModel::query()->where('name', 'like', 'religious.%')->get();

        foreach (ReligiousScopeService::ROLE_PERMISSIONS as $role => $permissions) {
            $roleInstance = $roleModel::findOrCreate($role, 'web');

            if ($permissions === '*') {
                $roleInstance->syncPermissions($all);

                continue;
            }

            $roleInstance->syncPermissions($permissions);
        }

        // Peran administrator umum memakai penamaan yang benar-benar dibuat
        // RolePermissionSeeder (huruf kecil). Sebelumnya seeder ini menulis
        // SUPER_ADMIN/SCHOOL_ADMIN (huruf besar) yang tidak pernah ada,
        // sehingga admin sekolah pun tidak mendapat izin modul.
        $adminRoles = ['super-admin', 'admin-sekolah'];
        foreach ($adminRoles as $role) {
            $instance = $roleModel::query()->where('name', $role)->where('guard_name', 'web')->first();
            $instance?->givePermissionTo($all);
        }

        // Kepala sekolah memantau modul (hanya baca).
        $readOnly = $all->filter(fn ($permission): bool => str_ends_with($permission->name, '.view')
            || in_array($permission->name, [
                'religious.report.view', 'religious.report.export',
                'religious.analytics.view', 'religious.audit.view',
            ], true));
        $roleModel::query()
            ->where('name', 'kepala-sekolah')
            ->where('guard_name', 'web')
            ->first()
            ?->givePermissionTo($readOnly);
    }

    protected function seedPrayerTypes(): void
    {
        app(PrayerScheduleService::class)->seedPrayerTypes();
    }

    protected function seedAssessmentTypes(): void
    {
        app(ReligiousLearningService::class)->seedAssessmentTypes();
    }

    protected function seedKhutbahCategories(): void
    {
        app(KhutbahService::class)->seedCategories();
    }
}
