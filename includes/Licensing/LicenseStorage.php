<?php

namespace Whop\WooCommerce\Licensing;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseStorage;
use Whop\WooCommerce\Licensing\LicenseEncryption;

/**
 * Class LicenseStorage
 * Handles storing and retrieving license data using WordPress options.
 * @package WhopWooCommerce\Includes\Licensing
 */
class LicenseStorage implements ILicenseStorage
{
    /**
     * The option name for storing license data.
     * @var string
     */
    private const OPTION_NAME = 'whop_wc_license_data';

    /**
     * The option name for storing the last check timestamp.
     * @var string
     */
    private const LAST_CHECK_OPTION_NAME = 'whop_wc_license_last_check';

    /**
     * Saves license data to persistent storage.
     * @param array $data The license data to save.
     * @return bool True on success, false on failure.
     */
    public function saveLicenseData(array $data): bool
    {
        if (isset($data['license_key'])) {
            $data['license_key'] = LicenseEncryption::encrypt($data['license_key']);
        }
        return update_option(self::OPTION_NAME, $data);
    }

    /**
     * Retrieves license data from persistent storage.
     * @return array The stored license data, or an empty array if not found.
     */
    public function getLicenseData(): array
    {
        $data = get_option(self::OPTION_NAME, []);
        if (isset($data['license_key'])) {
            $data['license_key'] = LicenseEncryption::decrypt($data['license_key']);
        }
        return $data;
    }

    /**
     * Deletes all stored license data.
     * @return bool True on success, false on failure.
     */
    public function deleteLicenseData(): bool
    {
        return delete_option(self::OPTION_NAME);
    }

    /**
     * Saves the last checked timestamp.
     * @param int $timestamp The timestamp to save.
     * @return bool True on success, false on failure.
     */
    public function saveLastCheck(int $timestamp): bool
    {
        return update_option(self::LAST_CHECK_OPTION_NAME, $timestamp);
    }

    /**
     * Retrieves the last checked timestamp.
     * @return int The timestamp, or 0 if not found.
     */
    public function getLastCheck(): int
    {
        return (int) get_option(self::LAST_CHECK_OPTION_NAME, 0);
    }
}
