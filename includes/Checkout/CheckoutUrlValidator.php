<?php

namespace Whop\WooCommerce\Checkout;

use Whop\WooCommerce\Helpers\Config;

final class CheckoutUrlValidator
{
    private const REQUIRED_PATH_PREFIX = '/checkout/';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function isCustomDomainUrl(string $checkoutUrl): bool
    {
        $allowedHost = $this->getAllowedHost();

        if ($allowedHost === '') {
            return false;
        }

        return $this->isValidCheckoutUrl($checkoutUrl, $allowedHost);
    }

    public function isValidCheckoutUrl(string $checkoutUrl, ?string $expectedHost = null): bool
    {
        if (trim($checkoutUrl) === '') {
            return false;
        }

        $parsed = wp_parse_url($checkoutUrl);

        if (! is_array($parsed)) {
            return false;
        }

        if (empty($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        if (empty($parsed['host'])) {
            return false;
        }

        if ($expectedHost !== null && strtolower($parsed['host']) !== strtolower($expectedHost)) {
            return false;
        }

        if (empty($parsed['path']) || stripos($parsed['path'], self::REQUIRED_PATH_PREFIX) !== 0) {
            return false;
        }

        return true;
    }

    public function getAllowedHost(): string
    {
        $configured = trim((string) $this->config->get('checkout_domain'));

        if ($configured === '') {
            return '';
        }

        if (stripos($configured, 'http://') === 0 || stripos($configured, 'https://') === 0) {
            $parsed = wp_parse_url($configured);
            return $parsed['host'] ?? '';
        }

        $configured = preg_replace('#^https?://#i', '', $configured);
        $configured = trim($configured, '/');

        return $configured;
    }
}
