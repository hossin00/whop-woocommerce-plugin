<?php

namespace Whop\WooCommerce\Admin;

use Whop\WooCommerce\API\WhopClient;
use Whop\WooCommerce\HealthCheck\HealthCheckService;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class SettingsPage
{
    private Config $config;
    private WhopClient $whopClient;
    private HealthCheckService $healthCheckService;
    private Logger $logger;

    public function __construct(Config $config, WhopClient $whopClient, HealthCheckService $healthCheckService, Logger $logger)
    {
        $this->config = $config;
        $this->whopClient = $whopClient;
        $this->healthCheckService = $healthCheckService;
        $this->logger = $logger;
    }

    public function register(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Whop Checkout', 'whop-woocommerce'),
            __('Whop Checkout', 'whop-woocommerce'),
            'manage_options',
            'whop-woocommerce-settings',
            [$this, 'render']
        );
    }

    public function enqueue_scripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_whop-woocommerce-settings') {
            return;
        }

        wp_enqueue_script(
            'whop-woocommerce-settings',
            WHOP_WOOCOMMERCE_ASSETS_URL . '/js/settings.js',
            ['jquery'],
            WHOP_WOOCOMMERCE_VERSION,
            true
        );

        wp_localize_script('whop-woocommerce-settings', 'WhopWooCommerceSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('whop_test_connection'),
            'successMessage' => __('Connected successfully.', 'whop-woocommerce'),
            'buttonText' => __('Test Connection', 'whop-woocommerce'),
        ]);
    }

    public function register_settings(): void
    {
        register_setting('whop_woocommerce_settings_group', 'whop_woocommerce_settings', [
            'type' => 'array',
            'description' => __('Whop settings for WooCommerce integration.', 'whop-woocommerce'),
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => $this->config->get_defaults(),
        ]);

        add_settings_section(
            'whop_woocommerce_general',
            __('Whop Checkout Settings', 'whop-woocommerce'),
            [$this, 'render_section_description'],
            'whop-woocommerce-settings'
        );

        $this->register_field('api_key', __('Whop API Key', 'whop-woocommerce'), 'render_api_key_field');
        $this->register_field('sandbox_api_key', __('Whop Sandbox API Key', 'whop-woocommerce'), 'render_sandbox_api_key_field');
        $this->register_field('plan_id', __('Default Plan ID', 'whop-woocommerce'), 'render_plan_id_field');
        $this->register_field('webhook_secret', __('Whop Webhook Secret', 'whop-woocommerce'), 'render_webhook_secret_field');
        $this->register_field('checkout_domain', __('Checkout Domain', 'whop-woocommerce'), 'render_checkout_domain_field');
        $this->register_field('sandbox_mode', __('Sandbox Mode', 'whop-woocommerce'), 'render_sandbox_mode_field');
        $this->register_field('debug_mode', __('Debug Mode', 'whop-woocommerce'), 'render_debug_mode_field');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function sanitize_settings(array $input): array
    {
        $input = wp_unslash($input);
        $existing = get_option('whop_woocommerce_settings', $this->config->get_defaults());

        $webhookSecret = is_array($existing) ? ($existing['webhook_secret'] ?? '') : '';
        $apiKey = is_array($existing) ? ($existing['api_key'] ?? '') : '';
        $sandboxApiKey = is_array($existing) ? ($existing['sandbox_api_key'] ?? '') : '';

        if (isset($input['webhook_secret']) && $input['webhook_secret'] !== '') {
            $webhookSecret = sanitize_text_field($input['webhook_secret']);
        }

        if (isset($input['api_key']) && $input['api_key'] !== '') {
            $apiKey = sanitize_text_field($input['api_key']);
        }

        if (isset($input['sandbox_api_key']) && $input['sandbox_api_key'] !== '') {
            $sandboxApiKey = sanitize_text_field($input['sandbox_api_key']);
        }

        return [
            'api_key' => $apiKey,
            'sandbox_api_key' => $sandboxApiKey,
            'plan_id' => sanitize_text_field($input['plan_id'] ?? ''),
            'webhook_secret' => $webhookSecret,
            'checkout_domain' => sanitize_text_field($input['checkout_domain'] ?? ''),
            'sandbox_mode' => isset($input['sandbox_mode']) && $input['sandbox_mode'] === 'yes' ? 'yes' : 'no',
            'debug_mode' => isset($input['debug_mode']) && $input['debug_mode'] === 'yes' ? 'yes' : 'no',
        ];
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('whop_woocommerce_settings', $this->config->get_defaults());
        $this->healthCheckService->runChecks();
        $results = $this->healthCheckService->getResults();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Whop Checkout Settings', 'whop-woocommerce'); ?></h1>
            <?php settings_errors(); ?>
            <div style="margin-bottom:1.5rem; padding:1rem; border:1px solid #e1e1e1; background:#fafafa;">
                <h2><?php esc_html_e('Health Check', 'whop-woocommerce'); ?></h2>
                <?php foreach ($results as $result) : ?>
                    <?php
                    $color = '#ffc107';

                    if ($result['status'] === 'ok') {
                        $color = '#28a745';
                    } elseif ($result['status'] === 'error') {
                        $color = '#dc3545';
                    }
                    ?>
                    <div style="margin-bottom:.75rem; padding:.75rem; border-left:4px solid <?php echo esc_attr($color); ?>; background:#fff;">
                        <strong><?php echo esc_html($result['label']); ?></strong>
                        <span style="margin-left:.5rem; color:<?php echo esc_attr($color); ?>;"><?php echo esc_html(strtoupper($result['status'])); ?></span>
                        <div style="margin-top:.35rem; color:#6c757d;">
                            <?php echo esc_html($result['message']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="options.php">
                <?php
                settings_fields('whop_woocommerce_settings_group');
                do_settings_sections('whop-woocommerce-settings');
                submit_button(__('Save Settings', 'whop-woocommerce'));
                ?>
            </form>
            <div id="whop-test-connection-result" style="margin-top: 1.5rem;"></div>
            <button id="whop-test-connection" class="button button-secondary" type="button">
                <?php esc_html_e('Test Whop Connection', 'whop-woocommerce'); ?>
            </button>
        </div>
        <?php
    }

    public function render_section_description(): void
    {
        echo '<p>' . esc_html__('Configure your Whop checkout integration settings below.', 'whop-woocommerce') . '</p>';
    }

    private function register_field(string $id, string $title, string $callback): void
    {
        add_settings_field(
            $id,
            $title,
            [$this, $callback],
            'whop-woocommerce-settings',
            'whop_woocommerce_general'
        );
    }

    public function render_api_key_field(): void
    {
        printf(
            '<input type="password" id="api_key" name="whop_woocommerce_settings[api_key]" value="" class="regular-text" autocomplete="new-password">'
        );
        echo '<p class="description">' . esc_html__(
            'Enter the Whop production API key. Leave blank to keep the existing key.',
            'whop-woocommerce'
        ) . '</p>';
    }

    public function render_sandbox_api_key_field(): void
    {
        printf(
            '<input type="password" id="sandbox_api_key" name="whop_woocommerce_settings[sandbox_api_key]" value="" class="regular-text" autocomplete="new-password">'
        );
        echo '<p class="description">' . esc_html__(
            'Enter the Whop sandbox API key. Leave blank to keep the existing key.',
            'whop-woocommerce'
        ) . '</p>';
    }

    public function render_plan_id_field(): void
    {
        $settings = get_option('whop_woocommerce_settings', $this->config->get_defaults());
        printf(
            '<input type="text" id="plan_id" name="whop_woocommerce_settings[plan_id]" value="%s" class="regular-text">',
            esc_attr($settings['plan_id'] ?? '')
        );
    }

    public function render_webhook_secret_field(): void
    {
        printf(
            '<input type="password" id="webhook_secret" name="whop_woocommerce_settings[webhook_secret]" value="" class="regular-text" autocomplete="new-password">'
        );
        echo '<p class="description">' . esc_html__(
            'Leave blank to keep the current webhook secret.',
            'whop-woocommerce'
        ) . '</p>';
    }

    public function render_checkout_domain_field(): void
    {
        $settings = get_option('whop_woocommerce_settings', $this->config->get_defaults());
        printf(
            '<input type="text" id="checkout_domain" name="whop_woocommerce_settings[checkout_domain]" value="%s" class="regular-text">',
            esc_attr($settings['checkout_domain'] ?? '')
        );
        echo '<p class="description">' . esc_html__(
            'Enter the checkout host name used for Whop checkout redirects, for example checkout.yourdomain.com.',
            'whop-woocommerce'
        ) . '</p>';
    }

    public function render_sandbox_mode_field(): void
    {
        $settings = get_option('whop_woocommerce_settings', $this->config->get_defaults());
        printf(
            '<label><input type="checkbox" name="whop_woocommerce_settings[sandbox_mode]" value="yes" %s> %s</label>',
            checked('yes', $settings['sandbox_mode'] ?? 'no', false),
            esc_html__('Enable sandbox mode for testing', 'whop-woocommerce')
        );
    }

    public function render_debug_mode_field(): void
    {
        $settings = get_option('whop_woocommerce_settings', $this->config->get_defaults());
        printf(
            '<label><input type="checkbox" name="whop_woocommerce_settings[debug_mode]" value="yes" %s> %s</label>',
            checked('yes', $settings['debug_mode'] ?? 'no', false),
            esc_html__('Enable debug logging', 'whop-woocommerce')
        );
    }

    public function handle_test_connection(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'whop-woocommerce')], 403);
        }

        check_ajax_referer('whop_test_connection', 'nonce');

        $result = $this->whopClient->test_connection();

        if (! $result['success']) {
            $this->logger->log('Whop connection test failed: ' . $result['message']);
            wp_send_json_error(['message' => $result['message']]);
        }

        $this->logger->log('Whop connection test succeeded.');
        wp_send_json_success(['message' => $result['message']]);
    }
}
