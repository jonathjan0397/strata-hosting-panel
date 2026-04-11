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

        $host = $account->node->hostname ?: $account->node->ip_address;
        $baseUrl = rtrim(config('app.url'), '/');

        $databases = HostingDatabase::where('account_id', $account->id)
            ->orderBy('engine')
            ->orderBy('db_name')
            ->get()
            ->map(function (HostingDatabase $database) use ($baseUrl): array {
                $engine = $database->engine ?? 'mysql';
                $password = $database->password_plain;

                return [
                    'id' => $database->id,
                    'engine' => $engine,
                    'db_name' => $database->db_name,
                    'db_user' => $database->db_user,
                    'password' => $password,
                    'password_available' => $password !== null,
                    'tool_name' => $engine === 'postgresql' ? 'phpPgAdmin' : 'phpMyAdmin',
                    'tool_url' => $this->toolUrl($baseUrl, $engine === 'postgresql' ? 'phppgadmin' : 'phpmyadmin', $database->db_name),
                ];
            })
            ->values();

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
                    'login' => 'Open phpMyAdmin with the database username and saved password shown below.',
                ],
                [
                    'name' => 'phpPgAdmin',
                    'engine' => 'postgresql',
                    'label' => 'PostgreSQL',
                    'url' => $this->toolUrl($baseUrl, 'phppgadmin'),
                    'available' => is_dir('/usr/share/phppgadmin'),
                    'login' => 'Open phpPgAdmin with the database username and saved password shown below.',
                ],
            ],
            'databases' => $databases,
            'connection' => [
                'host' => $host,
                'localHost' => 'localhost',
            ],
        ]);
    }

    private function toolUrl(string $baseUrl, string $path, ?string $databaseName = null): string
    {
        $url = $baseUrl . '/' . trim($path, '/') . '/';

        if ($databaseName) {
            $url .= '?db=' . urlencode($databaseName);
        }

        return $url;
    }
}
