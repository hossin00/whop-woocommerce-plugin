<?php

namespace Whop\WooCommerce\Core;

use Whop\WooCommerce\Admin\SettingsPage;
use Whop\WooCommerce\API\WhopClient;
use Whop\WooCommerce\Checkout\Checkout;
use Whop\WooCommerce\Checkout\CheckoutService;
use Whop\WooCommerce\Checkout\CheckoutStateService;
use Whop\WooCommerce\Checkout\CheckoutUrlValidator;
use Whop\WooCommerce\Checkout\RedirectService;
use Whop\WooCommerce\Checkout\RetryPolicy;
use Whop\WooCommerce\HealthCheck\HealthCheckService;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Identity\WhopIdentityService;
use Whop\WooCommerce\Logger\Logger;
use Whop\WooCommerce\Orders\OrderHandler;
use Whop\WooCommerce\Webhooks\WebhookController;
use Whop\WooCommerce\Webhooks\WebhookHandler;
use Whop\WooCommerce\Webhooks\WebhookIdempotencyService;

final class Plugin
{
    private static ?Plugin $instance = null;

    private string $file;
    private string $basename;
    private Container $container;
    private Loader $loader;

    private function __construct(string $file)
    {
        $this->file = $file;
        $this->basename = plugin_basename($file);
    }

    public static function get_instance(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
        }

        return self::$instance;
    }

    public function run(): void
    {
        $this->define_constants();
        $this->register_services();
        $this->register_hooks();
    }

    private function define_constants(): void
    {
        if (!defined('WHOP_WOOCOMMERCE_VERSION')) {
            define('WHOP_WOOCOMMERCE_VERSION', '0.1.0');
        }

        if (!defined('WHOP_WOOCOMMERCE_FILE')) {
            define('WHOP_WOOCOMMERCE_FILE', $this->file);
        }

        if (!defined('WHOP_WOOCOMMERCE_PATH')) {
            define('WHOP_WOOCOMMERCE_PATH', dirname($this->file));
        }

        if (!defined('WHOP_WOOCOMMERCE_URL')) {
            define('WHOP_WOOCOMMERCE_URL', plugin_dir_url($this->file));
        }

        if (!defined('WHOP_WOOCOMMERCE_INCLUDES')) {
            define('WHOP_WOOCOMMERCE_INCLUDES', WHOP_WOOCOMMERCE_PATH . '/includes');
        }

        if (!defined('WHOP_WOOCOMMERCE_ASSETS')) {
            define('WHOP_WOOCOMMERCE_ASSETS', WHOP_WOOCOMMERCE_PATH . '/assets');
        }

        if (!defined('WHOP_WOOCOMMERCE_ASSETS_URL')) {
            define('WHOP_WOOCOMMERCE_ASSETS_URL', WHOP_WOOCOMMERCE_URL . 'assets');
        }

        if (!defined('WHOP_WOOCOMMERCE_TEMPLATES')) {
            define('WHOP_WOOCOMMERCE_TEMPLATES', WHOP_WOOCOMMERCE_PATH . '/templates');
        }

        if (!defined('WHOP_WOOCOMMERCE_BASENAME')) {
            define('WHOP_WOOCOMMERCE_BASENAME', $this->basename);
        }
    }

    private function register_services(): void
    {
        $this->container = new Container();

        $this->container->register('config', static function (): Config {
            return new Config();
        });

        $this->container->register('logger', function (): Logger {
            return new Logger($this->container->get('config'));
        });

        $this->container->register('whop_client', function (): WhopClient {
            return new WhopClient($this->container->get('config'));
        });

        $this->container->register('health_check_service', function (): HealthCheckService {
            return new HealthCheckService(
                $this->container->get('config'),
                $this->container->get('whop_client'),
                $this->container->get('logger')
            );
        });

        $this->container->register('settings_page', function (): SettingsPage {
            return new SettingsPage(
                $this->container->get('config'),
                $this->container->get('whop_client'),
                $this->container->get('health_check_service'),
                $this->container->get('logger')
            );
        });

        $this->container->register('checkout_state_service', function (): CheckoutStateService {
            return new CheckoutStateService(
                $this->container->get('logger')
            );
        });

        $this->container->register('retry_policy', function (): RetryPolicy {
            return new RetryPolicy(
                $this->container->get('logger'),
                $this->container->get('config')
            );
        });

        $this->container->register('checkout_url_validator', function (): CheckoutUrlValidator {
            return new CheckoutUrlValidator(
                $this->container->get('config')
            );
        });

        $this->container->register('checkout_service', function (): CheckoutService {
            return new CheckoutService(
                $this->container->get('config'),
                $this->container->get('whop_client'),
                $this->container->get('logger'),
                $this->container->get('checkout_state_service'),
                $this->container->get('retry_policy'),
                $this->container->get('checkout_url_validator')
            );
        });

        $this->container->register('redirect_service', function (): RedirectService {
            return new RedirectService(
                $this->container->get('checkout_url_validator'),
                $this->container->get('config'),
                $this->container->get('logger')
            );
        });

        $this->container->register('checkout', function (): Checkout {
            return new Checkout(
                $this->container->get('checkout_service'),
                $this->container->get('redirect_service'),
                $this->container->get('logger')
            );
        });

        $this->container->register('webhook_idempotency_service', function (): WebhookIdempotencyService {
            return new WebhookIdempotencyService(
                $this->container->get('logger')
            );
        });

        $this->container->register('whop_identity_service', function (): WhopIdentityService {
            return new WhopIdentityService(
                $this->container->get('logger')
            );
        });

        $this->container->register('order_handler', function (): OrderHandler {
            return new OrderHandler(
                $this->container->get('logger'),
                $this->container->get('checkout_state_service'),
                $this->container->get('webhook_idempotency_service'),
                $this->container->get('whop_identity_service')
            );
        });

        $this->container->register('webhook_handler', function (): WebhookHandler {
            return new WebhookHandler(
                $this->container->get('logger'),
                $this->container->get('order_handler')
            );
        });

        $this->loader = new Loader();

        $this->container->register('webhook_controller', function (): WebhookController {
            return new WebhookController(
                $this->container->get('config'),
                $this->container->get('logger'),
                $this->container->get('webhook_handler'),
                $this->container->get('webhook_idempotency_service')
            );
        });
    }

    private function register_hooks(): void
    {
        $settingsPage = $this->container->get('settings_page');
        $webhookController = $this->container->get('webhook_controller');

        $checkout = $this->container->get('checkout');

        $this->loader->register_action('admin_menu', $settingsPage, 'register');
        $this->loader->register_action('admin_init', $settingsPage, 'register_settings');
        $this->loader->register_action('admin_enqueue_scripts', $settingsPage, 'enqueue_scripts');
        $this->loader->register_action('wp_ajax_whop_test_connection', $settingsPage, 'handle_test_connection');
        $this->loader->register_action('woocommerce_after_add_to_cart_button', $checkout, 'render_buy_now_button');
        $this->loader->register_action('admin_post_whop_buy_now', $checkout, 'handle_buy_now');
        $this->loader->register_action('admin_post_nopriv_whop_buy_now', $checkout, 'handle_buy_now');
        $this->loader->register_filter('woocommerce_available_payment_gateways', $checkout, 'disable_payment_gateways', 10, 1);
        $this->loader->register_action('rest_api_init', $webhookController, 'register_routes');
        $this->loader->run();
    }

    public function get_container(): Container
    {
        return $this->container;
    }

    public function get_loader(): Loader
    {
        return $this->loader;
    }
}
