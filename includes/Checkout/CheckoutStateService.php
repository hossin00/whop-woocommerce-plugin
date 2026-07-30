<?php

namespace Whop\WooCommerce\Checkout;

use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use Whop\WooCommerce\Logger\Logger;

final class CheckoutStateService
{
    private const META_KEY = '_whop_checkout_state';
    private const STATES = [
        'pending',
        'checkout_created',
        'waiting_payment',
        'paid',
        'completed',
        'failed',
        'expired',
    ];

    private const TRANSITIONS = [
        'pending' => ['checkout_created'],
        'checkout_created' => ['waiting_payment', 'failed', 'expired'],
        'waiting_payment' => ['paid', 'failed', 'expired'],
        'paid' => ['completed', 'failed'],
        'completed' => [],
        'failed' => [],
        'expired' => [],
    ];

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function getState(WC_Order $order): string
    {
        $state = get_post_meta($order->get_id(), self::META_KEY, true);

        if (! is_string($state) || $state === '') {
            return 'pending';
        }

        return $state;
    }

    public function canTransition(WC_Order $order, string $nextState): bool
    {
        $currentState = $this->getState($order);

        if ($currentState === $nextState) {
            return false;
        }

        if (! in_array($nextState, self::STATES, true)) {
            return false;
        }

        return in_array($nextState, self::TRANSITIONS[$currentState] ?? [], true);
    }

    public function setState(WC_Order $order, string $nextState): bool
    {
        if (! in_array($nextState, self::STATES, true)) {
            $this->logger->log('Rejected invalid checkout state.', ['order_id' => $order->get_id(), 'state' => $nextState], 'ERROR');
            /* translators: %s is the invalid checkout state value supplied by the caller. */
            throw new InvalidArgumentException(sprintf(__('Invalid checkout state %s.', 'whop-woocommerce'), $nextState));
        }

        $currentState = $this->getState($order);

        if ($currentState === $nextState) {
            return false;
        }

        if (! $this->canTransition($order, $nextState)) {
            $this->logger->log('Rejected invalid checkout state transition.', ['order_id' => $order->get_id(), 'from' => $currentState, 'to' => $nextState], 'ERROR');
            /* translators: %1$s is the current checkout state and %2$s is the requested next state. */
            throw new RuntimeException(sprintf(__('Cannot transition checkout state from %1$s to %2$s.', 'whop-woocommerce'), $currentState, $nextState));
        }

        update_post_meta($order->get_id(), self::META_KEY, $nextState);
        $this->logger->log('Checkout state updated.', ['order_id' => $order->get_id(), 'from' => $currentState, 'to' => $nextState], 'INFO');

        return true;
    }
}
