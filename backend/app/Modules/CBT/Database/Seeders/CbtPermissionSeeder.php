<?php

namespace App\Modules\CBT\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular modul CBT. Berdiri sendiri agar tidak menyunting seeder ERP.
 */
class CbtPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'cbt.view',
        'cbt.question.view',
        'cbt.question.create',
        'cbt.question.update',
        'cbt.question.delete',
        'cbt.blueprint.view',
        'cbt.blueprint.manage',
        'cbt.session.view',
        'cbt.session.manage',
        'cbt.proctor',
        'cbt.grade',
        'cbt.report.view',
        'cbt.attempt',
    ];

    /** @var array<string, list<string>> */
    public const ROLE_MAP = [
        'guru' => [
            'cbt.view', 'cbt.question.view', 'cbt.question.create', 'cbt.question.update',
            'cbt.blueprint.view', 'cbt.blueprint.manage',
            'cbt.session.view', 'cbt.session.manage',
            'cbt.proctor', 'cbt.grade', 'cbt.report.view',
        ],
        'kepala-sekolah' => [
            'cbt.view', 'cbt.question.view', 'cbt.blueprint.view', 'cbt.session.view', 'cbt.report.view',
        ],
        'siswa' => [
            'cbt.view', 'cbt.attempt',
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
