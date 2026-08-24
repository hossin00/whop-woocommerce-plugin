<?php

namespace Whop\WooCommerce\Licensing\Providers;

use WP_Error;
use Whop\WooCommerce\Licensing\Interfaces\ILicenseProvider;

/**
 * Provider for the WooCommerce Whop Checkout SaaS licensing API.
 *
 * License keys are sent only in JSON request bodies over HTTPS. They are never
 * appended to URLs, persisted by this provider, or written to diagnostic logs.
 */
final class SaaSLicenseProvider implements ILicenseProvider
{
    private const DEFAULT_BASE_URL = 'https://woocommerce-whop-saas.vercel.app';
    private const REQUEST_TIMEOUT = 15;

    public function activate(string $licenseKey, string $instanceName): array
    {
        $response = $this->request('/api/licenses/activate', [
            'licenseKey' => $licenseKey,
            'domain' => $instanceName,
        ]);

        if (!($response['success'] ?? false)) {
            return $response;
        }

        $data = $response['data'] ?? [];
        $license = is_array($data['license'] ?? null) ? $data['license'] : [];
        $activation = is_array($data['activation'] ?? null) ? $data['activation'] : [];

        return [
            'success' => true,
            'data' => $this->normalizeLicense($license, [
                'instance_id' => (string) ($activation['id'] ?? ''),
            ]),
            'message' => $this->safeMessage($response, __('License activated successfully.', 'whop-woocommerce')),
        ];
    }

    public function deactivate(string $instanceId): array
    {
        // LicenseManager passes a local activation ID here. The SaaS provider uses
        // the key/domain held by LicenseManager, so direct instance-ID deletion is
        // intentionally unsupported to prevent cross-license object references.
        return [
            'success' => false,
            'message' => __('License deactivation requires the stored license context.', 'whop-woocommerce'),
        ];
    }

    /**
     * Deactivation is separate from ILicenseProvider to preserve its historical
     * signature while binding the remote request to key possession and domain.
     */
    public function deactivateForDomain(string $licenseKey, string $domain): array
    {
        $response = $this->request('/api/licenses/plugin/deactivate', [
            'licenseKey' => $licenseKey,
            'domain' => $domain,
        ]);

        return [
            'success' => (bool) ($response['success'] ?? false),
            'data' => is_array($response['data'] ?? null) ? $response['data'] : [],
            'message' => $this->safeMessage($response, __('Unable to deactivate the license.', 'whop-woocommerce')),
        ];
    }

    public function validate(string $licenseKey, string $instanceId): array
    {
        $response = $this->request('/api/licenses/validate', [
            'licenseKey' => $licenseKey,
            'domain' => $instanceId,
        ]);

        if (!($response['success'] ?? false)) {
            return $response;
        }

        $data = $response['data'] ?? [];
        $license = is_array($data['license'] ?? null) ? $data['license'] : [];

        return [
            'success' => true,
            'data' => $this->normalizeLicense($license),
            'message' => $this->safeMessage($response, __('License validation complete.', 'whop-woocommerce')),
        ];
    }

    public function checkForUpdates(string $licenseKey, string $instanceId, string $currentVersion): array
    {
        $response = $this->request('/api/plugins/latest', [
            'licenseKey' => $licenseKey,
            'domain' => $instanceId,
            'currentVersion' => $currentVersion,
        ]);

        if (!($response['success'] ?? false)) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $updatesActive = (bool) ($data['updatesActive'] ?? false);
        $updateAvailable = (bool) ($data['updateAvailable'] ?? false);
        $version = isset($data['version']) && is_string($data['version']) ? $data['version'] : '';

        return [
            'success' => true,
            'data' => [
                'new_version' => $version,
                'update_available' => $updatesActive && $updateAvailable && $version !== '',
                'updates_active' => $updatesActive,
                'core_active' => (bool) ($data['coreActive'] ?? false),
                'support_active' => (bool) ($data['supportActive'] ?? false),
                'updates_until' => (string) ($data['updatesUntil'] ?? ''),
                'support_until' => (string) ($data['supportUntil'] ?? ''),
                'release_notes' => isset($data['releaseNotes']) && is_string($data['releaseNotes']) ? $data['releaseNotes'] : '',
                'reason' => isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : '',
            ],
            'message' => $this->safeMessage($response, __('Update entitlement checked.', 'whop-woocommerce')),
        ];
    }

