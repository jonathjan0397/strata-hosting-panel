<?php
/**
 * Strata Panel — SnappyMail SSO Bridge
 *
 * Flow:
 *   1. Panel generates HMAC-signed token → stores {email, password} in Redis (60s TTL)
 *   2. User is redirected here: GET /webmail/sso.php?token=xxx
 *   3. This script validates the token, retrieves credentials, calls SnappyMail API
 *   4. Redirects to /?sso-hash=HASH (one-time SnappyMail autologin hash)
 *
 * Deployed to: /var/www/webmail/sso.php by installer
 * Config at:   /etc/strata-panel/webmail-sso.php
 */

declare(strict_types=1);

// ── Load config ────────────────────────────────────────────────────────────
$configPath = '/etc/strata-panel/webmail-sso.php';
if (! file_exists($configPath)) {
    http_response_code(503);
    exit('Webmail SSO not configured.');
}
$config = require $configPath;

// ── Validate token presence ────────────────────────────────────────────────
$token = isset($_GET['token']) ? preg_replace('/[^a-f0-9]/', '', $_GET['token']) : '';
if (strlen($token) !== 64) {
    sso_fail('Invalid token format.');
}

// ── Connect to Redis ───────────────────────────────────────────────────────
if (! class_exists('Redis')) {
    sso_fail('Redis extension not available.');
}

$redis = new Redis();
if (! @$redis->connect($config['redis_host'], (int) $config['redis_port'], 2.0)) {
    sso_fail('Redis connection failed.');
}
if (! empty($config['redis_password'])) {
    $redis->auth($config['redis_password']);
}
if ((int) $config['redis_db'] !== 0) {
    $redis->select((int) $config['redis_db']);
}

// ── Retrieve and consume token (one-time use) ──────────────────────────────
$redisKey = 'webmail_sso:' . $token;
$raw = $redis->get($redisKey);
if (! $raw) {
    sso_fail('Token expired or already used.');
}
$redis->del($redisKey);

$data = json_decode($raw, true);
if (! isset($data['email'], $data['password'], $data['hmac'])) {
    sso_fail('Malformed token payload.');
}

// ── Verify HMAC ────────────────────────────────────────────────────────────
$expected = hash_hmac('sha256', $data['email'] . '|' . $data['timestamp'], $config['hmac_secret']);
if (! hash_equals($expected, $data['hmac'])) {
    sso_fail('Token signature invalid.');
}

// ── Timestamp freshness (belt-and-suspenders over Redis TTL) ───────────────
if (abs(time() - (int) $data['timestamp']) > (int) $config['token_ttl']) {
    sso_fail('Token expired.');
}

// ── Bootstrap SnappyMail and create SSO hash ───────────────────────────────
$webmailRoot = rtrim($config['webmail_root'], '/');
$dataPath    = rtrim($config['data_path'], '/') . '/';

// Find the latest installed SnappyMail version
$versionDirs = glob($webmailRoot . '/snappymail/v/[0-9]*', GLOB_ONLYDIR);
if (empty($versionDirs)) {
    sso_fail('SnappyMail not found at ' . $webmailRoot);
}
usort($versionDirs, 'strnatcasecmp');
$latestDir = end($versionDirs);
$includeFile = $latestDir . '/app/include.php';

if (! file_exists($includeFile)) {
    sso_fail('SnappyMail include not found: ' . $includeFile);
}

if (! defined('APP_DATA_FOLDER_PATH')) {
    define('APP_DATA_FOLDER_PATH', $dataPath);
}

require_once $includeFile;

if (! class_exists('RainLoop\Api')) {
    sso_fail('SnappyMail API class not available.');
}

$ssoHash = \RainLoop\Api::CreateUserSsoHash(
    $data['email'],
    $data['password'],
    10  // expire in 10 minutes
);

if (! $ssoHash) {
    // Fall back to plain login page — user will need to enter credentials
    header('Location: /webmail/');
    exit;
}

header('Location: /webmail/?sso-hash=' . urlencode($ssoHash));
exit;

// ── Helper ─────────────────────────────────────────────────────────────────
function sso_fail(string $reason): never
{
    // Log to syslog but don't expose details to browser
    openlog('strata-webmail-sso', LOG_PID, LOG_AUTH);
    syslog(LOG_WARNING, 'SSO failed: ' . $reason);
    closelog();

    header('Location: /webmail/');
    exit;
}
