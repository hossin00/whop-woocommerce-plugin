<?php

namespace Whop\WooCommerce\Admin;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;
use Whop\WooCommerce\Licensing\LicenseValidator;
use Whop\WooCommerce\Logger\Logger;

/**
 * Class LicensePage
 * Handles the display and processing of the plugin license settings page.
 * @package Whop\WooCommerce\Admin
 */
class LicensePage
{
    /**
     * @var ILicenseManager $licenseManager The license manager instance.
     */
    private $licenseManager;

    /**
     * @var LicenseValidator $licenseValidator The license validator instance.
     */
    private $licenseValidator;

    /**
     * @var Logger $logger The logger instance.
     */
    private $logger;

    /**
     * LicensePage constructor.
     * @param ILicenseManager $licenseManager
     * @param LicenseValidator $licenseValidator
     * @param Logger $logger
     */
    public function __construct(ILicenseManager $licenseManager, LicenseValidator $licenseValidator, Logger $logger)
    {
        $this->licenseManager = $licenseManager;
        $this->licenseValidator = $licenseValidator;
        $this->logger = $logger;
    }

    /**
     * Registers the license submenu page.
     */
    public function register(): void
    {
        add_submenu_page(
            'woocommerce',
            __('License', 'whop-woocommerce'),
            __('License', 'whop-woocommerce'),
            'manage_options',
            'whop-woocommerce-license',
            [$this, 'render_page']
        );
    }

