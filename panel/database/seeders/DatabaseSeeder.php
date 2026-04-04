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
        $adminRole    = Role::firstOrCreate(['name' => 'admin']);
        $resellerRole = Role::firstOrCreate(['name' => 'reseller']);
        $userRole     = Role::firstOrCreate(['name' => 'user']);

        // Default admin (production — change password on first login)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@localhost'],
            [
                'name'     => 'Administrator',
                'password' => bcrypt('ChangeMe123!'),
            ]
        );
        $adminUser->assignRole($adminRole);

        // Demo accounts — only created when STRATA_DEMO_MODE=true
        if (config('strata.demo_mode')) {
            $demoAdmin = User::firstOrCreate(
                ['email' => 'demo-admin@example.com'],
                [
                    'name'     => 'Demo Admin',
                    'password' => bcrypt('DemoAdmin2026'),
                ]
            );
            $demoAdmin->assignRole($adminRole);

            $demoUser = User::firstOrCreate(
                ['email' => 'demo-user@example.com'],
                [
                    'name'     => 'Demo User',
                    'password' => bcrypt('DemoUser2026!'),
                ]
            );
            $demoUser->assignRole($userRole);
        }
    }
}
