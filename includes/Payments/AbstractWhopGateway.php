<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use Exception;
use WC_Order;
use WC_Payment_Gateway;
use Whop\WooCommerce\Helpers\Config;

/**
 * Common offsite WooCommerce gateway behavior for an explicitly allowed Whop
 * payment method. Payment credentials are never collected by WordPress.
 */
abstract class AbstractWhopGateway extends WC_Payment_Gateway
{
    protected PaymentMethodRegistry $registry;
    protected PaymentEligibilityService $eligibility;
    protected WhopPaymentAttemptService $attempts;
    protected Config $config;

    /** @var string */
    protected $gatewayId;

    public function __construct(PaymentMethodRegistry $registry, PaymentEligibilityService $eligibility, WhopPaymentAttemptService $attempts, Config $config, string $gatewayId)
    {
        $definition = $registry->get($gatewayId);

        if (! is_array($definition)) {
            throw new Exception('Unknown Whop gateway definition.');
        }

        $this->registry = $registry;
        $this->eligibility = $eligibility;
        $this->attempts = $attempts;
        $this->config = $config;
        $this->gatewayId = $gatewayId;
        $this->id = $gatewayId;
        $this->method_title = (string) $definition['title'];
        $this->method_description = (string) $definition['description'];
        $this->title = (string) $definition['title'];
        $this->description = (string) $definition['description'];
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->icon = esc_url_raw(WHOP_WOOCOMMERCE_ASSETS_URL . '/images/payments/' . rawurlencode((string) $definition['icon']));
    }

    public function is_available(): bool
    {
        if (trim($this->config->get_active_api_key()) === '' || trim($this->config->get_active_plan_id()) === '') {
            return false;
        }

        if (! parent::is_available()) {
            return false;
        }

        if (! function_exists('WC') || ! WC()->cart) {
            return true;
        }

        $country = WC()->customer ? (string) WC()->customer->get_billing_country() : '';
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $total = (float) WC()->cart->get_total('edit');
        $result = $this->eligibility->evaluateCheckoutContext($currency, $total, $country, $this->gatewayId);

        return $result['eligible'];
    }

    /**
     * @return array{result:string,redirect?:string}
     */
    public function process_payment($orderId): array
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order || $order->is_paid()) {
            wc_add_notice(__('This order is not available for payment.', 'whop-woocommerce'), 'error');

            return ['result' => 'failure'];
        }

        try {
            $paymentUrl = add_query_arg([
                NativePaymentPage::QUERY_ARG => '1',
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ], $order->get_checkout_payment_url(true));
            $this->attempts->getOrCreate($order, $this->gatewayId, $paymentUrl);
            $order->set_payment_method($this);
            $order->set_payment_method_title($this->get_title());
            $order->update_status('pending', __('Awaiting payment confirmation from Whop.', 'whop-woocommerce'));
            $order->save();

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            return [
                'result' => 'success',
                'redirect' => $paymentUrl,
            ];
        } catch (\Throwable $exception) {
            wc_add_notice(esc_html($exception->getMessage()), 'error');

            return ['result' => 'failure'];
        }
    }
}
