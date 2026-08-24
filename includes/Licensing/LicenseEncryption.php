<?php

namespace Whop\WooCommerce\Licensing;

/**
 * Encrypts a local plugin license key at rest.
 *
 * The key material is derived from WordPress salts that are never sent to the
 * SaaS. Values from the historical base64/hash implementation cannot be
 * decrypted and intentionally return an empty value, requiring a safe manual
 * reactivation rather than pretending that a hash is a usable secret.
 */
final class LicenseEncryption
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $data): string
    {
        if ($data === '' || !function_exists('openssl_encrypt')) {
            return '';
        }

        try {
            $iv = random_bytes(12);
        } catch (\Exception $exception) {
            return '';
        }

        $tag = '';
        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if (!is_string($encrypted) || $tag === '') {
            return '';
        }

        return self::PREFIX . base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $encrypted): string
    {
        if (!str_starts_with($encrypted, self::PREFIX) || !function_exists('openssl_decrypt')) {
            return '';
        }

        $decoded = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if (!is_string($decoded) || strlen($decoded) <= 28) {
            return '';
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return is_string($decrypted) ? $decrypted : '';
    }

    public static function hash(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    public static function verify(string $data, string $hash): bool
    {
        return hash_equals(self::hash($data), $hash);
    }

    private static function key(): string
    {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : '';
        $material = $salt . '|' . (defined('AUTH_KEY') ? AUTH_KEY : '') . '|whop-woocommerce-license-v1';

        return hash('sha256', $material, true);
    }
}
