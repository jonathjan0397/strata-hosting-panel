<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $resellerRole = Role::firstOrCreate(['name' => 'reseller']);
        $userRole = Role::firstOrCreate(['name' => 'user']);

        // Default admin for local installs. Production installers create the requested admin separately.
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@localhost'],
            [
                'name' => 'Administrator',
                'password' => bcrypt('ChangeMe123!'),
            ]
        );
        $adminUser->assignRole($adminRole);

        // Demo accounts are only created when STRATA_DEMO_MODE=true.
        if (config('strata.demo_mode')) {
            $demoAdmin = User::firstOrCreate(
                ['email' => 'demo-admin@stratadevplatform.net'],
                [
                    'name' => 'Demo Admin',
                    'password' => bcrypt('DemoAdmin2026!'),
                ]
            );
            $demoAdmin->assignRole($adminRole);

            $demoReseller = User::firstOrCreate(
                ['email' => 'demo-reseller@stratadevplatform.net'],
                [
                    'name' => 'Demo Reseller',
                    'password' => bcrypt('DemoReseller2026!'),
                ]
            );
            $demoReseller->assignRole($resellerRole);

            $demoUser = User::firstOrCreate(
                ['email' => 'demo-user@stratadevplatform.net'],
                [
                    'name' => 'Demo User',
                    'password' => bcrypt('DemoUser2026!'),
                ]
            );
            $demoUser->assignRole($userRole);

            $demoClient = User::firstOrCreate(
                ['email' => 'demo-client@stratadevplatform.net'],
                [
                    'name' => 'Demo Client',
                    'password' => bcrypt('DemoClient2026!'),
                ]
            );
            $demoClient->assignRole($userRole);
        }
    }
}