    /**
     * Renders the license settings page content.
     */
    public function render_page(): void
    {
        $licenseInfo = $this->licenseManager->getLicenseInfo();
        $licenseKey = $licenseInfo['license_key'] ?? '';
        $licenseStatus = $licenseInfo['status'] ?? __('Inactive', 'whop-woocommerce');
        $licenseType = $licenseInfo['license_type'] ?? __('N/A', 'whop-woocommerce');
        $supportExpiration = $licenseInfo['support_expiration'] ?? __('N/A', 'whop-woocommerce');
        $currentVersion = $licenseInfo['current_version'] ?? WHOP_WOOCOMMERCE_VERSION;
        $lastCheck = $licenseInfo['last_check'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $licenseInfo['last_check']) : __('Never', 'whop-woocommerce');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Whop Checkout License', 'whop-woocommerce'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('whop_woocommerce_license_group');
                do_settings_sections('whop-woocommerce-license');
                ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="whop_wc_license_key"><?php esc_html_e('License Key', 'whop-woocommerce'); ?></label></th>
                            <td>
                                <input type="text" id="whop_wc_license_key" name="whop_wc_license_key" value="<?php echo esc_attr($licenseKey); ?>" class="regular-text" placeholder="<?php esc_attr_e('Enter your license key', 'whop-woocommerce'); ?>" />
                                <p class="description"><?php esc_html_e('Enter your plugin license key here.', 'whop-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('License Status', 'whop-woocommerce'); ?></th>
                            <td><span class="license-status-<?php echo esc_attr(strtolower($licenseStatus)); ?>"><strong><?php echo esc_html(ucfirst($licenseStatus)); ?></strong></span></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('License Type', 'whop-woocommerce'); ?></th>
                            <td><?php echo esc_html($licenseType); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Support Expiration', 'whop-woocommerce'); ?></th>
                            <td><?php echo esc_html($supportExpiration); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Current Version', 'whop-woocommerce'); ?></th>
                            <td><?php echo esc_html($currentVersion); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last Checked', 'whop-woocommerce'); ?></th>
                            <td><?php echo esc_html($lastCheck); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="button" class="button button-primary" id="whop-wc-activate-license"><?php esc_html_e('Activate License', 'whop-woocommerce'); ?></button>
                    <button type="button" class="button" id="whop-wc-deactivate-license"><?php esc_html_e('Deactivate License', 'whop-woocommerce'); ?></button>
                    <button type="button" class="button" id="whop-wc-check-license"><?php esc_html_e('Check License', 'whop-woocommerce'); ?></button>
                    <button type="button" class="button" id="whop-wc-check-updates"><?php esc_html_e('Check Updates', 'whop-woocommerce'); ?></button>
                </p>
                <div id="whop-wc-license-message" style="margin-top: 15px;"></div>
            </form>
        </div>
        <?php
    }

    /**
     * Registers the settings for the license page.
     */
    public function register_settings(): void
    {
        register_setting(
            'whop_woocommerce_license_group',
            'whop_wc_license_key',
            [$this, 'sanitize_license_key']
        );

        add_settings_section(
            'whop_woocommerce_license_section',
            __('License Management', 'whop-woocommerce'),
            null,
            'whop-woocommerce-license'
        );

        add_settings_field(
            'whop_wc_license_key_field',
            __('License Key', 'whop-woocommerce'),
            [$this, 'render_license_key_field'],
            'whop-woocommerce-license',
            'whop_woocommerce_license_section'
        );
    }

    /**
     * Renders the license key input field (used by settings API, but we're rendering manually).
     */
    public function render_license_key_field(): void
    {
        // Rendered manually in render_page() for better control.
    }

    /**
     * Sanitizes the license key input.
     * @param string $input The raw license key input.
     * @return string The sanitized license key.
     */
    public function sanitize_license_key(string $input): string
    {
        return sanitize_text_field($input);
    }

    /**
     * Handles AJAX request for license activation.
     */
    public function handle_activate_license(): void
    {
        check_ajax_referer('whop-wc-license-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'whop-woocommerce')]);
        }

        $licenseKey = sanitize_text_field($_POST['license_key'] ?? '');

        if (!$this->licenseValidator->isValidFormat($licenseKey)) {
            wp_send_json_error(['message' => __('Invalid license key format.', 'whop-woocommerce')]);
        }

        // Also save to settings for UI consistency if needed, but Manager handles storage
        $response = $this->licenseManager->activateLicense($licenseKey);
        if ($response['status'] === 'success') {
            update_option('whop_wc_license_key', $licenseKey);
            wp_send_json_success(['message' => $response['message']]);
        } else {
            wp_send_json_error(['message' => $response['message']]);
        }
    }

    /**
     * Handles AJAX request for license deactivation.
     */
    public function handle_deactivate_license(): void
    {
        check_ajax_referer('whop-wc-license-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'whop-woocommerce')]);
        }

        $response = $this->licenseManager->deactivateLicense();
        if ($response['status'] === 'success') {
            wp_send_json_success(['message' => $response['message']]);
        } else {
            wp_send_json_error(['message' => $response['message']]);
        }
    }

    /**
     * Handles AJAX request for license check.
     */
    public function handle_check_license(): void
    {
        check_ajax_referer('whop-wc-license-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'whop-woocommerce')]);
        }

        $response = $this->licenseManager->checkLicense();
        if ($response['status'] === 'success' || $response['status'] === 'inactive' || $response['status'] === 'invalid') {
            wp_send_json_success(['message' => $response['message'], 'data' => $response['data'] ?? []]);
        } else {
            wp_send_json_error(['message' => $response['message']]);
        }
    }

    /**
     * Handles AJAX request for update check.
     */
    public function handle_check_updates(): void
    {
        check_ajax_referer('whop-wc-license-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'whop-woocommerce')]);
        }

        $response = $this->licenseManager->checkForUpdates();
        if ($response['status'] === 'success') {
            wp_send_json_success(['message' => $response['message'], 'data' => $response['data'] ?? []]);
        } else {
            wp_send_json_error(['message' => $response['message']]);
        }
    }

    /**
     * Enqueues scripts and styles for the license page.
     */
    public function enqueue_scripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_whop-woocommerce-license') {
            return;
        }

        wp_enqueue_script(
            'whop-wc-license-script',
            WHOP_WOOCOMMERCE_ASSETS_URL . '/js/license-settings.js',
            ['jquery'],
            WHOP_WOOCOMMERCE_VERSION,
            true
        );
        wp_localize_script(
            'whop-wc-license-script',
            'whopWcLicense',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('whop-wc-license-nonce'),
                'licenseTypeLabel' => __('License Type', 'whop-woocommerce'),
                'supportExpirationLabel' => __('Support Expiration', 'whop-woocommerce'),
                'lastCheckedLabel' => __('Last Checked', 'whop-woocommerce'),
            ]
        );
        wp_enqueue_style(
            'whop-wc-license-style',
            WHOP_WOOCOMMERCE_ASSETS_URL . '/css/license-settings.css',
            [],
            WHOP_WOOCOMMERCE_VERSION
        );
    }
}
