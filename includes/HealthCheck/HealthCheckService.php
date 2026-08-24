<?php

namespace Whop\WooCommerce\HealthCheck;

use Whop\WooCommerce\API\WhopClient;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class HealthCheckService
{
    private Config $config;
    private WhopClient $whopClient;
    private Logger $logger;
    /** @var array<int, array<string, string>> */
    private array $results = [];

    public function __construct(Config $config, WhopClient $whopClient, Logger $logger)
    {
        $this->config = $config;
        $this->whopClient = $whopClient;
        $this->logger = $logger;
    }

    public function runChecks(): void
    {
        $this->logger->log('Starting Whop health checks.');
        $this->results = [];

        $this->results[] = $this->checkApiKey();
        $this->results[] = $this->checkWebhookSecret();
        $this->results[] = $this->checkPlanId();
        $this->results[] = $this->checkEmbeddedCheckoutConfiguration();
        $this->results[] = $this->checkApiLatency();
        $this->results[] = $this->checkApiConnection();
        $this->results[] = $this->checkWebhookEndpoint();
        $this->results[] = $this->checkPhpExtensions();
        $this->results[] = $this->checkWooCommerceActive();
        $this->results[] = $this->checkWordPressVersion();
    }

    /** @return array<int, array<string, string>> */
    public function getResults(): array
    {
        return $this->results;
    }

    /** @return array<string, string> */
    private function checkApiKey(): array
    {
        $apiKey = $this->config->get_active_api_key();
        $environmentLabel = $this->config->is_sandbox_mode() ? 'Sandbox' : 'Production';

        if (trim($apiKey) === '') {
            /* translators: %s is the selected Whop environment label (Sandbox or Production). */
            return $this->failure('API key configured', sprintf(__('%s API key is not configured.', 'whop-woocommerce'), $environmentLabel));
        }

        /* translators: %s is the selected Whop environment label (Sandbox or Production). */
        return $this->success('API key configured', sprintf(__('%s API key is configured.', 'whop-woocommerce'), $environmentLabel));
    }

    /** @return array<string, string> */
    private function checkWebhookSecret(): array
    {
        $webhookSecret = $this->config->get('webhook_secret');

        if (trim($webhookSecret) === '') {
            return $this->failure('Webhook secret configured', __('Whop webhook secret is not configured.', 'whop-woocommerce'));
        }

        return $this->success('Webhook secret configured', __('Whop webhook secret is configured.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function checkPlanId(): array
    {
        $planId = $this->config->get_active_plan_id();
        $environmentLabel = $this->config->is_sandbox_mode() ? 'Sandbox' : 'Production';

        if (trim($planId) === '') {
            /* translators: %s is the selected Whop environment label (Sandbox or Production). */
            return $this->failure('Default plan ID configured', sprintf(__('%s plan ID is not configured.', 'whop-woocommerce'), $environmentLabel));
        }

        /* translators: %s is the selected Whop environment label (Sandbox or Production). */
        return $this->success('Default plan ID configured', sprintf(__('%s plan ID is configured.', 'whop-woocommerce'), $environmentLabel));
    }

    /** @return array<string, string> */
    private function checkEmbeddedCheckoutConfiguration(): array
    {
        $loaderUrl = 'https://js.whop.com/static/checkout/loader.js';
        $returnUrl = $this->config->get_checkout_return_url();
        $environment = $this->config->get_checkout_environment();

        if (filter_var($loaderUrl, FILTER_VALIDATE_URL) === false) {
            return $this->failure('Embedded checkout configuration', __('Embedded checkout loader URL is invalid.', 'whop-woocommerce'));
        }

        if (filter_var($returnUrl, FILTER_VALIDATE_URL) === false || stripos($returnUrl, 'https://') !== 0) {
            return $this->failure('Embedded checkout configuration', __('Embedded checkout return URL must be a valid HTTPS URL.', 'whop-woocommerce'));
        }

        if (! in_array($environment, ['production', 'sandbox'], true)) {
            return $this->failure('Embedded checkout configuration', __('Embedded checkout environment is invalid.', 'whop-woocommerce'));
        }

        return $this->success('Embedded checkout configuration', __('Embedded checkout loader, return URL, and environment are configured.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function checkApiLatency(): array
    {
        $apiKey = $this->config->get_active_api_key();
        $apiBase = $this->config->get_api_base_url();

        if (trim($apiKey) === '') {
            return $this->warning('Whop API latency', __('API latency cannot be checked without an API key.', 'whop-woocommerce'));
        }

        $url = $apiBase . '/me';

        $start = microtime(true);
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $apiKey),
                'Accept' => 'application/json',
            ],
        ]);
        $duration = microtime(true) - $start;

        if (is_wp_error($response)) {
            /* translators: %s is the WordPress error message returned while measuring Whop API latency. */
            return $this->failure('Whop API latency', sprintf(__('Unable to determine API latency: %s', 'whop-woocommerce'), $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            if ($duration > 1.0) {
                /* translators: %s is the measured API latency in seconds. */
                return $this->warning('Whop API latency', sprintf(__('Whop API latency is high (%.2fs).', 'whop-woocommerce'), $duration));
            }

            /* translators: %s is the measured API latency in seconds. */
            return $this->success('Whop API latency', sprintf(__('Whop API latency is acceptable (%.2fs).', 'whop-woocommerce'), $duration));
        }

        /* translators: %1$d is the HTTP response code and %2$.2f is the request duration in seconds. */
        return $this->warning('Whop API latency', sprintf(__('Whop API responded with HTTP %1$d (%2$.2fs).', 'whop-woocommerce'), $code, $duration));
    }

    /** @return array<string, string> */
    private function checkApiConnection(): array
    {
        $apiKey = $this->config->get_active_api_key();
        $planId = trim($this->config->get_active_plan_id());

        if (trim($apiKey) === '') {
            return $this->warning('API connection successful', __('API connection cannot be checked without an API key.', 'whop-woocommerce'));
        }

        if ($planId === '') {
            return $this->warning('Configured plan retrievable', __('Plan retrieval cannot be checked without a configured plan ID.', 'whop-woocommerce'));
        }

        try {
            $this->whopClient->retrieve_plan($planId);
        } catch (\Throwable $exception) {
            /* translators: %s is the API error message returned by the Whop client when plan retrieval fails. */
            return $this->failure('Configured plan retrievable', sprintf(__('Unable to retrieve configured plan from Whop: %s', 'whop-woocommerce'), $exception->getMessage()));
        }

        return $this->success('Configured plan retrievable', __('Configured plan is retrievable from Whop API.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function checkWebhookEndpoint(): array
    {
        $found = false;

        if (function_exists('rest_get_server')) {
            $server = rest_get_server();
            $routes = $server->get_routes();
            $found = isset($routes['/whop-woocommerce/v1/webhook']);
        }

        if (! $found) {
            return $this->warning('REST webhook endpoint registered', __('Webhook endpoint is not registered yet. It may be initialized after plugin activation.', 'whop-woocommerce'));
        }

        return $this->success('REST webhook endpoint registered', __('Webhook endpoint is registered.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function checkPhpExtensions(): array
    {
        $required = ['curl', 'json'];
        $missing = [];

        foreach ($required as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if (! empty($missing)) {
            /* translators: %s is a comma-separated list of missing PHP extensions. */
            return $this->failure('Required PHP extensions available', sprintf(__('%s extension(s) are missing.', 'whop-woocommerce'), implode(', ', $missing)));
        }

        return $this->success('Required PHP extensions available', __('Required PHP extensions are available.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function checkWooCommerceActive(): array
    {
        if (! function_exists('is_plugin_active') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (! function_exists('is_plugin_active') || ! is_plugin_active('woocommerce/woocommerce.php')) {
            return $this->failure('WooCommerce active', __('WooCommerce is not active.', 'whop-woocommerce'));
        }

        return $this->success('WooCommerce active', __('WooCommerce is active.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function checkWordPressVersion(): array
    {
        global $wp_version;

        if (! isset($wp_version) || version_compare($wp_version, '5.5', '<')) {
            return $this->failure('WordPress version supported', __('WordPress version is not supported. Minimum required is 5.5.', 'whop-woocommerce'));
        }

        return $this->success('WordPress version supported', __('WordPress version is supported.', 'whop-woocommerce'));
    }

    /** @return array<string, string> */
    private function success(string $label, string $message): array
    {
        return [
            'status' => 'ok',
            'label' => $label,
            'message' => $message,
        ];
    }

    /** @return array<string, string> */
    private function warning(string $label, string $message): array
    {
        return [
            'status' => 'warning',
            'label' => $label,
            'message' => $message,
        ];
    }

    /** @return array<string, string> */
    private function failure(string $label, string $message): array
    {
        return [
            'status' => 'error',
            'label' => $label,
            'message' => $message,
        ];
    }
}
