<?php

namespace Whop\WooCommerce\Licensing;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseStorage;

/**
 * Stores license state in WordPress options while encrypting the key at rest.
 */
final class LicenseStorage implements ILicenseStorage
{
    private const OPTION_NAME = 'whop_wc_license_data';
    private const LAST_CHECK_OPTION_NAME = 'whop_wc_license_last_check';

    public function saveLicenseData(array $data): bool
    {
        if (isset($data['license_key'])) {
            $encrypted = LicenseEncryption::encrypt((string) $data['license_key']);
            if ($encrypted === '') {
                return false;
            }

            $data['license_key'] = $encrypted;
        }

        $existing = get_option(self::OPTION_NAME, null);
        if ($existing === $data) {
            return true;
        }

        return update_option(self::OPTION_NAME, $data, false);
    }

    public function getLicenseData(): array
    {
        $data = get_option(self::OPTION_NAME, []);
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['license_key'])) {
            $data['license_key'] = LicenseEncryption::decrypt((string) $data['license_key']);
        }

        return $data;
    }

    public function deleteLicenseData(): bool
    {
        return delete_option(self::OPTION_NAME);
    }

    public function saveLastCheck(int $timestamp): bool
    {
        return update_option(self::LAST_CHECK_OPTION_NAME, $timestamp, false);
    }

    public function getLastCheck(): int
    {
        return (int) get_option(self::LAST_CHECK_OPTION_NAME, 0);
    }
}
