<?php

namespace Whop\WooCommerce\Licensing;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;

/**
 * Class UpdatesHandler
 * Integrates plugin updates with WordPress native update system for licensed users.
 * @package Whop\WooCommerce\Licensing
 */
class UpdatesHandler
{
    private $licenseManager;

    public function __construct(ILicenseManager $licenseManager)
    {
        $this->licenseManager = $licenseManager;
    }

    public function registerHooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdates']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);
    }

    public function checkForUpdates($transient)
    {
        if (!isset($transient->checked)) {
            return $transient;
        }

        $licenseInfo = $this->licenseManager->getLicenseInfo();
        if (empty($licenseInfo['license_key']) || $licenseInfo['status'] !== 'active') {
            return $transient;
        }

        $updateInfo = $this->licenseManager->checkForUpdates();
        if ($updateInfo['status'] === 'success' && isset($updateInfo['data']['new_version'])) {
            $newVersion = $updateInfo['data']['new_version'];
            $currentVersion = WHOP_WOOCOMMERCE_VERSION;

            if (version_compare($newVersion, $currentVersion, '>')) {
                $transient->response[WHOP_WOOCOMMERCE_BASENAME] = (object) [
                    'id' => WHOP_WOOCOMMERCE_BASENAME,
                    'slug' => 'whop-woocommerce-checkout',
                    'plugin' => WHOP_WOOCOMMERCE_BASENAME,
                    'new_version' => $newVersion,
                    'url' => 'https://whop.com',
                    'package' => $updateInfo['data']['package'] ?? '',
                    'tested' => '6.4',
                    'requires_php' => '8.2',
                ];
            }
        }

        return $transient;
    }

    public function pluginInfo($result, $action, $args)
    {
        if ($action !== 'plugin_information' || $args->slug !== 'whop-woocommerce-checkout') {
            return $result;
        }

        $licenseInfo = $this->licenseManager->getLicenseInfo();
        if (empty($licenseInfo['license_key']) || $licenseInfo['status'] !== 'active') {
            return $result;
        }

        return (object) [
            'name' => 'Whop WooCommerce Checkout',
            'slug' => 'whop-woocommerce-checkout',
            'version' => WHOP_WOOCOMMERCE_VERSION,
            'author' => 'Whop',
            'homepage' => 'https://whop.com',
            'description' => 'Professional checkout integration for Whop.',
            'requires' => '5.0',
            'requires_php' => '8.2',
            'tested' => '6.4',
        ];
    }
}
