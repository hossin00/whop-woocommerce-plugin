<?php

namespace Whop\WooCommerce\Licensing\Interfaces;

/**
 * Interface ILicenseManager
 * Defines the contract for managing plugin licenses.
 * @package WhopWooCommerce\Includes\Licensing\Interfaces
 */
interface ILicenseManager
{
    /**
     * Activates a license key.
     * @param string $licenseKey The license key to activate.
     * @return array An array containing the activation status and message.
     */
    public function activateLicense(string $licenseKey): array;

    /**
     * Deactivates an active license.
     * @return array An array containing the deactivation status and message.
     */
    public function deactivateLicense(): array;

    /**
     * Checks the current status of the license.
     * @return array An array containing the license status and details.
     */
    public function checkLicense(): array;

    /**
     * Checks for available plugin updates based on the license.
     * @return array An array containing update information.
     */
    public function checkForUpdates(): array;

    /**
     * Retrieves the current license information.
     * @return array An array containing detailed license information.
     */
    public function getLicenseInfo(): array;

    /**
     * Requests a short-lived, one-use package only at the native WordPress
     * updater execution point.
     *
     * @param string $version The published version requested by the updater.
     * @return array An array containing status and an opaque package URL.
     */
    public function getPackageForUpdate(string $version): array;

    /**
     * Indicates whether commercial gateway functionality is currently enabled
     * from a valid local state or bounded last-known-valid grace period.
     */
    public function isPremiumFeatureEnabled(): bool;
}
