<?php
/**
 * Execute only inside an isolated WordPress + WooCommerce QA install:
 * php tests/license-provider-integration.php /absolute/path/to/wp-load.php
 */

$bootstrap = $argv[1] ?? '';
if (!is_string($bootstrap) || !is_file($bootstrap)) {
    fwrite(STDERR, "Usage: php tests/license-provider-integration.php /path/to/wp-load.php\n");
    exit(2);
}

require $bootstrap;

use Whop\WooCommerce\Licensing\LicenseEncryption;
use Whop\WooCommerce\Licensing\LicenseCache;
use Whop\WooCommerce\Licensing\LicenseManager;
use Whop\WooCommerce\Licensing\LicenseStorage;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;
use Whop\WooCommerce\Licensing\Providers\SaaSLicenseProvider;
use Whop\WooCommerce\Licensing\UpdatesHandler;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$licenseKey = 'LICENSE-QA-0123456789-ABCDEFGHIJK';
$captured = [];

add_filter('whop_wc_license_api_base_url', static function (): string {
    return 'http://localhost:9010';
});

add_filter('pre_http_request', static function ($preempt, array $args, string $url) use (&$captured): array {
    $captured[] = ['url' => $url, 'body' => $args['body'] ?? ''];
    $path = (string) wp_parse_url($url, PHP_URL_PATH);

    $payload = ['success' => true, 'data' => []];
    if ($path === '/api/licenses/activate') {
        $payload['data'] = [
            'activation' => ['id' => 'activation-qa-1', 'domain' => 'example.test'],
            'license' => [
                'status' => 'VALID',
                'plan' => 'PROFESSIONAL',
                'coreActive' => true,
                'updatesActive' => true,
                'supportActive' => true,
                'updatesUntil' => '2030-01-01T00:00:00.000Z',
                'supportUntil' => '2030-01-01T00:00:00.000Z',
                'sitesUsed' => 1,
                'siteLimit' => 5,
                'unlimitedSites' => false,
            ],
        ];
    } elseif ($path === '/api/licenses/validate') {
        $payload['data'] = [
            'license' => [
                'status' => 'VALID',
                'plan' => 'PROFESSIONAL',
                'coreActive' => true,
                'updatesActive' => false,
                'supportActive' => false,
                'updatesUntil' => '2020-01-01T00:00:00.000Z',
                'supportUntil' => '2020-01-01T00:00:00.000Z',
                'sitesUsed' => 1,
                'siteLimit' => 5,
                'unlimitedSites' => false,
            ],
        ];
    } elseif ($path === '/api/plugins/latest') {
        $payload['data'] = [
            'coreActive' => true,
            'updatesActive' => false,
            'supportActive' => false,
            'updatesUntil' => '2020-01-01T00:00:00.000Z',
            'supportUntil' => '2020-01-01T00:00:00.000Z',
            'updateAvailable' => false,
            'packageUrl' => null,
            'reason' => 'UPDATES_EXPIRED',
        ];
    } elseif ($path === '/api/plugins/update-grant') {
        $payload['data'] = [
            'downloadUrl' => '/api/plugins/download/file/opaque-grant-token',
            'version' => '0.1.58',
        ];
    } elseif ($path === '/api/licenses/plugin/deactivate') {
        $payload['data'] = ['activation' => ['id' => 'activation-qa-1']];
    } else {
        $payload = ['success' => false, 'error' => 'Unexpected local test request'];
    }

    return [
        'headers' => ['content-type' => 'application/json'],
        'body' => wp_json_encode($payload),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

$encrypted = LicenseEncryption::encrypt($licenseKey);
$assert(str_starts_with($encrypted, 'v1:'), 'The new at-rest license format must be versioned.');
$assert($encrypted !== $licenseKey, 'The at-rest value must not equal the license key.');
$assert(LicenseEncryption::decrypt($encrypted) === $licenseKey, 'The new encrypted value must decrypt locally.');
$assert(LicenseEncryption::decrypt(base64_encode('historical-hash')) === '', 'Legacy non-decryptable values must fail closed.');

$storage = new LicenseStorage();
$storage->deleteLicenseData();
LicenseCache::clear();
$provider = new SaaSLicenseProvider();
$manager = new LicenseManager($provider, $storage);
$assert($manager->isPremiumFeatureEnabled() === false, 'Premium gateways must be disabled before activation.');

$activation = $manager->activateLicense($licenseKey);
$assert(($activation['status'] ?? '') === 'success', 'Activation must succeed with a valid SaaS response.');
$assert($manager->isPremiumFeatureEnabled() === true, 'Premium gateways must enable only after activation.');
$storedRaw = get_option('whop_wc_license_data', []);
$assert(is_array($storedRaw) && !str_contains((string) ($storedRaw['license_key'] ?? ''), $licenseKey), 'The WordPress option must not contain the raw license key.');
$info = $manager->getLicenseInfo();
$assert(($info['license_key'] ?? '') === $licenseKey, 'The manager must recover the key only in process for provider calls.');
$assert(($info['site_limit'] ?? null) === 5, 'The Professional site limit must come from the SaaS response.');

$validation = $manager->checkLicense();
$assert(($validation['status'] ?? '') === 'active', 'Core remains active after annual updates/support expire.');
$validationData = $validation['data'] ?? [];
$assert(($validationData['updates_active'] ?? true) === false, 'Expired updates must be represented separately.');
$assert(($validationData['support_active'] ?? true) === false, 'Expired support must be represented separately.');

$updates = $manager->checkForUpdates();
$assert(($updates['status'] ?? '') === 'success', 'Update entitlement check should return successfully.');
$assert(($updates['data']['update_available'] ?? true) === false, 'No update may be announced when annual updates are expired.');

$package = $manager->getPackageForUpdate('0.1.58');
$assert(($package['status'] ?? '') === 'success', 'A private package grant request should return an opaque package URL.');
$assert(str_contains((string) ($package['data']['package'] ?? ''), '/api/plugins/download/file/opaque-grant-token'), 'The package URL must contain the opaque grant route.');

$deactivation = $manager->deactivateLicense();
$assert(($deactivation['status'] ?? '') === 'success', 'Deactivation must be server-confirmed before local state is removed.');
$assert($storage->getLicenseData() === [], 'Deactivation must remove local encrypted license state only after confirmation.');
$assert($manager->isPremiumFeatureEnabled() === false, 'Premium gateways must close after deactivation.');

$updateManager = new class implements ILicenseManager {
    public function activateLicense(string $licenseKey): array { return []; }
    public function deactivateLicense(): array { return []; }
    public function checkLicense(): array { return []; }
    public function checkForUpdates(): array {
        return ['status' => 'success', 'data' => ['new_version' => '0.1.59-rc1', 'update_available' => true]];
    }
    public function getLicenseInfo(): array { return ['license_key' => 'local-only', 'status' => 'active']; }
    public function getPackageForUpdate(string $version): array { return []; }
    public function isPremiumFeatureEnabled(): bool { return true; }
};
$transient = (object) ['checked' => [WHOP_WOOCOMMERCE_BASENAME => '0.1.57']];
$updatedTransient = (new UpdatesHandler($updateManager))->checkForUpdates($transient);
$updateEntry = $updatedTransient->response[WHOP_WOOCOMMERCE_BASENAME] ?? null;
$assert(is_object($updateEntry) && ($updateEntry->new_version ?? '') === '0.1.59-rc1', 'Native updater must advertise the eligible RC version.');
$assert(is_object($updateEntry) && ($updateEntry->package ?? '') === 'whop-saas://0.1.59-rc1', 'Native updater must retain only an internal package sentinel.');

foreach ($captured as $request) {
    $assert(!str_contains($request['url'], $licenseKey), 'No license key may appear in any provider URL.');
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf("PASS: %d provider contract assertions\n", 20);
