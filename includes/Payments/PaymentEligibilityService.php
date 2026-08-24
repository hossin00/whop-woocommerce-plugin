<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use WC_Order;

/**
 * Applies only documented, server-verifiable public eligibility rules.
 *
 * Whop remains the final authority because processor configuration and buyer
 * IP geography are not available to a native WooCommerce gateway preflight.
 */
final class PaymentEligibilityService
{
    private PaymentMethodRegistry $registry;

    public function __construct(PaymentMethodRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @return array{eligible:bool,whop_method_type:string,reason:string}
     */
    public function evaluateOrder(WC_Order $order, string $gatewayId): array
    {
        return $this->evaluate(
            strtolower((string) $order->get_currency()),
            (float) $order->get_total(),
            strtoupper((string) $order->get_billing_country()),
            $gatewayId
        );
    }

    /**
     * @return array{eligible:bool,whop_method_type:string,reason:string}
     */
    public function evaluateCheckoutContext(string $currency, float $total, string $country, string $gatewayId): array
    {
        return $this->evaluate(strtolower($currency), $total, strtoupper($country), $gatewayId);
    }

    /**
     * @return array{eligible:bool,whop_method_type:string,reason:string}
     */
    private function evaluate(string $currency, float $total, string $country, string $gatewayId): array
    {
        $method = $this->registry->get($gatewayId);

        if (! is_array($method)) {
            return $this->ineligible(__('This payment method is not available.', 'whop-woocommerce'));
        }

        if ($total <= 0.0 || $currency === '') {
            return $this->ineligible(__('This order is not eligible for online payment.', 'whop-woocommerce'));
        }

        if ($gatewayId === PaymentMethodRegistry::CRYPTO && $currency !== 'usd') {
            return $this->ineligible(__('Crypto is currently available for USD orders only.', 'whop-woocommerce'));
        }

        // PayPal remains registered for future re-enablement, but is deliberately
        // unavailable until its merchant payout behavior is officially confirmed.
        if ($gatewayId === PaymentMethodRegistry::PAYPAL) {
            return $this->ineligible(__('PayPal is temporarily unavailable.', 'whop-woocommerce'));
        }

        $whopMethod = $this->registry->resolveWhopMethodType($gatewayId, $country, $currency);

        if ($gatewayId === PaymentMethodRegistry::BANK_TRANSFER && $whopMethod === '') {
            return $this->ineligible(__('Bank Transfer is not available for this billing country and currency.', 'whop-woocommerce'));
        }

        if ($whopMethod === '') {
            $whopMethod = (string) ($method['method_type'] ?? '');
        }

        if ($whopMethod === '') {
            return $this->ineligible(__('This payment method could not be prepared securely.', 'whop-woocommerce'));
        }

        return [
            'eligible' => true,
            'whop_method_type' => $whopMethod,
            'reason' => '',
        ];
    }

    /**
     * @return array{eligible:bool,whop_method_type:string,reason:string}
     */
    private function ineligible(string $reason): array
    {
        return [
            'eligible' => false,
            'whop_method_type' => '',
            'reason' => $reason,
        ];
    }
}
