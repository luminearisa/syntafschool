<?php

namespace App\Modules\StudentProgress\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular modul StudentProgress (Prestasi & Pelanggaran siswa).
 *
 * Berdiri sendiri agar modul dapat dipasang tanpa menyunting `RolePermissionSeeder`
 * milik ERP; ia membuat `progress.*` lalu membagikannya ke peran ERP yang ada.
 */
class StudentProgressPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'progress.achievement.view',
        'progress.achievement.create',
        'progress.achievement.update',
        'progress.achievement.delete',
        'progress.violation.view',
        'progress.violation.create',
        'progress.violation.update',
        'progress.violation.delete',
        'progress.violation.verify',
        'progress.point.view',
    ];

    /** @var array<string, list<string>> */
    public const ROLE_MAP = [
        'admin-sekolah' => [
            'progress.achievement.view', 'progress.achievement.create',
            'progress.achievement.update', 'progress.achievement.delete',
            'progress.violation.view', 'progress.violation.create',
            'progress.violation.update', 'progress.violation.delete',
            'progress.violation.verify', 'progress.point.view',
        ],
        'wali-kelas' => [
            'progress.achievement.view', 'progress.achievement.create', 'progress.achievement.update',
            'progress.violation.view', 'progress.violation.create', 'progress.violation.update',
            'progress.violation.verify',
            'progress.point.view',
        ],
        'guru' => [
            'progress.achievement.view',
            'progress.violation.view', 'progress.violation.create',
        ],
        'kepala-sekolah' => [
            'progress.achievement.view',
            'progress.violation.view',
            'progress.point.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $all = Permission::query()->whereIn('name', self::PERMISSIONS)->get();

        if ($super = Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first()) {
            $super->givePermissionTo($all);
        }

        foreach (self::ROLE_MAP as $roleName => $names) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                continue;
            }
            $role->givePermissionTo(Permission::query()->whereIn('name', $names)->get());
        }
    }
}
