<?php

namespace Whop\WooCommerce\Licensing;

/**
 * Class DomainValidator
 * Validates domains for license activation (Localhost, Staging, Production).
 * @package Whop\WooCommerce\Licensing
 */
class DomainValidator
{
    public static function isLocalhost(string $url): bool
    {
        return strpos($url, 'localhost') !== false || 
               strpos($url, '127.0.0.1') !== false ||
               strpos($url, '::1') !== false;
    }

    public static function isStaging(string $url): bool
    {
        $stagingPatterns = ['staging', 'dev', 'test', 'sandbox', '.local'];
        foreach ($stagingPatterns as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isProduction(string $url): bool
    {
        return !self::isLocalhost($url) && !self::isStaging($url);
    }

    public static function getEnvironment(string $url): string
    {
        if (self::isLocalhost($url)) {
            return 'localhost';
        } elseif (self::isStaging($url)) {
            return 'staging';
        }
        return 'production';
    }
}
