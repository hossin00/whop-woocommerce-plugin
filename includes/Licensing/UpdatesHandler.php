<?php

namespace Whop\WooCommerce\Licensing;

use WP_Error;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;

/**
 * Integrates entitlement-aware updates with the native WordPress updater.
 */
final class UpdatesHandler
{
    private const PACKAGE_SCHEME = 'whop-saas://';

    private ILicenseManager $licenseManager;

    public function __construct(ILicenseManager $licenseManager)
    {
        $this->licenseManager = $licenseManager;
    }

    public function registerHooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdates']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'downloadPrivatePackage'], 10, 4);
    }

    public function checkForUpdates($transient)
    {
        if (!isset($transient->checked)) {
            return $transient;
        }

        $licenseInfo = $this->licenseManager->getLicenseInfo();
        if (empty($licenseInfo['license_key']) || ($licenseInfo['status'] ?? '') !== 'active') {
            return $transient;
        }

        $updateInfo = $this->licenseManager->checkForUpdates();
        $data = is_array($updateInfo['data'] ?? null) ? $updateInfo['data'] : [];
        $newVersion = isset($data['new_version']) && is_string($data['new_version']) ? $data['new_version'] : '';

        if (
            ($updateInfo['status'] ?? '') === 'success'
            && !empty($data['update_available'])
            && $newVersion !== ''
            && version_compare($newVersion, WHOP_WOOCOMMERCE_VERSION, '>')
        ) {
            $transient->response[WHOP_WOOCOMMERCE_BASENAME] = (object) [
                'id' => WHOP_WOOCOMMERCE_BASENAME,
                'slug' => 'whop-woocommerce-checkout',
                'plugin' => WHOP_WOOCOMMERCE_BASENAME,
                'new_version' => $newVersion,
                'url' => 'https://woocommerce-whop-saas.vercel.app',
                // The real package URL is minted only by downloadPrivatePackage().
                'package' => self::PACKAGE_SCHEME . rawurlencode($newVersion),
                'tested' => '6.5',
                'requires_php' => '8.2',
            ];
        }

        return $transient;
    }

    /**
     * Converts an internal sentinel into a just-in-time opaque grant and returns
     * the downloaded temporary file expected by WordPress' upgrader.
     *
     * @param mixed $reply
     * @param string $package
     * @param mixed $upgrader
     * @param array $hookExtra
     * @return mixed
     */
    public function downloadPrivatePackage($reply, string $package, $upgrader, array $hookExtra = [])
    {
        if ($reply !== false || strpos($package, self::PACKAGE_SCHEME) !== 0) {
            return $reply;
        }

        $version = rawurldecode(substr($package, strlen(self::PACKAGE_SCHEME)));
        if ($version === '' || !preg_match('/^[0-9A-Za-z._-]+$/', $version)) {
            return new WP_Error('whop_update_invalid_version', __('The requested update version is invalid.', 'whop-woocommerce'));
        }

        $grant = $this->licenseManager->getPackageForUpdate($version);
        $downloadUrl = $grant['data']['package'] ?? null;
        if (($grant['status'] ?? '') !== 'success' || !is_string($downloadUrl) || $downloadUrl === '') {
            return new WP_Error('whop_update_not_authorized', __('The private update package is not authorized for this license.', 'whop-woocommerce'));
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $download = download_url($downloadUrl, 60, false);
        if (is_wp_error($download)) {
            return new WP_Error('whop_update_download_failed', __('The private update package could not be downloaded.', 'whop-woocommerce'));
        }

        $expectedSha256 = strtolower((string) ($grant['data']['artifact_sha256'] ?? ''));
        $actualSha256 = is_file($download) ? hash_file('sha256', $download) : false;
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedSha256) || !is_string($actualSha256) || !hash_equals($expectedSha256, $actualSha256)) {
            if (is_file($download)) {
                @unlink($download);
            }
            return new WP_Error('whop_update_integrity_failed', __('The private update package failed integrity verification.', 'whop-woocommerce'));
        }

        return $download;
    }

    public function pluginInfo($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'whop-woocommerce-checkout') {
            return $result;
        }

        $licenseInfo = $this->licenseManager->getLicenseInfo();
        if (empty($licenseInfo['license_key']) || ($licenseInfo['status'] ?? '') !== 'active') {
            return $result;
        }

        return (object) [
            'name' => 'Whop WooCommerce Checkout',
            'slug' => 'whop-woocommerce-checkout',
            'version' => WHOP_WOOCOMMERCE_VERSION,
            'author' => 'Whop',
            'homepage' => 'https://woocommerce-whop-saas.vercel.app',
            'description' => 'Professional checkout integration for Whop.',
            'requires' => '6.0',
            'requires_php' => '8.2',
            'tested' => '6.5',
        ];
    }
}
