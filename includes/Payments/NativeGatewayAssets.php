<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

/**
 * Enqueues local native-gateway presentation assets only on payment surfaces.
 */
final class NativeGatewayAssets
{
    public function enqueue(): void
    {
        $isCheckout = function_exists('is_checkout') && is_checkout();
        $isOrderPay = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');

        if (! $isCheckout && ! $isOrderPay) {
            return;
        }

        wp_enqueue_style(
            'whop-woocommerce-native-gateways',
            WHOP_WOOCOMMERCE_ASSETS_URL . '/css/native-gateways.css',
            [],
            WHOP_WOOCOMMERCE_VERSION
        );
    }
}
