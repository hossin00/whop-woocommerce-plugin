<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use Whop\WooCommerce\Helpers\Config;

/**
 * Registers approved Whop methods with the WooCommerce Checkout Block registry.
 */
final class NativeGatewayBlocksIntegration
{
    private PaymentMethodRegistry $registry;
    private Config $config;

    public function __construct(PaymentMethodRegistry $registry, Config $config)
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * @param object $paymentMethodRegistry
     */
    public function register($paymentMethodRegistry): void
    {
        if (! class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')
            || ! is_object($paymentMethodRegistry)
            || ! method_exists($paymentMethodRegistry, 'register')) {
            return;
        }

        foreach ([
            PaymentMethodRegistry::CARD,
            PaymentMethodRegistry::BANK_TRANSFER,
            PaymentMethodRegistry::CRYPTO,
            PaymentMethodRegistry::PAYPAL,
        ] as $gatewayId) {
            $paymentMethodRegistry->register(new WhopBlocksPaymentMethod($this->registry, $this->config, $gatewayId));
        }
    }
}
