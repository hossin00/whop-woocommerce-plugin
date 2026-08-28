<?php

/**
 * Standalone regression test for license cache and activation-response safety.
 *
 * Run with: php tests/license-cache-security.php
 */

$options = [];
$clock = 2_000_000_000;
$failures = [];
$assertions = 0;

function update_option(string $key, mixed $value): bool
{
    global $options;
    $options[$key] = $value;
    return true;
}

function get_option(string $key, mixed $default = false): mixed
{
    global $options;
    return $options[$key] ?? $default;
}

function delete_option(string $key): bool
{
    global $options;
    unset($options[$key]);
    return true;
}

function apply_filters(string $hook, mixed $value): mixed
{
    global $clock;
    return $hook === 'whop_wc_license_cache_now' ? $clock : $value;
}

function __(string $message, string $domain = ''): string
{
    return $message;
}

function wp_strip_all_tags(string $message): string
{
    return strip_tags($message);
}

function home_url(string $path = ''): string
{
    return 'https://store.example.test' . $path;
}

function get_site_url(): string
{
    return 'https://store.example.test';
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

require_once __DIR__ . '/../includes/Licensing/Interfaces/ILicenseProvider.php';
require_once __DIR__ . '/../includes/Licensing/Interfaces/ILicenseStorage.php';
require_once __DIR__ . '/../includes/Licensing/Interfaces/ILicenseManager.php';
require_once __DIR__ . '/../includes/Licensing/LicenseCache.php';
require_once __DIR__ . '/../includes/Licensing/LicenseManager.php';

use Whop\WooCommerce\Licensing\Interfaces\ILicenseProvider;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseStorage;
use Whop\WooCommerce\Licensing\LicenseCache;
use Whop\WooCommerce\Licensing\LicenseManager;

final class SecurityTestProvider implements ILicenseProvider
{
    public bool $available = true;

    public function activate(string $licenseKey, string $instanceName): array
    {
        return [
            'success' => true,
            'data' => [
                'status' => 'active',
                'license_key' => $licenseKey,
                'instance_domain' => $instanceName,
                'site_limit' => 5,
                'nested' => ['licenseKey' => $licenseKey, 'plan' => 'professional'],
            ],
        ];
    }

    public function deactivate(string $instanceId): array
    {
        return ['success' => true];
    }

    public function validate(string $licenseKey, string $instanceId): array
    {
        if (!$this->available) {
            return ['success' => false, 'message' => '<b>Temporarily unavailable</b>'];
        }

        return ['success' => true, 'data' => ['status' => 'active', 'site_limit' => 5]];
    }

    public function checkForUpdates(string $licenseKey, string $instanceId, string $currentVersion): array
    {
        return ['success' => true, 'data' => []];
    }
}

final class SecurityTestStorage implements ILicenseStorage
{
    public array $data = [];
    public int $lastCheck = 0;

    public function saveLicenseData(array $data): bool
    {
        $this->data = $data;
        return true;
    }

    public function getLicenseData(): array
    {
        return $this->data;
    }

    public function deleteLicenseData(): bool
    {
        $this->data = [];
        return true;
    }

    public function saveLastCheck(int $timestamp): bool
    {
        $this->lastCheck = $timestamp;
        return true;
    }

    public function getLastCheck(): int
    {
        return $this->lastCheck;
    }
}

$rawKey = 'LICENSE-RC2-PLAINTEXT-MUST-NOT-PERSIST';

LicenseCache::set([
    'status' => 'active',
    'license_key' => $rawKey,
    'nested' => ['licenseKey' => $rawKey, 'plan' => 'professional'],
]);

$storedCache = get_option('whop_wc_license_cache', []);
$serializedCache = serialize($storedCache);
$assert(!str_contains($serializedCache, $rawKey), 'New cache writes must not contain the raw license key.');
$assert(!array_key_exists('license_key', $storedCache['data'] ?? []), 'Top-level license_key must be removed.');
$assert(!array_key_exists('licenseKey', $storedCache['data']['nested'] ?? []), 'Nested licenseKey must be removed.');
$assert(($storedCache['data']['nested']['plan'] ?? '') === 'professional', 'Non-secret nested cache data must be preserved.');
$assert(($storedCache['timestamp'] ?? 0) === $clock, 'Cache writes must use the deterministic clock hook.');
$assert(LicenseCache::isValid(), 'A newly written cache must be valid.');

$clock += 86400;
$assert(!LicenseCache::isValid(), 'The cache must expire at the exact 24-hour boundary.');

$legacyTimestamp = $clock - 100;
update_option('whop_wc_license_cache', [
    'data' => [
        'status' => 'active',
        'license_key' => $rawKey,
        'nested' => ['raw_license_key' => $rawKey, 'sites_used' => 1],
    ],
    'timestamp' => $legacyTimestamp,
]);

$migrated = LicenseCache::get();
$migratedStored = get_option('whop_wc_license_cache', []);
$assert(!str_contains(serialize($migrated), $rawKey), 'Legacy cache reads must never return the raw key.');
$assert(!str_contains(serialize($migratedStored), $rawKey), 'Legacy cache entries must be migrated in place.');
$assert(($migratedStored['timestamp'] ?? 0) === $legacyTimestamp, 'Legacy migration must preserve the original timestamp.');
$assert(($migrated['status'] ?? '') === 'active', 'Legacy migration must preserve license status.');
$assert(($migrated['nested']['sites_used'] ?? 0) === 1, 'Legacy migration must preserve non-secret entitlement data.');

LicenseCache::updateLastCheck();
$assert(get_option('whop_wc_license_last_check') === $clock, 'Last-check writes must use the deterministic clock.');
$assert(LicenseCache::isWithinGracePeriod(), 'Grace must be active immediately after a successful check.');

$clock += 604799;
$assert(LicenseCache::isWithinGracePeriod(), 'Grace must remain active one second before the seven-day boundary.');
$clock += 1;
$assert(!LicenseCache::isWithinGracePeriod(), 'Grace must end at the exact seven-day boundary.');

$clock = 2_100_000_000;
LicenseCache::clear();
$provider = new SecurityTestProvider();
$storage = new SecurityTestStorage();
$manager = new LicenseManager($provider, $storage);
$activation = $manager->activateLicense($rawKey);

$assert(($activation['status'] ?? '') === 'success', 'Activation must succeed in the regression fixture.');
$assert(!str_contains(serialize($activation), $rawKey), 'Activation responses must not expose the raw license key.');
$assert(!str_contains(serialize(get_option('whop_wc_license_cache', [])), $rawKey), 'Activation must not persist the raw key in cache.');
$assert(($storage->data['license_key'] ?? '') === $rawKey, 'The manager must retain the key only in its protected primary storage contract.');

$provider->available = false;
$offline = $manager->checkLicense();
$assert(($offline['status'] ?? '') === 'active', 'An active license must remain active inside offline grace.');
$assert(!str_contains(serialize($offline), $rawKey), 'Offline-grace responses must not expose the raw key.');

$clock += 604800;
$outsideGrace = $manager->checkLicense();
$assert(($outsideGrace['status'] ?? '') === 'unavailable', 'An unreachable service outside grace must return unavailable.');
$assert(!str_contains(serialize($outsideGrace), $rawKey), 'Outside-grace responses must not expose the raw key.');

LicenseCache::clear();
$assert(get_option('whop_wc_license_cache', null) === null, 'Cache clear must remove the cached entitlement.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf("PASS: %d license cache security assertions\n", $assertions);
