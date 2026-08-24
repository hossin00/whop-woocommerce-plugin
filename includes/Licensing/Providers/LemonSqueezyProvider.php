<?php

namespace Whop\WooCommerce\Licensing\Providers;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseProvider;

/**
 * Class LemonSqueezyProvider
 * Production-ready Lemon Squeezy licensing provider.
 * @package Whop\WooCommerce\Licensing\Providers
 */
class LemonSqueezyProvider implements ILicenseProvider
{
    private const API_BASE_URL = 'https://api.lemonsqueezy.com/v1';
    private const TIMEOUT = 15;

    private $apiKey;
    private $storeId;

    public function __construct(string $apiKey, string $storeId)
    {
        $this->apiKey = $apiKey;
        $this->storeId = $storeId;
    }

    public function activate(string $licenseKey, string $instanceName): array
    {
        return $this->request('POST', '/licenses/activate', [
            'license_key' => $licenseKey,
            'instance_name' => $instanceName,
        ]);
    }

    public function deactivate(string $instanceId): array
    {
        return $this->request('POST', '/licenses/deactivate', [
            'instance_id' => $instanceId,
        ]);
    }

    public function validate(string $licenseKey, string $instanceId): array
    {
        return $this->request('POST', '/licenses/validate', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    public function checkForUpdates(string $licenseKey, string $instanceId, string $currentVersion): array
    {
        return $this->request('POST', '/updates/check', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'version' => $currentVersion,
        ]);
    }

    private function request(string $method, string $endpoint, array $data): array
    {
        $url = self::API_BASE_URL . $endpoint;
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        $args = [
            'method' => $method,
            'headers' => $headers,
            'body' => wp_json_encode($data),
            'timeout' => self::TIMEOUT,
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return [
                'success' => false,
                'message' => 'API Error: HTTP ' . $code,
            ];
        }

        $decoded = json_decode($body, true);
        return $decoded ?: ['success' => false, 'message' => 'Invalid response'];
    }
}
