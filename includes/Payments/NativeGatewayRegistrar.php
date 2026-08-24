<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use Whop\WooCommerce\Helpers\Config;

/**
 * Registers only the approved native Whop gateways with WooCommerce.
 */
final class NativeGatewayRegistrar
{
    private PaymentMethodRegistry $registry;
    private PaymentEligibilityService $eligibility;
    private WhopPaymentAttemptService $attempts;
    private Config $config;

    public function __construct(PaymentMethodRegistry $registry, PaymentEligibilityService $eligibility, WhopPaymentAttemptService $attempts, Config $config)
    {
        $this->registry = $registry;
        $this->eligibility = $eligibility;
        $this->attempts = $attempts;
        $this->config = $config;
    }

    /**
     * @param array<int, mixed> $gateways
     * @return array<int, mixed>
     */
    public function register(array $gateways): array
    {
        foreach ([
            PaymentMethodRegistry::CARD,
            PaymentMethodRegistry::BANK_TRANSFER,
            PaymentMethodRegistry::CRYPTO,
            PaymentMethodRegistry::PAYPAL,
        ] as $gatewayId) {
            $gateways[] = new WhopGateway($this->registry, $this->eligibility, $this->attempts, $this->config, $gatewayId);
        }

        return $gateways;
    }
}
