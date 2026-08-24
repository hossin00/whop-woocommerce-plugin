<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use Whop\WooCommerce\Helpers\Config;

/**
 * One concrete WooCommerce gateway instance for one allowed Whop method.
 */
final class WhopGateway extends AbstractWhopGateway
{
    public function __construct(PaymentMethodRegistry $registry, PaymentEligibilityService $eligibility, WhopPaymentAttemptService $attempts, Config $config, string $gatewayId)
    {
        parent::__construct($registry, $eligibility, $attempts, $config, $gatewayId);
    }
}
