<?php

namespace Whop\WooCommerce\Core;

if (! function_exists('deactivate_plugins')) {
    function deactivate_plugins(string $plugin, bool $silent = false): void
    {
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename($file);
    }
}

final class Activator
{
    public static function activate(): void
    {
        // Minimum PHP version
        if (self::isVersionBelowMinimum(PHP_VERSION, '8.2')) {
            deactivate_plugins(plugin_basename(WHOP_WOOCOMMERCE_FILE));
            wp_die(esc_html__(
                'Whop WooCommerce Checkout requires PHP 8.2 or greater.',
                'whop-woocommerce'
            ));
        }

        // Minimum WordPress version
        global $wp_version;
        $wordpressVersion = isset($wp_version) && is_string($wp_version) ? $wp_version : '';

        if ($wordpressVersion === '' || self::isVersionBelowMinimum($wordpressVersion, '6.0')) {
            deactivate_plugins(plugin_basename(WHOP_WOOCOMMERCE_FILE));
            wp_die(esc_html__(
                'Whop WooCommerce Checkout requires WordPress 6.0 or greater.',
                'whop-woocommerce'
            ));
        }

        // WooCommerce presence and minimum version
        if (! class_exists('\WC_Integration') || ! defined('WC_VERSION') || self::isVersionBelowMinimum(WC_VERSION, '8.0')) {
            deactivate_plugins(plugin_basename(WHOP_WOOCOMMERCE_FILE));
            wp_die(esc_html__(
                'WooCommerce 8.0 or greater must be installed and active to use Whop WooCommerce Checkout.',
                'whop-woocommerce'
            ));
        }

        self::create_default_settings();
    }

    private static function isVersionBelowMinimum(string $currentVersion, string $minimumVersion): bool
    {
        return version_compare($currentVersion, $minimumVersion, '<');
    }

    private static function create_default_settings(): void
    {
        $defaults = [
            'api_key' => '',
            'sandbox_api_key' => '',
            'plan_id' => '',
            'sandbox_plan_id' => '',
            'webhook_secret' => '',
            'checkout_return_url' => home_url('/checkout/complete'),
            'checkout_domain' => '',
            'sandbox_mode' => 'no',
            'debug_mode' => 'no',
        ];

        add_option('whop_woocommerce_settings', $defaults, '', 'no');
        update_option('whop_woocommerce_flush_rewrite', 'yes');
    }
}
