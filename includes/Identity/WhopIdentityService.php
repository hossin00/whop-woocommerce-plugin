<?php

namespace Whop\WooCommerce\Identity;

use InvalidArgumentException;
use RuntimeException;
use Whop\WooCommerce\Logger\Logger;

final class WhopIdentityService
{
    private const PAYMENT_OPTION_PREFIX = 'whop_wc_payment_identity_';
    private const CHECKOUT_OPTION_PREFIX = 'whop_wc_checkout_identity_';
    private const PAYMENT_FULFILLMENT_OPTION_PREFIX = 'whop_wc_payment_fulfillment_';
    private const SETUP_INTENT_OPTION_PREFIX = 'whop_wc_setup_intent_identity_';

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function claimPayment(string $paymentId, int $orderId): bool
    {
        $normalizedPaymentId = trim($paymentId);

        if ($normalizedPaymentId === '') {
            throw new InvalidArgumentException(esc_html__('Whop payment ID is required for identity claiming.', 'whop-woocommerce'));
        }

        $optionName = $this->get_payment_option_name($normalizedPaymentId);
        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

            if ($existingOrderId === $orderId) {
                return true;
            }

            $this->logger->log('Whop payment identity conflict detected.', [
                'payment_id' => $normalizedPaymentId,
                'candidate_order_id' => $orderId,
                'owner_order_id' => $existingOrderId,
            ], 'ERROR');

            return false;
        }

        $created = add_option($optionName, [
            'order_id' => $orderId,
            'claimed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], '', 'no');

        if ($created === true) {
            return true;
        }

        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

            if ($existingOrderId === $orderId) {
                return true;
            }

            $this->logger->log('Whop payment identity claim persistence conflict.', [
                'payment_id' => $normalizedPaymentId,
                'candidate_order_id' => $orderId,
                'owner_order_id' => $existingOrderId,
            ], 'ERROR');

            return false;
        }

        $this->logger->log('Whop payment identity persistence failed.', [
            'payment_id' => $normalizedPaymentId,
            'order_id' => $orderId,
        ], 'ERROR');

        throw new RuntimeException(esc_html__('Unable to persist Whop payment identity claim.', 'whop-woocommerce'));
    }

    public function claimSetupIntent(string $setupIntentId, int $orderId): bool
    {
        $normalizedSetupIntentId = trim($setupIntentId);

        if ($normalizedSetupIntentId === '') {
            throw new InvalidArgumentException(esc_html__('Whop setup intent ID is required for identity claiming.', 'whop-woocommerce'));
        }

        $optionName = self::SETUP_INTENT_OPTION_PREFIX . substr(sha1($normalizedSetupIntentId), 0, 24);
        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

            if ($existingOrderId === $orderId) {
                return true;
            }

            $this->logger->log('Whop setup intent identity conflict detected.', [
                'setup_intent_id' => $normalizedSetupIntentId,
                'candidate_order_id' => $orderId,
                'owner_order_id' => $existingOrderId,
            ], 'ERROR');

            return false;
        }

