<?php

namespace App\Modules\Mutation\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular modul Mutation (Mutasi Siswa). Berdiri sendiri agar tidak
 * menyunting seeder ERP.
 */
class MutationPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'mutation.view',
        'mutation.create',
        'mutation.update',
        'mutation.approve',
        'mutation.complete',
        'mutation.delete',
    ];

    /** @var array<string, list<string>> */
    public const ROLE_MAP = [
        'admin-sekolah' => self::PERMISSIONS,
        'kepala-sekolah' => [
            'mutation.view',
            'mutation.approve',
        ],
        'staf' => [
            'mutation.view',
            'mutation.create',
            'mutation.update',
        ],
        'wali-kelas' => [
            'mutation.view',
            'mutation.create',
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
