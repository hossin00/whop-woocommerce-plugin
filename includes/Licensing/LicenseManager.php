<?php

namespace Whop\WooCommerce\Licensing;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseProvider;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseStorage;
use Whop\WooCommerce\Licensing\Providers\SaaSLicenseProvider;

/**
 * Coordinates local encrypted storage and the active SaaS provider.
 */
final class LicenseManager implements ILicenseManager
{
    private ILicenseProvider $provider;
    private ILicenseStorage $storage;

    public function __construct(ILicenseProvider $provider, ILicenseStorage $storage)
    {
        $this->provider = $provider;
        $this->storage = $storage;
    }

    public function activateLicense(string $licenseKey): array
    {
        $domain = $this->instanceDomain();
        $response = $this->provider->activate($licenseKey, $domain);

        if (!($response['success'] ?? false)) {
            return ['status' => 'error', 'message' => $this->message($response, __('Failed to activate license.', 'whop-woocommerce'))];
        }

        $remote = is_array($response['data'] ?? null) ? $response['data'] : [];
        $licenseData = array_merge($remote, [
            'license_key' => $licenseKey,
            'instance_domain' => $domain,
            'instance_id' => (string) ($remote['instance_id'] ?? ''),
            'status' => (string) ($remote['status'] ?? 'inactive'),
        ]);

        if ($licenseData['status'] !== 'active' || !$this->storage->saveLicenseData($licenseData)) {
            return ['status' => 'error', 'message' => __('License activation could not be stored securely.', 'whop-woocommerce')];
        }

        $this->storage->saveLastCheck(time());
        LicenseCache::set($licenseData);
        LicenseCache::updateLastCheck();

        return ['status' => 'success', 'message' => __('License activated successfully.', 'whop-woocommerce'), 'data' => $licenseData];
    }

    public function deactivateLicense(): array
    {
        $licenseData = $this->storage->getLicenseData();
        $licenseKey = (string) ($licenseData['license_key'] ?? '');
        $domain = (string) ($licenseData['instance_domain'] ?? $this->instanceDomain());

        if ($licenseKey === '') {
            return ['status' => 'error', 'message' => __('No active license to deactivate.', 'whop-woocommerce')];
        }

        if (!$this->provider instanceof SaaSLicenseProvider) {
            return ['status' => 'error', 'message' => __('The configured license provider does not support safe deactivation.', 'whop-woocommerce')];
        }

        $response = $this->provider->deactivateForDomain($licenseKey, $domain);
        if (!($response['success'] ?? false)) {
            return ['status' => 'error', 'message' => $this->message($response, __('Failed to deactivate license.', 'whop-woocommerce'))];
        }

        $this->storage->deleteLicenseData();
        LicenseCache::clear();

        return ['status' => 'success', 'message' => __('License deactivated successfully.', 'whop-woocommerce')];
    }

    public function checkLicense(): array
    {
        $licenseData = $this->storage->getLicenseData();
        $licenseKey = (string) ($licenseData['license_key'] ?? '');
        $domain = (string) ($licenseData['instance_domain'] ?? $this->instanceDomain());

        if ($licenseKey === '') {
            return ['status' => 'inactive', 'message' => __('No license key found.', 'whop-woocommerce')];
        }

        $response = $this->provider->validate($licenseKey, $domain);
        if (!($response['success'] ?? false)) {
            if (LicenseCache::isWithinGracePeriod()) {
                $cached = LicenseCache::get();
                if (is_array($cached) && ($cached['status'] ?? '') === 'active') {
                    return [
                        'status' => 'active',
                        'message' => __('License status is cached during the offline grace period.', 'whop-woocommerce'),
                        'data' => $cached,
                    ];
                }
            }

            // Do not convert a still-active license into invalid merely because the
            // service is unreachable. The caller can present a neutral retry state.
            return [
                'status' => 'unavailable',
                'message' => __('The license service is unavailable. Please try again later.', 'whop-woocommerce'),
                'data' => $this->withoutKey($licenseData),
            ];
        }

        $remote = is_array($response['data'] ?? null) ? $response['data'] : [];
        $licenseData = array_merge($licenseData, $remote, [
            'status' => (string) ($remote['status'] ?? 'inactive'),
            'instance_domain' => $domain,
        ]);

        if (!$this->storage->saveLicenseData($licenseData)) {
            return ['status' => 'error', 'message' => __('License status could not be stored securely.', 'whop-woocommerce')];
        }

        $this->storage->saveLastCheck(time());
        LicenseCache::set($licenseData);
        LicenseCache::updateLastCheck();

        return [
            'status' => $licenseData['status'],
            'message' => __('License status updated.', 'whop-woocommerce'),
            'data' => $this->withoutKey($licenseData),
        ];
    }

