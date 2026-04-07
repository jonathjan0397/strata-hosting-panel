<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\HostingDatabase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseToolsController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();

        $databases = HostingDatabase::where('account_id', $account->id)
            ->orderBy('engine')
            ->orderBy('db_name')
            ->get(['id', 'engine', 'db_name', 'db_user']);

        $host = $account->node->hostname ?: $account->node->ip_address;
        $baseUrl = rtrim(config('app.url'), '/');

        return Inertia::render('User/Database/Tools', [
            'account' => [
                'username' => $account->username,
                'node' => [
                    'name' => $account->node->name,
                    'hostname' => $host,
                ],
            ],
            'tools' => [
                [
                    'name' => 'phpMyAdmin',
                    'engine' => 'mysql',
                    'label' => 'MySQL / MariaDB',
                    'url' => $this->toolUrl($baseUrl, 'phpmyadmin'),
                    'available' => is_dir('/usr/share/phpmyadmin'),
                    'login' => 'Use the database username and password created in Strata.',
                ],
                [
                    'name' => 'phpPgAdmin',
                    'engine' => 'postgresql',
                    'label' => 'PostgreSQL',
                    'url' => $this->toolUrl($baseUrl, 'phppgadmin'),
                    'available' => is_dir('/usr/share/phppgadmin'),
                    'login' => 'Use the PostgreSQL database username and password created in Strata.',
                ],
            ],
            'databases' => $databases,
            'connection' => [
                'host' => $host,
                'localHost' => 'localhost',
            ],
        ]);
    }

    private function toolUrl(string $baseUrl, string $path): string
    {
        return $baseUrl . '/' . trim($path, '/') . '/';
    }
}
