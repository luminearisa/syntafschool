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

        // Peran tingkat yayasan/administrator mendapat seluruh izin modul.
        foreach (['SUPER_ADMIN', 'FOUNDATION_ADMIN', 'HR_DIRECTOR', 'SCHOOL_ADMIN'] as $role) {
            $instance = $roleModel::query()->where('name', $role)->first();
            if ($instance) {
                $instance->givePermissionTo($all);
            }
        }
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
