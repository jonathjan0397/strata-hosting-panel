<?php
require __DIR__ . '/panel/vendor/autoload.php';
$app = require __DIR__ . '/panel/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = \App\Models\User::firstOrCreate(
    ['email' => 'admin@stratadevplatform.com'],
    ['name' => 'Admin', 'password' => bcrypt('AdminPanel2026!')]
);
$u->assignRole('admin');
echo "Admin ready: " . $u->email . PHP_EOL;
