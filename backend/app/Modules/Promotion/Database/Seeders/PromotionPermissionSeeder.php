<?php

namespace App\Modules\Promotion\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular modul Promotion (A10 — Kenaikan Kelas & Kelulusan).
 *
 * Berdiri sendiri agar tidak menyunting seeder ERP; pemanggilannya dilakukan
 * dari DatabaseSeeder oleh agen utama.
 */
class PromotionPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'promotion.view',
        'promotion.manage',
        'promotion.decide',
        'promotion.approve',
        'promotion.finalize',
        'graduation.view',
        'graduation.manage',
        'graduation.decide',
    ];

    /** @var array<string, list<string>> */
    public const ROLE_MAP = [
        'admin-sekolah' => self::PERMISSIONS,
        'kepala-sekolah' => [
            'promotion.view', 'promotion.approve', 'graduation.view', 'graduation.decide',
        ],
        'wali-kelas' => [
            'promotion.view', 'promotion.manage', 'promotion.decide',
        ],
        'guru' => [
            'promotion.view',
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