    public function checkForUpdates(): array
    {
        $licenseData = $this->storage->getLicenseData();
        $licenseKey = (string) ($licenseData['license_key'] ?? '');
        $domain = (string) ($licenseData['instance_domain'] ?? $this->instanceDomain());

        if ($licenseKey === '' || ($licenseData['status'] ?? '') !== 'active') {
            return ['status' => 'error', 'message' => __('No active license to check for updates.', 'whop-woocommerce')];
        }

        $currentVersion = defined('WHOP_WOOCOMMERCE_VERSION') ? WHOP_WOOCOMMERCE_VERSION : '0.1.0';
        $response = $this->provider->checkForUpdates($licenseKey, $domain, $currentVersion);
        if (!($response['success'] ?? false)) {
            return ['status' => 'error', 'message' => $this->message($response, __('Failed to check for updates.', 'whop-woocommerce'))];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $licenseData = array_merge($licenseData, [
            'updates_active' => (bool) ($data['updates_active'] ?? false),
            'support_active' => (bool) ($data['support_active'] ?? false),
            'updates_expiration' => (string) ($data['updates_until'] ?? ($licenseData['updates_expiration'] ?? '')),
            'support_expiration' => (string) ($data['support_until'] ?? ($licenseData['support_expiration'] ?? '')),
        ]);
        $this->storage->saveLicenseData($licenseData);

        return ['status' => 'success', 'message' => __('Update check complete.', 'whop-woocommerce'), 'data' => $data];
    }

    public function getLicenseInfo(): array
    {
        $licenseData = $this->storage->getLicenseData();
        $lastCheck = $this->storage->getLastCheck();

        return array_merge($this->withoutKey($licenseData), [
            'license_key' => (string) ($licenseData['license_key'] ?? ''),
            'last_check' => $lastCheck,
            'current_version' => defined('WHOP_WOOCOMMERCE_VERSION') ? WHOP_WOOCOMMERCE_VERSION : '0.1.0',
            'updates_status' => !empty($licenseData['updates_active']) ? __('Active', 'whop-woocommerce') : __('Unavailable', 'whop-woocommerce'),
        ]);
    }

    public function getPackageForUpdate(string $version): array
    {
        $licenseData = $this->storage->getLicenseData();
        $licenseKey = (string) ($licenseData['license_key'] ?? '');
        $domain = (string) ($licenseData['instance_domain'] ?? $this->instanceDomain());

        if ($licenseKey === '' || !$this->provider instanceof SaaSLicenseProvider) {
            return ['status' => 'error', 'message' => __('No active license is available for this update.', 'whop-woocommerce')];
        }

        $response = $this->provider->requestPackage($licenseKey, $domain, $version);
        if (!($response['success'] ?? false)) {
            return ['status' => 'error', 'message' => $this->message($response, __('The private update package is unavailable.', 'whop-woocommerce'))];
        }

        return ['status' => 'success', 'data' => $response['data'] ?? []];
    }

    public function isPremiumFeatureEnabled(): bool
    {
        $licenseData = $this->storage->getLicenseData();
        if (($licenseData['status'] ?? '') === 'active' && !empty($licenseData['license_key'])) {
            return true;
        }

        if (!LicenseCache::isWithinGracePeriod()) {
            return false;
        }

        $cached = LicenseCache::get();
        return is_array($cached) && ($cached['status'] ?? '') === 'active';
    }

    private function instanceDomain(): string
    {
        $url = function_exists('home_url') ? home_url('/') : get_site_url();
        $host = wp_parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    private function withoutKey(array $data): array
    {
        unset($data['license_key']);
        return $data;
    }

    private function message(array $response, string $fallback): string
    {
        $message = $response['message'] ?? $fallback;
        return is_string($message) && $message !== '' ? wp_strip_all_tags($message) : $fallback;
    }
}
