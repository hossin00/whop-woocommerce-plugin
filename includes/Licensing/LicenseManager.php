<?php

namespace Whop\WooCommerce\Licensing;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseProvider;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseStorage;
use Whop\WooCommerce\Licensing\LicenseCache;

/**
 * Class LicenseManager
 * High-level licensing logic. Orchestrates between storage and the current provider.
 * @package Whop\WooCommerce\Licensing
 */
class LicenseManager implements ILicenseManager
{
    /**
     * @var ILicenseProvider $provider The active licensing provider.
     */
    private $provider;

    /**
     * @var ILicenseStorage $storage Persistent storage for license data.
     */
    private $storage;

    /**
     * LicenseManager constructor.
     * @param ILicenseProvider $provider
     * @param ILicenseStorage $storage
     */
    public function __construct(ILicenseProvider $provider, ILicenseStorage $storage)
    {
        $this->provider = $provider;
        $this->storage = $storage;
    }

    /**
     * @inheritDoc
     */
    public function activateLicense(string $licenseKey): array
    {
        $instanceName = get_site_url();
        $response = $this->provider->activate($licenseKey, $instanceName);

        if (isset($response['success']) && $response['success']) {
            $licenseData = [
                'license_key' => $licenseKey,
                'instance_id' => $response['data']['instance_id'] ?? '',
                'status' => $response['data']['status'] ?? 'active',
                'license_type' => $response['data']['license_type'] ?? 'single',
                'support_expiration' => $response['data']['support_expiration'] ?? '',
            ];
            $this->storage->saveLicenseData($licenseData);
            $this->storage->saveLastCheck(time());
            return ['status' => 'success', 'message' => __('License activated successfully.', 'whop-woocommerce')];
        }

        return ['status' => 'error', 'message' => $response['message'] ?? __('Failed to activate license.', 'whop-woocommerce')];
    }

    /**
     * @inheritDoc
     */
    public function deactivateLicense(): array
    {
        $licenseData = $this->storage->getLicenseData();
        if (empty($licenseData['instance_id'])) {
            return ['status' => 'error', 'message' => __('No active license to deactivate.', 'whop-woocommerce')];
        }

        $response = $this->provider->deactivate($licenseData['instance_id']);

        if (isset($response['success']) && $response['success']) {
            $this->storage->deleteLicenseData();
            return ['status' => 'success', 'message' => __('License deactivated successfully.', 'whop-woocommerce')];
        }

        return ['status' => 'error', 'message' => $response['message'] ?? __('Failed to deactivate license.', 'whop-woocommerce')];
    }

    /**
     * @inheritDoc
     */
    public function checkLicense(): array
    {
        $licenseData = $this->storage->getLicenseData();
        if (empty($licenseData['license_key']) || empty($licenseData['instance_id'])) {
            return ['status' => 'inactive', 'message' => __('No license key found.', 'whop-woocommerce')];
        }

        $response = $this->provider->validate($licenseData['license_key'], $licenseData['instance_id']);

        if (isset($response['success']) && $response['success']) {
            $licenseData['status'] = $response['data']['status'] ?? 'active';
            $licenseData['license_type'] = $response['data']['license_type'] ?? $licenseData['license_type'];
            $licenseData['support_expiration'] = $response['data']['support_expiration'] ?? $licenseData['support_expiration'];
            $this->storage->saveLicenseData($licenseData);
            $this->storage->saveLastCheck(time());
            LicenseCache::set($licenseData);
            LicenseCache::updateLastCheck();
            return ['status' => $licenseData['status'], 'message' => __('License status updated.', 'whop-woocommerce'), 'data' => $licenseData];
        }

        // Handle API failure with Grace Period
        if (LicenseCache::isWithinGracePeriod()) {
            $cachedData = LicenseCache::get();
            if ($cachedData) {
                return ['status' => $cachedData['status'], 'message' => __('License status (Cached/Grace Period).', 'whop-woocommerce'), 'data' => $cachedData];
            }
        }

        $licenseData['status'] = 'invalid';
        $this->storage->saveLicenseData($licenseData);
        return ['status' => 'invalid', 'message' => $response['message'] ?? __('Failed to validate license.', 'whop-woocommerce'), 'data' => $licenseData];
    }

    /**
     * @inheritDoc
     */
    public function checkForUpdates(): array
    {
        $licenseData = $this->storage->getLicenseData();
        if (empty($licenseData['license_key']) || empty($licenseData['instance_id'])) {
            return ['status' => 'error', 'message' => __('No active license to check for updates.', 'whop-woocommerce')];
        }

        $currentVersion = defined('WHOP_WOOCOMMERCE_VERSION') ? WHOP_WOOCOMMERCE_VERSION : '0.1.0';
        $response = $this->provider->checkForUpdates($licenseData['license_key'], $licenseData['instance_id'], $currentVersion);

        if (isset($response['success']) && $response['success']) {
            return ['status' => 'success', 'message' => __('Update check complete.', 'whop-woocommerce'), 'data' => $response['data']];
        }

        return ['status' => 'error', 'message' => $response['message'] ?? __('Failed to check for updates.', 'whop-woocommerce')];
    }

    /**
     * @inheritDoc
     */
    public function getLicenseInfo(): array
    {
        $licenseData = $this->storage->getLicenseData();
        $lastCheck = $this->storage->getLastCheck();

        return array_merge($licenseData, [
            'last_check' => $lastCheck,
            'current_version' => defined('WHOP_WOOCOMMERCE_VERSION') ? WHOP_WOOCOMMERCE_VERSION : '0.1.0',
            'updates_status' => __('Unknown', 'whop-woocommerce'),
        ]);
    }
}
