<?php

namespace Whop\WooCommerce\API;

use Whop\WooCommerce\API\Exceptions\WhopClientNonRetryableException;
use Whop\WooCommerce\API\Exceptions\WhopClientTransientException;
use Whop\WooCommerce\Helpers\Config;

final class WhopClient
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return array{success: bool, message: string, code?: int, data?: array<string, mixed>}
     */
    public function test_connection(): array
    {
        $apiKey = $this->get_api_key_for_environment();

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => __('API key is not configured for the selected environment.', 'whop-woocommerce'),
                'code' => 0,
            ];
        }

        $apiBase = $this->config->get_api_base_url();

        try {
            $response = wp_remote_get(
                $apiBase . '/me',
                [
                    'timeout' => 15,
                    'headers' => [
                        'Authorization' => sprintf('Bearer %s', $apiKey),
                        'Accept' => 'application/json',
                    ],
                ]
            );

            return $this->handle_api_response($response, __('Unable to validate the API key.', 'whop-woocommerce'));
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 0,
            ];
        } catch (WhopClientTransientException|WhopClientNonRetryableException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 0,
            ];
        }
    }

    /** @param array<string, string> $payload */
    public function create_checkout_link(string $planId, array $payload): string
    {
        $apiKey = $this->get_api_key_for_environment();
        $apiBase = $this->config->get_api_base_url();

        if (empty($apiKey)) {
            throw new \InvalidArgumentException(__('Whop API key is not configured for the selected environment.', 'whop-woocommerce'));
        }

        if (empty($planId)) {
            throw new \InvalidArgumentException(__('Whop Default Plan ID is not configured.', 'whop-woocommerce'));
        }

        $requestBody = [
            'plan_id' => $planId,
            'metadata' => $payload,
        ];

        $response = wp_remote_post(
            $apiBase . '/checkout-links',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $apiKey),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($requestBody),
            ]
        );

        $result = $this->handle_api_response($response, __('Unable to create checkout link.', 'whop-woocommerce'));

        if (! isset($result['data']['url'])) {
            throw new WhopClientTransientException(__('Whop API did not return a checkout URL.', 'whop-woocommerce'));
        }

        return esc_url_raw($result['data']['url']);
    }

    /**
     * @param mixed $response
     * @return array{success: bool, message: string, code?: int, data?: array<string, mixed>}
     */
    private function handle_api_response($response, string $fallbackMessage): array
    {
        if (is_wp_error($response)) {
            $message = $response->get_error_message() ?: $fallbackMessage;
            throw new WhopClientTransientException($message);
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode >= 200 && $statusCode < 300 && is_array($data)) {
            return [
                'success' => true,
                'message' => __('Request succeeded.', 'whop-woocommerce'),
                'code' => $statusCode,
                'data' => $data,
            ];
        }

        $message = $this->extract_error_message($data) ?: $fallbackMessage;

        if ($this->isTransientStatus($statusCode)) {
            throw new WhopClientTransientException($message);
        }

        throw new WhopClientNonRetryableException($message);
    }

    private function extract_error_message(mixed $data): string
    {
        if (!is_array($data)) {
            return '';
        }

        if (isset($data['error_description'])) {
            return sanitize_text_field($data['error_description']);
        }

        if (isset($data['message'])) {
            return sanitize_text_field($data['message']);
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            return sanitize_text_field((string) reset($data['errors']));
        }

        return '';
    }

    private function isTransientStatus(int $statusCode): bool
    {
        return in_array($statusCode, [429, 500, 502, 503, 504], true);
    }

    private function get_api_key_for_environment(): string
    {
        $isSandboxMode = $this->config->is_sandbox_mode();
        $productionKey = trim($this->config->get('api_key'));
        $sandboxKey = trim($this->config->get('sandbox_api_key'));

        if ($isSandboxMode) {
            if ($sandboxKey === '') {
                throw new \InvalidArgumentException(__('Sandbox mode is enabled but no sandbox API key is configured.', 'whop-woocommerce'));
            }

            return $sandboxKey;
        }

        if ($productionKey === '') {
            throw new \InvalidArgumentException(__('Production mode is enabled but no production API key is configured.', 'whop-woocommerce'));
        }

        return $productionKey;
    }
}
