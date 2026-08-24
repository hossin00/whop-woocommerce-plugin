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

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, purchase_url: string, plan: array<string, mixed>, plan_id: string}
     */
    public function create_checkout_configuration(array $payload): array
    {
        $apiKey = $this->get_api_key_for_environment();
        $apiBase = $this->config->get_api_base_url();

        if (empty($apiKey)) {
            throw new \InvalidArgumentException(__('Whop API key is not configured for the selected environment.', 'whop-woocommerce'));
        }

        if (! isset($payload['mode']) || ! is_string($payload['mode']) || trim($payload['mode']) === '') {
            throw new \InvalidArgumentException(__('Checkout mode is required.', 'whop-woocommerce'));
        }

        $response = wp_remote_post(
            $apiBase . '/checkout_configurations',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $apiKey),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        $result = $this->handle_api_response($response, __('Unable to create checkout configuration.', 'whop-woocommerce'));

        $data = $result['data'] ?? [];

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $checkoutId = isset($data['id']) ? sanitize_text_field((string) $data['id']) : '';
        $purchaseUrl = isset($data['purchase_url']) ? esc_url_raw((string) $data['purchase_url']) : '';
        $plan = isset($data['plan']) && is_array($data['plan']) ? $data['plan'] : [];
        $planId = isset($plan['id']) ? sanitize_text_field((string) $plan['id']) : '';

        if ($checkoutId === '') {
            throw new WhopClientTransientException(__('Whop API did not return a checkout configuration ID.', 'whop-woocommerce'));
        }

        return [
            'id' => $checkoutId,
            'purchase_url' => $purchaseUrl,
            'plan' => $plan,
            'plan_id' => $planId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{payment_id:string}
     */
    public function create_saved_card_payment(array $payload): array
    {
        $apiKey = $this->get_api_key_for_environment();
        $apiBase = $this->config->get_api_base_url();

        foreach (['company_id', 'member_id', 'payment_method_id', 'plan'] as $requiredField) {
            if (! array_key_exists($requiredField, $payload) || $payload[$requiredField] === '' || $payload[$requiredField] === null) {
                throw new \InvalidArgumentException(__('Saved Card payment is missing required Whop fields.', 'whop-woocommerce'));
            }
        }

        $response = wp_remote_post(
            $apiBase . '/payments',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $apiKey),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        $result = $this->handle_api_response($response, __('Unable to create the saved Card payment.', 'whop-woocommerce'));
        $data = $result['data'] ?? [];

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $paymentId = is_array($data) && isset($data['id']) ? sanitize_text_field((string) $data['id']) : '';
        if ($paymentId === '') {
            throw new WhopClientTransientException(__('Whop API did not return a payment identifier.', 'whop-woocommerce'));
        }

        return ['payment_id' => $paymentId];
    }

    /** @return array<string, mixed> */
    public function retrieve_plan(string $planId): array
    {
        $apiKey = $this->get_api_key_for_environment();
        $apiBase = $this->config->get_api_base_url();

        if (empty($apiKey)) {
            throw new \InvalidArgumentException(__('Whop API key is not configured for the selected environment.', 'whop-woocommerce'));
        }

        if (trim($planId) === '') {
            throw new \InvalidArgumentException(__('Whop Default Plan ID is not configured.', 'whop-woocommerce'));
        }

        $response = wp_remote_get(
            $apiBase . '/plans/' . rawurlencode($planId),
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $apiKey),
                    'Accept' => 'application/json',
                ],
            ]
        );

        $result = $this->handle_api_response($response, __('Unable to retrieve Whop plan details.', 'whop-woocommerce'));
        $data = $result['data'] ?? [];

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return $data;
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
        $errorCode = $this->extract_error_code($data);
        $errorType = $this->extract_error_type($data);

        if ($this->isTransientStatus($statusCode)) {
            throw new WhopClientTransientException($message);
        }

        $errorContext = '';

        if ($errorCode !== '') {
            $errorContext .= sprintf(' [%s]', $errorCode);
        }

        if ($errorType !== '') {
            $errorContext .= sprintf(' (%s)', $errorType);
        }

        if ($statusCode > 0) {
            $errorContext .= sprintf(' (HTTP %d)', $statusCode);
        }

        throw new WhopClientNonRetryableException(trim($message . $errorContext));
    }

    private function extract_error_message(mixed $data): string
    {
        if (!is_array($data)) {
            return '';
        }

        if (isset($data['error']['message']) && is_string($data['error']['message'])) {
            return sanitize_text_field($data['error']['message']);
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

    private function extract_error_code(mixed $data): string
    {
        if (! is_array($data)) {
            return '';
        }

        if (isset($data['error']['code']) && is_string($data['error']['code'])) {
            return sanitize_text_field($data['error']['code']);
        }

        if (isset($data['code']) && is_string($data['code'])) {
            return sanitize_text_field($data['code']);
        }

        return '';
    }

    private function extract_error_type(mixed $data): string
    {
        if (! is_array($data)) {
            return '';
        }

        if (isset($data['error']['type']) && is_string($data['error']['type'])) {
            return sanitize_text_field($data['error']['type']);
        }

        if (isset($data['type']) && is_string($data['type'])) {
            return sanitize_text_field($data['type']);
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
