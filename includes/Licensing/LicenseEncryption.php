<?php

namespace Whop\WooCommerce\Licensing;

/**
 * Class LicenseEncryption
 * Handles encryption/decryption of sensitive license data.
 * @package Whop\WooCommerce\Licensing
 */
class LicenseEncryption
{
    private const ENCRYPTION_KEY = 'whop_wc_license_key';

    public static function encrypt(string $data): string
    {
        return base64_encode(wp_hash($data . WHOP_WOOCOMMERCE_VERSION));
    }

    public static function decrypt(string $encrypted): string
    {
        return base64_decode($encrypted);
    }

    public static function hash(string $data): string
    {
        return wp_hash($data);
    }

    public static function verify(string $data, string $hash): bool
    {
        return wp_hash($data) === $hash;
    }
}