        $created = add_option($optionName, [
            'order_id' => $orderId,
            'claimed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], '', 'no');

        if ($created === true) {
            return true;
        }

        $record = get_option($optionName);
        $existingOrderId = is_array($record) && isset($record['order_id']) ? (int) $record['order_id'] : 0;

        if ($existingOrderId === $orderId) {
            return true;
        }

        $this->logger->log('Whop setup intent identity persistence failed.', [
            'setup_intent_id' => $normalizedSetupIntentId,
            'order_id' => $orderId,
        ], 'ERROR');

        throw new RuntimeException(esc_html__('Unable to persist Whop setup intent identity claim.', 'whop-woocommerce'));
    }

    public function claimCheckout(string $checkoutId, int $orderId): bool

    {
        $normalizedCheckoutId = trim($checkoutId);

        if ($normalizedCheckoutId === '') {
            throw new InvalidArgumentException(esc_html__('Whop checkout ID is required for identity claiming.', 'whop-woocommerce'));
        }

        $optionName = $this->get_checkout_option_name($normalizedCheckoutId);
        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

            if ($existingOrderId === $orderId) {
                return true;
            }

            $this->logger->log('Whop checkout identity conflict detected.', [
                'checkout_id' => $normalizedCheckoutId,
                'candidate_order_id' => $orderId,
                'owner_order_id' => $existingOrderId,
            ], 'ERROR');

            return false;
        }

        $created = add_option($optionName, [
            'order_id' => $orderId,
            'claimed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], '', 'no');

        if ($created === true) {
            return true;
        }

        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

            if ($existingOrderId === $orderId) {
                return true;
            }

            $this->logger->log('Whop checkout identity claim persistence conflict.', [
                'checkout_id' => $normalizedCheckoutId,
                'candidate_order_id' => $orderId,
                'owner_order_id' => $existingOrderId,
            ], 'ERROR');

            return false;
        }

        $this->logger->log('Whop checkout identity persistence failed.', [
            'checkout_id' => $normalizedCheckoutId,
            'order_id' => $orderId,
        ], 'ERROR');

        throw new RuntimeException(esc_html__('Unable to persist Whop checkout identity claim.', 'whop-woocommerce'));
    }

    public function validatePaymentOwnership(string $paymentId, int $orderId): bool
    {
        $normalizedPaymentId = trim($paymentId);

        if ($normalizedPaymentId === '') {
            return true;
        }

        $record = get_option($this->get_payment_option_name($normalizedPaymentId));

        if (! is_array($record)) {
            return true;
        }

        $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

        if ($existingOrderId === $orderId) {
            return true;
        }

        $this->logger->log('Whop payment identity ownership mismatch.', [
            'payment_id' => $normalizedPaymentId,
            'candidate_order_id' => $orderId,
            'owner_order_id' => $existingOrderId,
        ], 'ERROR');

        return false;
    }

    public function claimPaymentFulfillment(string $paymentId, int $orderId): bool
    {
        $normalizedPaymentId = trim($paymentId);

        if ($normalizedPaymentId === '') {
            return true;
        }

        $optionName = $this->get_payment_fulfillment_option_name($normalizedPaymentId);
        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;
            $status = isset($record['status']) ? (string) $record['status'] : '';

            if ($existingOrderId === $orderId) {
                $this->logger->log('Whop payment fulfillment already claimed for the same order.', [
                    'payment_id' => $normalizedPaymentId,
                    'order_id' => $orderId,
                    'status' => $status,
                ], 'INFO');

                return false;
            }

            if ($existingOrderId > 0) {
                $this->logger->log('Whop payment fulfillment conflict detected.', [
                    'payment_id' => $normalizedPaymentId,
                    'candidate_order_id' => $orderId,
                    'owner_order_id' => $existingOrderId,
                    'status' => $status,
                ], 'ERROR');

                return false;
            }
        }

        $created = add_option($optionName, [
            'order_id' => $orderId,
            'status' => 'claimed',
            'claimed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], '', 'no');

        if ($created === true) {
            return true;
        }

        $record = get_option($optionName);

        if (is_array($record)) {
            $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

            if ($existingOrderId === $orderId) {
                return true;
            }

            $this->logger->log('Whop payment fulfillment claim persistence conflict.', [
                'payment_id' => $normalizedPaymentId,
                'candidate_order_id' => $orderId,
                'owner_order_id' => $existingOrderId,
            ], 'ERROR');

            return false;
        }

        return false;
    }

    public function markPaymentFulfillmentCompleted(string $paymentId, int $orderId): bool
    {
        $normalizedPaymentId = trim($paymentId);

        if ($normalizedPaymentId === '') {
            return true;
        }

        $optionName = $this->get_payment_fulfillment_option_name($normalizedPaymentId);
        $record = get_option($optionName);

        if (! is_array($record)) {
            $created = add_option($optionName, [
                'order_id' => $orderId,
                'status' => 'completed',
                'claimed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ], '', 'no');

            return $created === true;
        }

        $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

        if ($existingOrderId !== $orderId) {
            return false;
        }

        $record['status'] = 'completed';
        $record['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');

        return update_option($optionName, $record) === true;
    }

    public function validateCheckoutOwnership(string $checkoutId, int $orderId): bool
    {
        $normalizedCheckoutId = trim($checkoutId);

        if ($normalizedCheckoutId === '') {
            return true;
        }

        $record = get_option($this->get_checkout_option_name($normalizedCheckoutId));

        if (! is_array($record)) {
            return true;
        }

        $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

        if ($existingOrderId === $orderId) {
            return true;
        }

        $this->logger->log('Whop checkout identity ownership mismatch.', [
            'checkout_id' => $normalizedCheckoutId,
            'candidate_order_id' => $orderId,
            'owner_order_id' => $existingOrderId,
        ], 'ERROR');

        return false;
    }

    public function getPaymentOwnerOrderId(string $paymentId): ?int
    {
        $normalizedPaymentId = trim($paymentId);

        if ($normalizedPaymentId === '') {
            return null;
        }

        $record = get_option($this->get_payment_option_name($normalizedPaymentId));

        if (! is_array($record)) {
            return null;
        }

        $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

        return $existingOrderId > 0 ? $existingOrderId : null;
    }

    public function getCheckoutOwnerOrderId(string $checkoutId): ?int
    {
        $normalizedCheckoutId = trim($checkoutId);

        if ($normalizedCheckoutId === '') {
            return null;
        }

        $record = get_option($this->get_checkout_option_name($normalizedCheckoutId));

        if (! is_array($record)) {
            return null;
        }

        $existingOrderId = isset($record['order_id']) ? (int) $record['order_id'] : 0;

        return $existingOrderId > 0 ? $existingOrderId : null;
    }

    private function get_payment_option_name(string $paymentId): string
    {
        return self::PAYMENT_OPTION_PREFIX . substr(sha1($paymentId), 0, 24);
    }

    private function get_checkout_option_name(string $checkoutId): string
    {
        return self::CHECKOUT_OPTION_PREFIX . substr(sha1($checkoutId), 0, 24);
    }

    private function get_payment_fulfillment_option_name(string $paymentId): string
    {
        return self::PAYMENT_FULFILLMENT_OPTION_PREFIX . substr(sha1($paymentId), 0, 24);
    }
}
