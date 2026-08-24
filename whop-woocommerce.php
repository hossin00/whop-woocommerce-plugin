<?php
/**
 * Plugin Name: Whop WooCommerce Checkout
 * Plugin URI:  https://example.com/whop-woocommerce-checkout
 * Description: Replace WooCommerce checkout with an embedded Whop checkout while keeping customers on the same domain.
 * Version:     0.1.57
 * Author:      Whop
 * Author URI:  https://example.com
 * Text Domain: whop-woocommerce
 * Domain Path: /languages
 * License:     GPLv2 or later
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Tested up to: 6.5
 * WC requires at least: 8.0
 * WC tested up to: 8.9
 */

defined('ABSPATH') || exit;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (!defined('WHOP_WOOCOMMERCE_FILE')) {
    define('WHOP_WOOCOMMERCE_FILE', __FILE__);
}

if (!defined('WHOP_WOOCOMMERCE_PATH')) {
    define('WHOP_WOOCOMMERCE_PATH', __DIR__);
}

use Whop\WooCommerce\Core\Activator;
use Whop\WooCommerce\Core\Deactivator;
use Whop\WooCommerce\Core\Plugin;

register_activation_hook(WHOP_WOOCOMMERCE_FILE, [Activator::class, 'activate']);
register_deactivation_hook(WHOP_WOOCOMMERCE_FILE, [Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('whop-woocommerce', false, dirname(plugin_basename(WHOP_WOOCOMMERCE_FILE)) . '/languages');

    Plugin::get_instance(WHOP_WOOCOMMERCE_FILE)->run();
});
