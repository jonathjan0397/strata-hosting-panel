<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $admin    = Role::firstOrCreate(['name' => 'admin']);
        $reseller = Role::firstOrCreate(['name' => 'reseller']);
        $user     = Role::firstOrCreate(['name' => 'user']);

        // Create default admin account (change password on first login)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@localhost'],
            [
                'name'     => 'Administrator',
                'password' => bcrypt('ChangeMe123!'),
            ]
        );

        $adminUser->assignRole($admin);
    }
}
