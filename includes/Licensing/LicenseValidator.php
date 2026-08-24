<?php

namespace Whop\WooCommerce\Licensing;

/**
 * Class LicenseValidator
 * Placeholder for validating license data locally.
 * @package WhopWooCommerce\Includes\Licensing
 */
class LicenseValidator
{
    /**
     * Validates the format and basic integrity of a license key.
     * @param string $licenseKey The license key to validate.
     * @return bool True if the key format is valid, false otherwise.
     */
    public function isValidFormat(string $licenseKey): bool
    {
        // Lemon Squeezy license keys are typically 36 characters (UUID-like)
        return !empty($licenseKey) && preg_match('/^[a-f0-9-]{36}$/i', $licenseKey);
    }

    /**
     * Checks if the license is currently active based on stored data.
     * @param array $licenseData The stored license data.
     * @return bool True if active, false otherwise.
     */
    public function isActive(array $licenseData): bool
    {
        return isset($licenseData["status"]) && $licenseData["status"] === "active";
    }
}
