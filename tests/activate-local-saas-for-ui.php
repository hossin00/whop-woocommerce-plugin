<?php
/** Usage: php tests/activate-local-saas-for-ui.php /path/to/wp-load.php /tmp/credential.json */
$bootstrap = $argv[1] ?? '';
$credentialPath = $argv[2] ?? '';
if (!is_file($bootstrap) || !is_file($credentialPath)) {
    exit(2);
}
$credential = json_decode((string) file_get_contents($credentialPath), true);
if (!is_array($credential) || !is_string($credential['licenseKey'] ?? null)) {
    exit(2);
}
require $bootstrap;

use Whop\WooCommerce\Licensing\LicenseCache;
use Whop\WooCommerce\Licensing\LicenseManager;
use Whop\WooCommerce\Licensing\LicenseStorage;
use Whop\WooCommerce\Licensing\Providers\SaaSLicenseProvider;

add_filter('whop_wc_license_api_base_url', static function (): string {
    return 'http://127.0.0.1:8080';
});
$storage = new LicenseStorage();
$storage->deleteLicenseData();
LicenseCache::clear();
$manager = new LicenseManager(new SaaSLicenseProvider(), $storage);
$result = $manager->activateLicense($credential['licenseKey']);
@unlink($credentialPath);
if (($result['status'] ?? '') !== 'success') {
    fwrite(STDERR, "Local UI activation failed\n");
    exit(1);
}
printf("Local UI entitlement activated\n");
