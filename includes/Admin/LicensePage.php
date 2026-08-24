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
        add_action('admin_notices', [$this, 'render_premium_gate_notice']);
    }

    public function render_premium_gate_notice(): void
    {
        if (!current_user_can('manage_options') || $this->licenseManager->isPremiumFeatureEnabled()) {
            return;
        }

        $url = admin_url('admin.php?page=whop-woocommerce-license');
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            wp_kses_post(
                sprintf(
                    __('Whop Checkout commercial gateways require a valid license. <a href="%s">Open License</a>. Existing storefront content and historic orders are unaffected.', 'whop-woocommerce'),
                    esc_url($url)
                )
            )
        );
    }

    /**
     * Renders the license settings page content.
     */
    public function render_page(): void
    {
        $licenseInfo = $this->licenseManager->getLicenseInfo();
        $licenseStatus = (string) ($licenseInfo['status'] ?? 'inactive');
        $licenseType = (string) ($licenseInfo['license_type'] ?? __('N/A', 'whop-woocommerce'));
        $supportExpiration = (string) ($licenseInfo['support_expiration'] ?? __('N/A', 'whop-woocommerce'));
        $updatesExpiration = (string) ($licenseInfo['updates_expiration'] ?? __('N/A', 'whop-woocommerce'));
        $sitesUsed = absint($licenseInfo['sites_used'] ?? 0);
        $siteLimit = $licenseInfo['site_limit'] ?? null;
        $sitesAllowed = !empty($licenseInfo['unlimited_sites']) || $siteLimit === null
            ? __('Unlimited', 'whop-woocommerce')
            : (string) absint($siteLimit);
        $currentVersion = $licenseInfo['current_version'] ?? WHOP_WOOCOMMERCE_VERSION;
        $lastCheck = !empty($licenseInfo['last_check']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $licenseInfo['last_check']) : __('Never', 'whop-woocommerce');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Whop Checkout License', 'whop-woocommerce'); ?></h1>
            <?php if ($licenseStatus !== 'active') : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('A valid license is required for premium licensing, updates, and support. Existing checkout behavior is not changed by this notice.', 'whop-woocommerce'); ?></p></div>
            <?php elseif (empty($licenseInfo['updates_active'])) : ?>
                <div class="notice notice-info"><p><?php esc_html_e('Your perpetual core license remains active, but updates are currently unavailable. Renew support and updates to receive new releases.', 'whop-woocommerce'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php" autocomplete="off">
                <?php // License actions are handled only by nonce-protected AJAX. ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="whop_wc_license_key"><?php esc_html_e('License Key', 'whop-woocommerce'); ?></label></th>
                            <td>
                                <input type="password" id="whop_wc_license_key" name="whop_wc_license_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e('Enter a license key to activate or replace it', 'whop-woocommerce'); ?>" />
                                <p class="description"><?php esc_html_e('The stored key is encrypted and never rendered back into this page. Enter it again only to activate or replace a license.', 'whop-woocommerce'); ?></p>
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
                            <th scope="row"><?php esc_html_e('Sites Used / Allowed', 'whop-woocommerce'); ?></th>
                            <td><?php echo esc_html($sitesUsed . ' / ' . $sitesAllowed); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Updates Until', 'whop-woocommerce'); ?></th>
                            <td><?php echo esc_html($updatesExpiration); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Support Until', 'whop-woocommerce'); ?></th>
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
        // Kept as a no-op hook for backward compatibility. License state is saved
        // only through LicenseStorage after a provider-confirmed activation.
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
            // Remove the historical plaintext option if a previous version wrote it.
            delete_option('whop_wc_license_key');
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
