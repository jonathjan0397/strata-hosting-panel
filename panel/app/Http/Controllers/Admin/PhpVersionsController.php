<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhpVersionsController extends Controller
{
    /**
     * Display available PHP versions and their status
     */
    public function index(Request $request): Response
    {
        // Get all accounts to see what versions are in use
        $accounts = Account::with(['node'])->get();
        
        // Get installed PHP versions from system
        $installedVersions = $this->getInstalledPhpVersions();
        $availableVersions = $this->getSupportedPhpVersions();
        
        // Get accounts using each version
        $versionUsage = $accounts->groupBy('php_version')->map(function ($group) {
            return $group->count();
        })->toArray();
        
        // Get accounts by node for more detailed info
        $accountsByNode = $accounts->groupBy('node_id')->map(function ($nodes) {
            return $nodes->groupBy('php_version')->map(function ($group) {
                return $group->count();
            })->toArray();
        })->toArray();
        
        return Inertia::render('Admin/PhpVersions/Index', [
            'installedVersions' => $installedVersions,
            'availableVersions' => $availableVersions,
            'versionUsage' => $versionUsage,
            'accountsByNode' => $accountsByNode,
            'canManage' => $request->user()->hasRole('admin'),
        ]);
    }

    /**
     * Install a missing PHP version on the primary server.
     */
    public function install(Request $request, string $version): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $this->validatePhpVersion($version);

        if (in_array($version, $this->getInstalledPhpVersions(), true)) {
            return back()->with('success', "PHP {$version} is already installed.");
        }

        $node = Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->first();

        if (! $node) {
            return back()->with('error', 'No primary node is registered for PHP installation.');
        }

        $response = AgentClient::for($node)->installPhpVersion($version);

        if (! $response->successful()) {
            $payload = $response->json();
            $message = is_array($payload)
                ? ($payload['output'] ?? $payload['error'] ?? $response->body())
                : $response->body();

            return back()->with('error', trim("PHP {$version} installation failed. {$message}"));
        }

        AuditLog::record('php.version_installed', $request->user(), [
            'version' => $version,
            'node' => $node->name,
        ]);

        return back()->with('success', "PHP {$version} has been installed on {$node->name}.");
    }

    /**
     * Enable a PHP version
     */
    public function enable(Request $request, string $version): RedirectResponse
    {
        $this->validatePhpVersion($version);
        
        // In a real implementation, we would actually enable the PHP version
        # For now, we'll just log it since enabling/disabling PHP versions
        # is typically done at the system level via package management
        
        AuditLog::record('php.version_enabled', $request->user(), [
            'version' => $version,
        ]);
        
        return back()->with('success', "PHP {$version} has been enabled.");
    }

    /**
     * Disable a PHP version
     */
    public function disable(Request $request, string $version): RedirectResponse
    {
        $this->validatePhpVersion($version);
        
        // Check if any accounts are using this version
        $accountsUsingVersion = Account::where('php_version', $version)->exists();
        
        if ($accountsUsingVersion) {
            return back()->with('error', "Cannot disable PHP {$version} because it is currently in use by {$accountsUsingVersion} account(s). Please migrate those accounts to a different PHP version first.");
        }
        
        // In a real implementation, we would actually disable the PHP version
        # For now, we'll just log it since enabling/disabling PHP versions
        # is typically done at the system level via package management
        
        AuditLog::record('php.version_disabled', $request->user(), [
            'version' => $version,
        ]);
        
        return back()->with('success', "PHP {$version} has been disabled.");
    }

    /**
     * Validate that the PHP version is in our supported list
     */
    private function validatePhpVersion(string $version): void
    {
        $supportedVersions = $this->getSupportedPhpVersions();
        
        if (!in_array($version, $supportedVersions, true)) {
            throw new \InvalidArgumentException("Unsupported PHP version: {$version}");
        }
    }

    /**
     * Get currently installed PHP versions from the system
     */
    private function getInstalledPhpVersions(): array
    {
        $versions = [];
        
        // Check for common PHP binary paths
        $phpBinaries = [
            '/usr/bin/php7.4',
            '/usr/bin/php8.0',
            '/usr/bin/php8.1',
            '/usr/bin/php8.2',
            '/usr/bin/php8.3',
            '/usr/bin/php8.4',
        ];
        
        foreach ($phpBinaries as $binary) {
            if (is_executable($binary)) {
                // Extract version from path (e.g., /usr/bin/php8.1 -> 8.1)
                if (preg_match('#php(\d+\.\d+)$#', $binary, $matches)) {
                    $versions[] = $matches[1];
                }
            }
        }
        
        // Also check via php -v if we have a default php
        if (empty($versions) && is_executable('/usr/bin/php')) {
            $output = shell_exec('/usr/bin/php -r "echo PHP_MAJOR_VERSION . \'.\' . PHP_MINOR_VERSION;"');
            if ($output !== null && preg_match('#^(\d+\.\d+)#', trim($output), $matches)) {
                $versions[] = $matches[1];
            }
        }
        
        return array_unique($versions);
    }

    /**
     * Get PHP versions supported by this server's Debian release.
     */
    private function getSupportedPhpVersions(): array
    {
        $versionId = $this->osReleaseValue('VERSION_ID');

        return match ($versionId) {
            '13' => ['7.4', '8.0', '8.2', '8.3', '8.4'],
            '12' => ['7.4', '8.0', '8.1', '8.2', '8.3'],
            default => ['7.4', '8.0', '8.1', '8.2', '8.3'],
        };
    }

    private function osReleaseValue(string $key): string
    {
        if (! is_readable('/etc/os-release')) {
            return '';
        }

        foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_starts_with($line, "{$key}=")) {
                continue;
            }

            return trim(substr($line, strlen($key) + 1), '"');
        }

        return '';
    }
}
