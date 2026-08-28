<?php

namespace Whop\WooCommerce\Core;

use Whop\WooCommerce\Admin\SettingsPage;
use Whop\WooCommerce\Admin\LicensePage;
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
use Whop\WooCommerce\Payments\NativeGatewayAssets;
use Whop\WooCommerce\Payments\NativeGatewayBlocksIntegration;
use Whop\WooCommerce\Payments\NativeGatewayRegistrar;
use Whop\WooCommerce\Payments\NativePaymentPage;
use Whop\WooCommerce\Payments\PaymentEligibilityService;
use Whop\WooCommerce\Payments\PaymentMethodRegistry;
use Whop\WooCommerce\Payments\WhopPaymentAttemptService;
use Whop\WooCommerce\Payments\CardSetupService;
use Whop\WooCommerce\Orders\OrderHandler;
use Whop\WooCommerce\Webhooks\WebhookController;
use Whop\WooCommerce\Webhooks\WebhookHandler;
use Whop\WooCommerce\Webhooks\WebhookIdempotencyService;
use Whop\WooCommerce\Licensing\LicenseStorage;
use Whop\WooCommerce\Licensing\LicenseManager;
use Whop\WooCommerce\Licensing\LicenseValidator;
use Whop\WooCommerce\Licensing\Providers\SaaSLicenseProvider;
use Whop\WooCommerce\Licensing\UpdatesHandler;
use Whop\WooCommerce\Licensing\LicenseCron;

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
        $this->maybe_schedule_rewrite_flush();
        $this->register_services();
        $this->register_hooks();
    }

    private function maybe_schedule_rewrite_flush(): void
    {
        $installedVersion = get_option('whop_woocommerce_version', '');

        if (! is_string($installedVersion)) {
            $installedVersion = '';
        }

        if ($installedVersion === WHOP_WOOCOMMERCE_VERSION) {
            return;
        }

        update_option('whop_woocommerce_flush_rewrite', 'yes');
        update_option('whop_woocommerce_version', WHOP_WOOCOMMERCE_VERSION);
    }

    private function define_constants(): void
    {
        if (!defined('WHOP_WOOCOMMERCE_VERSION')) {
            define('WHOP_WOOCOMMERCE_VERSION', '0.1.58-rc2');
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

        // Licensing services use the existing local manager/storage and a SaaS
        // provider. Checkout credentials remain isolated in Config/SettingsPage.
        $this->container->register('license_provider', function (): SaaSLicenseProvider {
            return new SaaSLicenseProvider();
        });

        $this->container->register('updates_handler', function (): UpdatesHandler {
            return new UpdatesHandler($this->container->get('license_manager'));
        });

        $this->container->register('license_cron', function (): LicenseCron {
            return new LicenseCron($this->container->get('license_manager'));
        });

        $this->container->register('license_storage', function (): LicenseStorage {
            return new LicenseStorage();
        });

        $this->container->register('license_manager', function (): LicenseManager {
            return new LicenseManager(
                $this->container->get('license_provider'),
                $this->container->get('license_storage')
            );
        });

        $this->container->register('license_validator', function (): LicenseValidator {
            return new LicenseValidator();
        });

        $this->container->register('license_page', function (): LicensePage {
            return new LicensePage(
                $this->container->get('license_manager'),
                $this->container->get('license_validator'),
                $this->container->get('logger')
            );
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
            return new RedirectService($this->container->get('logger'));
        });

        $this->container->register('payment_method_registry', function (): PaymentMethodRegistry {
            return new PaymentMethodRegistry();
        });

        $this->container->register('payment_eligibility_service', function (): PaymentEligibilityService {
            return new PaymentEligibilityService($this->container->get('payment_method_registry'));
        });

        $this->container->register('whop_payment_attempt_service', function (): WhopPaymentAttemptService {
            return new WhopPaymentAttemptService(
                $this->container->get('checkout_service'),
                $this->container->get('payment_eligibility_service'),
                $this->container->get('card_setup_service'),
                $this->container->get('logger')
            );
        });

        $this->container->register('native_gateway_registrar', function (): NativeGatewayRegistrar {
            return new NativeGatewayRegistrar(
                $this->container->get('payment_method_registry'),
                $this->container->get('payment_eligibility_service'),
                $this->container->get('whop_payment_attempt_service'),
                $this->container->get('config')
            );
        });

        $this->container->register('native_payment_page', function (): NativePaymentPage {
            return new NativePaymentPage(
                $this->container->get('config'),
                $this->container->get('logger'),
                $this->container->get('whop_payment_attempt_service')
            );
        });

        $this->container->register('native_gateway_blocks_integration', function (): NativeGatewayBlocksIntegration {
            return new NativeGatewayBlocksIntegration(
                $this->container->get('payment_method_registry'),
                $this->container->get('config')
            );
        });

        $this->container->register('native_gateway_assets', function (): NativeGatewayAssets {
            return new NativeGatewayAssets();
        });

        $this->container->register('checkout', function (): Checkout {
            return new Checkout(
                $this->container->get('checkout_service'),
                $this->container->get('redirect_service'),
                $this->container->get('logger'),
                $this->container->get('config'),
                $this->container->get('license_manager')
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

        $this->container->register('card_setup_service', function (): CardSetupService {
            return new CardSetupService(
                $this->container->get('checkout_service'),
                $this->container->get('whop_identity_service'),
                $this->container->get('webhook_idempotency_service'),
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
                $this->container->get('order_handler'),
                $this->container->get('card_setup_service')
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
        $nativeGatewayRegistrar = $this->container->get('native_gateway_registrar');
        $nativePaymentPage = $this->container->get('native_payment_page');
        $nativeGatewayBlocksIntegration = $this->container->get('native_gateway_blocks_integration');
        $nativeGatewayAssets = $this->container->get('native_gateway_assets');

        $this->loader->register_action('admin_menu', $settingsPage, 'register');
        $this->loader->register_action('admin_init', $settingsPage, 'register_settings');

        $licensePage = $this->container->get('license_page');
        $this->loader->register_action('admin_menu', $licensePage, 'register');
        $this->loader->register_action('admin_init', $licensePage, 'register_settings');
        $this->loader->register_action('admin_enqueue_scripts', $licensePage, 'enqueue_scripts');
        $this->loader->register_action('wp_ajax_whop_activate_license', $licensePage, 'handle_activate_license');
        $this->loader->register_action('wp_ajax_whop_deactivate_license', $licensePage, 'handle_deactivate_license');
        $this->loader->register_action('wp_ajax_whop_check_license', $licensePage, 'handle_check_license');
        $this->loader->register_action('wp_ajax_whop_check_updates', $licensePage, 'handle_check_updates');

        $updatesHandler = $this->container->get('updates_handler');
        $updatesHandler->registerHooks();

        $licenseCron = $this->container->get('license_cron');
        $licenseCron->register();
        $this->loader->register_action('admin_enqueue_scripts', $settingsPage, 'enqueue_scripts');
        $this->loader->register_action('wp_ajax_whop_test_connection', $settingsPage, 'handle_test_connection');
        $this->loader->register_action('woocommerce_after_add_to_cart_button', $checkout, 'render_buy_now_button');
        $this->loader->register_action('template_redirect', $checkout, 'maybe_handle_native_buy_now', 0);
        $this->loader->register_action('init', $checkout, 'register_rewrite_rules');
        $this->loader->register_action('template_redirect', $nativePaymentPage, 'maybeRender', 1);
        $this->loader->register_action('wp_enqueue_scripts', $nativeGatewayAssets, 'enqueue');
        $this->loader->register_action('woocommerce_blocks_payment_method_type_registration', $nativeGatewayBlocksIntegration, 'register');
        $this->loader->register_action('template_redirect', $checkout, 'handle_checkout_routes');
        $this->loader->register_action('wp_enqueue_scripts', $checkout, 'enqueue_checkout_assets');
        $this->loader->register_filter('query_vars', $checkout, 'add_query_vars', 10, 1);
        $this->loader->register_filter('woocommerce_payment_gateways', $nativeGatewayRegistrar, 'register', 20, 1);
        $this->loader->register_filter('woocommerce_available_payment_gateways', $checkout, 'disable_payment_gateways', 10, 1);
        $this->loader->register_filter('woocommerce_get_checkout_url', $checkout, 'mark_cart_checkout_url', 20, 1);
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
