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
            'data' => $licenseData,
            'timestamp' => time(),
        ]);
    }

    public static function get(): ?array
    {
        $cached = get_option(self::CACHE_KEY);
        if (!$cached || !isset($cached['data'], $cached['timestamp'])) {
            return null;
        }
        return $cached['data'];
    }

    public static function isValid(): bool
    {
        $cached = get_option(self::CACHE_KEY);
        if (!$cached || !isset($cached['timestamp'])) {
            return false;
        }
        return (time() - $cached['timestamp']) < self::CACHE_DURATION;
    }

    public static function isWithinGracePeriod(): bool
    {
        $lastCheck = get_option(self::LAST_CHECK_KEY, 0);
        return (time() - (int)$lastCheck) < self::GRACE_PERIOD;
    }

    public static function updateLastCheck(): void
    {
        update_option(self::LAST_CHECK_KEY, time());
    }

    public static function clear(): void
    {
        delete_option(self::CACHE_KEY);
    }
}
