<?php

namespace Whop\WooCommerce\Licensing\Interfaces;

/**
 * Interface ILicenseProvider
 * Defines the contract for external licensing providers (e.g., Lemon Squeezy, Paddle).
 * @package Whop\WooCommerce\Licensing\Interfaces
 */
interface ILicenseProvider
{
    /**
     * Activates a license key with the provider.
     * @param string $licenseKey The license key.
     * @param string $instanceName The site identifier.
     * @return array The provider response.
     */
    public function activate(string $licenseKey, string $instanceName): array;

    /**
     * Deactivates a license instance with the provider.
     * @param string $instanceId The instance ID.
     * @return array The provider response.
     */
    public function deactivate(string $instanceId): array;

    /**
     * Validates a license key with the provider.
     * @param string $licenseKey The license key.
     * @param string $instanceId The instance ID.
     * @return array The provider response.
     */
    public function validate(string $licenseKey, string $instanceId): array;

    /**
     * Checks for updates with the provider.
     * @param string $licenseKey The license key.
     * @param string $instanceId The instance ID.
     * @param string $currentVersion Current plugin version.
     * @return array The update information.
     */
    public function checkForUpdates(string $licenseKey, string $instanceId, string $currentVersion): array;
}
