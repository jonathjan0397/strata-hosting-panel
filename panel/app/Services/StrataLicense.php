<?php

namespace App\Services;

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

        try {
            $response = Http::timeout(10)
                ->post(rtrim($url, '/') . '/api/ping', [
                    'install_token'  => $token,
                    'install_secret' => $secret,
                    'software'       => 'strata-panel',
                    'version'        => config('strata.version', 'dev'),
                    'app_url'        => config('app.url'),
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
}
