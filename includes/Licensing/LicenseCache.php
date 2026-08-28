<?php

namespace Whop\WooCommerce\Licensing;

/**
 * Class LicenseCache
 * Manages local caching and grace period for license validation.
 * @package Whop\WooCommerce\Licensing
 */
class LicenseCache
{
    private const CACHE_DURATION = 86400; // 24 hours
    private const GRACE_PERIOD = 604800; // 7 days
    private const CACHE_KEY = 'whop_wc_license_cache';
    private const LAST_CHECK_KEY = 'whop_wc_license_last_check';

    public static function set(array $licenseData): bool
    {
        return update_option(self::CACHE_KEY, [
            'data' => self::withoutLicenseKeys($licenseData),
            'timestamp' => self::now(),
        ]);
    }

    public static function get(): ?array
    {
        $cached = get_option(self::CACHE_KEY);
        if (!$cached || !isset($cached['data'], $cached['timestamp']) || !is_array($cached['data'])) {
            return null;
        }

        $safeData = self::withoutLicenseKeys($cached['data']);

        // Migrate a cache written by an older release without ever returning its
        // plaintext license key to callers. Preserve the original timestamp so
        // this cleanup cannot extend cache validity or the offline grace window.
        if ($safeData !== $cached['data']) {
            update_option(self::CACHE_KEY, [
                'data' => $safeData,
                'timestamp' => (int) $cached['timestamp'],
            ]);
        }

        return $safeData;
    }

    public static function isValid(): bool
    {
        $cached = get_option(self::CACHE_KEY);
        if (!$cached || !isset($cached['timestamp'])) {
            return false;
        }
        return (self::now() - (int) $cached['timestamp']) < self::CACHE_DURATION;
    }

    public static function isWithinGracePeriod(): bool
    {
        $lastCheck = get_option(self::LAST_CHECK_KEY, 0);
        return (self::now() - (int) $lastCheck) < self::GRACE_PERIOD;
    }

    public static function updateLastCheck(): void
    {
        update_option(self::LAST_CHECK_KEY, self::now());
    }

    public static function clear(): void
    {
        delete_option(self::CACHE_KEY);
    }

    /**
     * Remove license credentials recursively before data reaches WordPress'
     * plaintext options/cache layer.
     */
    private static function withoutLicenseKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            $normalizedKey = is_string($key)
                ? strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key))
                : '';

            if ($normalizedKey === 'licensekey' || $normalizedKey === 'rawlicensekey') {
                unset($data[$key]);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::withoutLicenseKeys($value);
            }
        }

        return $data;
    }

    /**
     * Provide a deterministic clock hook for security and grace-period tests.
     */
    private static function now(): int
    {
        $now = time();
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('whop_wc_license_cache_now', $now);
            if (is_numeric($filtered)) {
                return (int) $filtered;
            }
        }

        return $now;
    }
}
