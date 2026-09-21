<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@syntaf.sch.id'],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => 'password',
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['super-admin']);
    }
}
