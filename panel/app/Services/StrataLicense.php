<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * StrataLicense — communicates with the Strata license server.
 *
 * Usage:
 *   StrataLicense::hasFeature('premium_reseller')
 *   StrataLicense::status()
 *   StrataLicense::sync()           // called by strata:license-sync command
 *
 * Graceful degradation: any failure returns status=active, features=[].
 * Customer installs never break due to a license server outage.
 */
class StrataLicense
{
    private const CACHE_KEY = 'strata_license_response';
    private const CACHE_TTL = 90000; // 25 hours — survives a daily-sync miss

    /**
     * Ping the license server and cache the response.
     * Returns the response array, or the fallback on any failure.
     */
    public static function sync(): array
    {
        $url    = config('strata.license_server_url');
        $token  = config('strata.install_token');
        $secret = config('strata.install_secret');

        // No license server configured — run as open Community edition.
        if (! $url || ! $token) {
            $fallback = self::fallback();
            Cache::put(self::CACHE_KEY, $fallback, self::CACHE_TTL);
            return $fallback;
        }

        if (! self::licenseTransportAllowed($url)) {
            Log::warning('StrataLicense: refusing non-HTTPS license sync because STRATA_LICENSE_ALLOW_INSECURE_TRANSPORT is not enabled');
            return self::useCachedOrFallback();
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post(rtrim($url, '/') . '/api/ping', [
                    'install_token'  => $token,
                    'install_secret' => $secret,
                    'software'       => 'strata-hosting-panel',
                    'version'        => config('strata.version', 'dev'),
                    'app_url'        => config('app.url'),
                    'install_path'   => self::installPath(),
                    'server_ip'      => self::serverIp(),
                    'nodes'          => self::nodePayload(),
                    'demo_mode'      => (bool) config('strata.demo_mode'),
                ]);

            if (! $response->successful()) {
                Log::warning('StrataLicense: ping returned HTTP ' . $response->status());
                return self::useCachedOrFallback();
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['status'])) {
                Log::warning('StrataLicense: unexpected response format');
                return self::useCachedOrFallback();
            }

            // Verify the HMAC signature if the server included one.
            if ($secret && isset($data['sig'])) {
                $payload = ['status' => $data['status'], 'features' => $data['features'] ?? []];
                $expected = hash_hmac(
                    'sha256',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $secret
                );
                if (! hash_equals($expected, $data['sig'])) {
                    Log::warning('StrataLicense: signature mismatch — ignoring response');
                    return self::useCachedOrFallback();
                }
            }

            $cached = [
                'status'     => $data['status'] ?? 'active',
                'features'   => array_values(array_filter((array) ($data['features'] ?? []), 'is_string')),
                'synced_at'  => now()->toISOString(),
            ];

            Cache::put(self::CACHE_KEY, $cached, self::CACHE_TTL);

            return $cached;

        } catch (Throwable $e) {
            Log::warning('StrataLicense: sync failed — ' . $e->getMessage());
            return self::useCachedOrFallback();
        }
    }

    /**
     * Return the installation's license status: 'active', 'suspended', or 'unknown'.
     */
    public static function status(): string
    {
        return self::cached()['status'] ?? 'active';
    }

    /**
     * Check if this installation has a specific feature enabled.
     */
    public static function hasFeature(string $key): bool
    {
        return in_array($key, self::features(), true);
    }

    /**
     * Return all enabled feature keys for this installation.
     */
    public static function features(): array
    {
        return self::cached()['features'] ?? [];
    }

    /**
     * Return the full cached response (useful for dashboard display).
     */
    public static function cached(): array
    {
        return Cache::get(self::CACHE_KEY, self::fallback());
    }

    /**
     * True if a license server URL is configured (non-community install).
     */
    public static function isManaged(): bool
    {
        return (bool) config('strata.license_server_url');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function useCachedOrFallback(): array
    {
        // Keep serving last known-good cache if it exists.
        if (Cache::has(self::CACHE_KEY)) {
            return Cache::get(self::CACHE_KEY);
        }
        return self::fallback();
    }

    private static function fallback(): array
    {
        return [
            'status'    => 'active',
            'features'  => [],
            'synced_at' => null,
        ];
    }

    private static function installPath(): string
    {
        $basePath = base_path();

        return basename($basePath) === 'panel'
            ? dirname($basePath)
            : $basePath;
    }

    private static function serverIp(): ?string
    {
        $primary = Node::query()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $primary ? self::publicNodeIp($primary) : null;
    }

    private static function nodePayload(): array
    {
        return Node::query()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(function (Node $node): array {
                return [
                    'name'          => $node->name,
                    'hostname'      => $node->hostname,
                    'ip_address'    => self::publicNodeIp($node),
                    'role'          => $node->is_primary ? 'primary' : 'child',
                    'status'        => $node->status,
                    'web_server'    => $node->web_server,
                    'agent_version' => $node->agent_version,
                    'is_primary'    => $node->is_primary,
                ];
            })
            ->values()
            ->all();
    }

    private static function publicNodeIp(Node $node): ?string
    {
        if (self::isPublicIp($node->ip_address)) {
            return $node->ip_address;
        }

        if ($node->hostname) {
            $resolved = gethostbyname($node->hostname);
            if ($resolved !== $node->hostname && self::isPublicIp($resolved)) {
                return $resolved;
            }
        }

        return $node->ip_address;
    }

    private static function isPublicIp(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function licenseTransportAllowed(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return true;
        }

        return (bool) config('strata.license_allow_insecure_transport');
    }
}
