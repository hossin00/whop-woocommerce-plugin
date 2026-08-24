<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use WC_Order;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class NativePaymentPage
{
    public const QUERY_ARG = 'whop_native_payment';
    private Config $config;
    private Logger $logger;
    private WhopPaymentAttemptService $attempts;

    public function __construct(Config $config, Logger $logger, WhopPaymentAttemptService $attempts)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->attempts = $attempts;
    }

    public function maybeRender(): void
    {
        if ((string) filter_input(INPUT_GET, self::QUERY_ARG, FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== '1') return;
        $orderId = absint((string) filter_input(INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT));
        $orderKey = sanitize_text_field((string) filter_input(INPUT_GET, 'key', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $order = $orderId > 0 && function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (! $order instanceof WC_Order || $orderKey === '' || ! hash_equals($order->get_order_key(), $orderKey)) { status_header(403); wp_die(esc_html__('This payment link is not valid.', 'whop-woocommerce')); }
        if ($order->is_paid()) { wp_safe_redirect($order->get_checkout_order_received_url()); exit; }
        $returnUrl = $order->get_checkout_payment_url(true);
        if ((string) $order->get_meta('_whop_bank_deferred_state', true) === 'pending_initialization') $this->attempts->initializeDeferredBank($order, $returnUrl);
        if ((string) $order->get_meta('_whop_bank_deferred_state', true) === 'provider_rejected') { wp_safe_redirect($order->get_checkout_order_received_url()); exit; }
        $checkoutId = sanitize_text_field((string) $order->get_meta('_whop_checkout_id', true));
        $gatewayId = sanitize_text_field((string) $order->get_meta('_whop_native_gateway_id', true));
        if ($checkoutId === '' || $gatewayId === '') { status_header(409); wp_die(esc_html__('A payment checkout is not ready for this order.', 'whop-woocommerce')); }
        nocache_headers(); status_header(200);
        $environment = $this->config->get_checkout_environment();
        $templatePath = WHOP_WOOCOMMERCE_TEMPLATES . '/native-payment.php';
        if (! file_exists($templatePath)) wp_die(esc_html__('Payment template not found.', 'whop-woocommerce'));
        include $templatePath; exit;
    }
}
