<?php

namespace Whop\WooCommerce\Checkout;

use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class RedirectService
{
    private CheckoutUrlValidator $validator;
    private Config $config;
    private Logger $logger;

    public function __construct(CheckoutUrlValidator $validator, Config $config, Logger $logger)
    {
        $this->validator = $validator;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function redirectTo(string $checkoutUrl, string $checkoutId): void
    {
        $redirectUrl = $this->buildRedirectUrl($checkoutUrl, $checkoutId);

        $this->logger->log('Redirecting customer to checkout.', ['checkout_id' => $checkoutId, 'redirect_url' => $redirectUrl], 'INFO');

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function buildRedirectUrl(string $checkoutUrl, string $checkoutId): string
    {
        $checkoutDomain = trim((string) $this->config->get('checkout_domain'));
        $customHost = $this->validator->getAllowedHost();

        if ($checkoutDomain === '' || $customHost === '') {
            $this->logger->log('Checkout domain is missing or invalid; refusing redirect.', ['configured_domain' => $checkoutDomain], 'ERROR');
            throw new \RuntimeException(__('Checkout domain must be configured before redirecting to hosted checkout.', 'whop-woocommerce'));
        }

        if ($checkoutId === '') {
            $this->logger->log('Checkout ID missing; cannot build checkout redirect.', ['checkout_domain' => $checkoutDomain], 'ERROR');
            throw new \RuntimeException(__('Unable to resolve a safe checkout redirect URL.', 'whop-woocommerce'));
        }

        $customCheckoutUrl = sprintf('https://%s/checkout/%s', $customHost, rawurlencode($checkoutId));

        if ($this->validator->isCustomDomainUrl($customCheckoutUrl)) {
            $this->logger->log('Custom checkout domain redirect selected.', ['checkout_domain' => $customHost, 'checkout_id' => $checkoutId, 'redirect_url' => $customCheckoutUrl], 'INFO');

            return $customCheckoutUrl;
        }

        $this->logger->log('Configured checkout domain redirect URL failed validation.', ['configured_domain' => $checkoutDomain, 'checkout_id' => $checkoutId, 'checkout_url' => $checkoutUrl], 'ERROR');
        throw new \RuntimeException(__('Unable to resolve a safe checkout redirect URL.', 'whop-woocommerce'));
    }
}