    /**
     * Obtain a one-use package URL only immediately before WordPress downloads an
     * update. This keeps opaque grant lifetime short and avoids a key in any URL.
     */
    public function requestPackage(string $licenseKey, string $domain, string $version): array
    {
        $response = $this->request('/api/plugins/update-grant', [
            'licenseKey' => $licenseKey,
            'domain' => $domain,
            'version' => $version,
        ]);

        if (!($response['success'] ?? false)) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $downloadPath = $data['downloadUrl'] ?? null;
        if (!is_string($downloadPath) || $downloadPath === '') {
            return [
                'success' => false,
                'message' => __('No private update package is available.', 'whop-woocommerce'),
            ];
        }

        return [
            'success' => true,
            'data' => [
                'package' => $this->absoluteUrl($downloadPath),
                'version' => isset($data['version']) && is_string($data['version']) ? $data['version'] : $version,
                'artifact_sha256' => isset($data['artifactSha256']) && is_string($data['artifactSha256']) ? strtolower($data['artifactSha256']) : '',
            ],
            'message' => __('Private update package authorized.', 'whop-woocommerce'),
        ];
    }

    private function request(string $path, array $payload): array
    {
        $url = $this->absoluteUrl($path);
        if (strpos($url, 'https://') !== 0 && strpos($url, 'http://127.0.0.1:') !== 0 && strpos($url, 'http://localhost:') !== 0) {
            return [
                'success' => false,
                'message' => __('License service configuration is invalid.', 'whop-woocommerce'),
            ];
        }

        $response = wp_remote_post($url, [
            'timeout' => self::REQUEST_TIMEOUT,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Whop-WooCommerce/' . (defined('WHOP_WOOCOMMERCE_VERSION') ? WHOP_WOOCOMMERCE_VERSION : 'unknown'),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if ($response instanceof WP_Error) {
            return [
                'success' => false,
                'message' => __('The license service is temporarily unavailable.', 'whop-woocommerce'),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('The license service returned an invalid response.', 'whop-woocommerce'),
            ];
        }

        if ($status < 200 || $status >= 300 || !($decoded['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $this->safeMessage($decoded, __('License request was not accepted.', 'whop-woocommerce')),
            ];
        }

        return $decoded;
    }

    private function normalizeLicense(array $license, array $extra = []): array
    {
        $siteLimit = isset($license['siteLimit']) && is_int($license['siteLimit']) ? $license['siteLimit'] : null;

        return array_merge([
            'status' => (bool) ($license['coreActive'] ?? false) ? 'active' : 'invalid',
            'license_type' => isset($license['plan']) && is_string($license['plan']) ? strtolower($license['plan']) : 'unknown',
            'support_expiration' => isset($license['supportUntil']) && is_string($license['supportUntil']) ? $license['supportUntil'] : '',
            'updates_expiration' => isset($license['updatesUntil']) && is_string($license['updatesUntil']) ? $license['updatesUntil'] : '',
            'updates_active' => (bool) ($license['updatesActive'] ?? false),
            'support_active' => (bool) ($license['supportActive'] ?? false),
            'core_active' => (bool) ($license['coreActive'] ?? false),
            'sites_used' => isset($license['sitesUsed']) && is_int($license['sitesUsed']) ? $license['sitesUsed'] : 0,
            'site_limit' => $siteLimit,
            'unlimited_sites' => (bool) ($license['unlimitedSites'] ?? false),
        ], $extra);
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
    }

    private function baseUrl(): string
    {
        $configured = defined('WHOP_WC_LICENSE_API_BASE_URL')
            ? (string) WHOP_WC_LICENSE_API_BASE_URL
            : self::DEFAULT_BASE_URL;
        $configured = apply_filters('whop_wc_license_api_base_url', $configured);

        return esc_url_raw((string) $configured);
    }

    private function safeMessage(array $response, string $fallback): string
    {
        $message = $response['message'] ?? $response['error'] ?? $fallback;
        if (!is_string($message) || $message === '') {
            return $fallback;
        }

        return wp_strip_all_tags($message);
    }
}
