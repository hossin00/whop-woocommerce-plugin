<?php
/**
 * End-to-end test against a local SaaS server and a local WordPress install.
 * Usage: php tests/local-saas-e2e.php /path/to/wp-load.php /tmp/credential.json
 */

$bootstrap = $argv[1] ?? '';
$credentialPath = $argv[2] ?? '';
if (!is_file($bootstrap) || !is_file($credentialPath)) {
    fwrite(STDERR, "Usage: php tests/local-saas-e2e.php /path/to/wp-load.php /path/to/credential.json\n");
    exit(2);
}

$credential = json_decode((string) file_get_contents($credentialPath), true);
if (!is_array($credential) || empty($credential['licenseKey']) || empty($credential['version']) || empty($credential['sha256'])) {
    fwrite(STDERR, "Invalid local E2E credential fixture\n");
    exit(2);
}

require $bootstrap;

use Whop\WooCommerce\Licensing\LicenseManager;
use Whop\WooCommerce\Licensing\LicenseCache;
use Whop\WooCommerce\Licensing\LicenseStorage;
use Whop\WooCommerce\Licensing\Providers\SaaSLicenseProvider;
use Whop\WooCommerce\Licensing\UpdatesHandler;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

add_filter('whop_wc_license_api_base_url', static function (): string {
    return 'http://127.0.0.1:8080';
});

$storage = new LicenseStorage();
$storage->deleteLicenseData();
LicenseCache::clear();
$manager = new LicenseManager(new SaaSLicenseProvider(), $storage);
$assert($manager->isPremiumFeatureEnabled() === false, 'Premium gate must begin closed.');

$activation = $manager->activateLicense((string) $credential['licenseKey']);
$assert(($activation['status'] ?? '') === 'success', 'Real local SaaS activation must succeed.');
$assert($manager->isPremiumFeatureEnabled() === true, 'Premium gate must open after confirmed activation.');
$info = $manager->getLicenseInfo();
$assert(($info['license_type'] ?? '') === 'professional', 'Provider must return the server-side Professional plan.');
$assert(($info['site_limit'] ?? null) === 5, 'Provider must return the server-side five-site limit.');

$validation = $manager->checkLicense();
$assert(($validation['status'] ?? '') === 'active', 'Real local SaaS validation must return active.');

$provider = new SaaSLicenseProvider();
$updates = $provider->checkForUpdates((string) $credential['licenseKey'], '127.0.0.1', '0.1.57');
$assert(($updates['success'] ?? false) === true, 'Eligible update metadata check must succeed.');
$assert(($updates['data']['new_version'] ?? '') === (string) $credential['version'], 'Published RC version must be discovered.');
$assert(($updates['data']['update_available'] ?? false) === true, 'Published RC must be offered as an update.');

$handler = new UpdatesHandler($manager);
$download = $handler->downloadPrivatePackage(
    false,
    'whop-saas://' . rawurlencode((string) $credential['version']),
    null,
    []
);
$assert(is_string($download) && is_file($download), 'Native updater must download a temporary private package.');
if (is_string($download) && is_file($download)) {
    $assert(hash_file('sha256', $download) === (string) $credential['sha256'], 'Downloaded package SHA-256 must match release metadata.');
    @unlink($download);
}

$deactivation = $manager->deactivateLicense();
$assert(($deactivation['status'] ?? '') === 'success', 'Real local SaaS deactivation must be server-confirmed.');
$assert($manager->isPremiumFeatureEnabled() === false, 'Premium gate must close after server-confirmed deactivation.');

@unlink($credentialPath);
if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf("PASS: local WordPress ↔ SaaS E2E activation/update/grant/deactivation\n");
