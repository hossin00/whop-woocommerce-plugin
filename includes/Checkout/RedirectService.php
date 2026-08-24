<?php

namespace Whop\WooCommerce\Checkout;

use Whop\WooCommerce\Logger\Logger;

final class RedirectService
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function redirectTo(string $checkoutUrl, string $checkoutId, string $token = ''): void
    {
        $redirectUrl = $this->buildRedirectUrl($checkoutUrl, $checkoutId, $token);

        $this->logger->log('Redirecting customer to checkout.', ['checkout_id' => $checkoutId, 'redirect_url' => $redirectUrl], 'INFO');

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function buildRedirectUrl(string $checkoutUrl, string $checkoutId, string $token = ''): string
    {
        $redirectUrl = esc_url_raw(home_url('/checkout/'));

        if ($redirectUrl === '') {
            throw new \RuntimeException(__('Unable to resolve a safe checkout redirect URL.', 'whop-woocommerce'));
        }

        $siteHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $redirectHost = wp_parse_url($redirectUrl, PHP_URL_HOST);

        if (! is_string($siteHost) || ! is_string($redirectHost) || strtolower($siteHost) !== strtolower($redirectHost)) {
            $this->logger->log('Resolved checkout redirect host mismatch.', ['site_host' => $siteHost, 'redirect_host' => $redirectHost], 'ERROR');
            throw new \RuntimeException(__('Unable to resolve a safe checkout redirect URL.', 'whop-woocommerce'));
        }

        $token = sanitize_text_field($token);

        if ($token !== '') {
            $redirectUrl = add_query_arg('whop_token', rawurlencode($token), $redirectUrl);
        }

        return $redirectUrl;
    }
}
