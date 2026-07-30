<?php

namespace Whop\WooCommerce\Orders;

use InvalidArgumentException;
use RuntimeException;
use Whop\WooCommerce\Checkout\CheckoutStateService;
use Whop\WooCommerce\Identity\WhopIdentityService;
use Whop\WooCommerce\Logger\Logger;
use Whop\WooCommerce\Webhooks\WebhookIdempotencyService;
use WC_Order;

final class OrderHandler
{
    private Logger $logger;
    private CheckoutStateService $stateService;
    private WebhookIdempotencyService $idempotencyService;
    private WhopIdentityService $identityService;

    public function __construct(Logger $logger, CheckoutStateService $stateService, WebhookIdempotencyService $idempotencyService, WhopIdentityService $identityService)
    {
        $this->logger = $logger;
        $this->stateService = $stateService;
        $this->idempotencyService = $idempotencyService;
        $this->identityService = $identityService;
    }

    /** @param array<string, mixed> $payload */
    public function completeFromWebhookPayload(array $payload, string $webhookId): void
    {
        $this->logger->log('Processing payment.succeeded webhook.', ['payload_event' => $payload['event'] ?? '', 'webhook_id' => $webhookId]);

        $paymentId = $this->extractPaymentId($payload);
        $checkoutId = $this->extractCheckoutId($payload);
        $customerEmail = $this->extractCustomerEmailFromPayload($payload);

        $order = $this->recoverOrder($payload, $checkoutId, $paymentId, $customerEmail);

        if (! $this->claimAndValidateIdentity($order, $checkoutId, $paymentId, $webhookId)) {
            throw new RuntimeException(esc_html__('Webhook identity metadata does not match the recovered WooCommerce order.', 'whop-woocommerce'));
        }

        if ($this->isOrderAlreadyPaid($order)) {
            $this->logger->log('Order is already paid, skipping completion.', ['order_id' => $order->get_id()]);
            return;
        }

        if (! $this->identityService->claimPaymentFulfillment($paymentId, $order->get_id())) {
            $this->logger->log('Payment fulfillment claim rejected for a conflicting or already-claimed payment.', ['webhook_id' => $webhookId, 'order_id' => $order->get_id(), 'payment_id' => $paymentId], 'ERROR');
            return;
        }

        $this->idempotencyService->updateContext($webhookId, (string) ($payload['event'] ?? ''), $order->get_id(), $paymentId, $checkoutId);

        $paymentCompleted = false;

        try {
            $this->logger->log('Completing WooCommerce order from Whop webhook.', ['order_id' => $order->get_id(), 'checkout_id' => $checkoutId, 'payment_id' => $paymentId]);

            if ($this->stateService->canTransition($order, 'paid')) {
                $this->stateService->setState($order, 'paid');
            }

            $order->payment_complete($paymentId);
            $paymentCompleted = true;
            $order->add_order_note(sprintf(
                /* translators: %s is the Whop payment ID that completed the order. */
                esc_html__('Whop payment completed with payment ID %s.', 'whop-woocommerce'),
                $paymentId
            ));

            update_post_meta($order->get_id(), '_whop_checkout_id', $checkoutId);
            update_post_meta($order->get_id(), '_whop_payment_id', $paymentId);

            $this->identityService->markPaymentFulfillmentCompleted($paymentId, $order->get_id());

            if ($this->stateService->canTransition($order, 'completed')) {
                $this->stateService->setState($order, 'completed');
            }

            try {
                $this->idempotencyService->markProcessed($webhookId, (string) ($payload['event'] ?? ''), $order->get_id());
            } catch (\Throwable $persistenceException) {
                $this->logger->log('Webhook idempotency completion persistence failed after payment completion.', ['order_id' => $order->get_id(), 'webhook_id' => $webhookId, 'error' => $this->sanitizeErrorMessage($persistenceException->getMessage())], 'ERROR');
                throw new \Exception(esc_html__('Order completed but webhook idempotency state could not be persisted.', 'whop-woocommerce'));
            }

            $this->logger->log('WooCommerce order completed successfully.', ['order_id' => $order->get_id()]);
        } catch (\Throwable $exception) {
            if ($paymentCompleted === true) {
                $this->logger->log('Webhook order completed in WooCommerce but idempotency state update failed.', ['order_id' => $order->get_id(), 'webhook_id' => $webhookId, 'error' => $this->sanitizeErrorMessage($exception->getMessage())], 'ERROR');
            } else {
                if ($this->stateService->canTransition($order, 'failed')) {
                    $this->stateService->setState($order, 'failed');
                }

                $this->idempotencyService->markFailed($webhookId, (string) ($payload['event'] ?? ''), $order->get_id(), $exception->getMessage());
                $this->logger->log('Webhook order processing failed.', ['order_id' => $order->get_id(), 'error' => $this->sanitizeErrorMessage($exception->getMessage()), 'webhook_id' => $webhookId], 'ERROR');
            }

            throw $exception;
        }
    }


    /** @param array<string, mixed> $payload */
    private function extractPaymentId(array $payload): string
    {
        $paymentId = $payload['data']['payment_id'] ?? $payload['data']['id'] ?? null;

        if ($paymentId === null || (string) $paymentId === '') {
            return '';
        }

        return (string) $paymentId;
    }

    /** @param array<string, mixed> $payload */
    private function extractCheckoutId(array $payload): string
    {
        $checkoutId = $payload['data']['checkout_id'] ?? $payload['data']['metadata']['checkout_id'] ?? null;

        if ($checkoutId === null || (string) $checkoutId === '') {
            return '';
        }

        return (string) $checkoutId;
    }


    /** @param array<string, mixed> $payload */
    private function extractOrderIdFromPayload(array $payload): ?int
    {
        $orderId = $payload['data']['metadata']['order_id'] ?? null;

        if ($orderId === null || (string) $orderId === '') {
            return null;
        }

        $orderId = absint($orderId);

        return $orderId > 0 ? $orderId : null;
    }

    /** @param array<string, mixed> $payload */
    private function extractCustomerEmailFromPayload(array $payload): string
    {
        $email = $payload['data']['metadata']['customer_email'] ?? $payload['data']['customer_email'] ?? null;

        if (! is_string($email) || trim($email) === '') {
            return '';
        }

        return sanitize_email($email);
    }

    /** @param array<string, mixed> $payload */
    private function recoverOrder(array $payload, string $checkoutId, string $paymentId, string $customerEmail): WC_Order
    {
        $orderId = $this->extractOrderIdFromPayload($payload);

        if ($orderId !== null) {
            $this->logger->log('Attempting order recovery by metadata order_id.', ['order_id' => $orderId]);
            $order = $this->findOrderById($orderId);
            if ($order !== null) {
                $this->logger->log('Order recovery succeeded by metadata order_id.', ['order_id' => $orderId]);
                return $order;
            }
            $this->logger->log('Order recovery by metadata order_id failed.', ['order_id' => $orderId], 'WARNING');
        }

        if ($checkoutId !== '') {
            $this->logger->log('Attempting order recovery by checkout ID.', ['checkout_id' => $checkoutId]);
            $order = $this->findOrderByCheckoutId($checkoutId);
            if ($order !== null) {
                $this->logger->log('Order recovery succeeded by checkout ID.', ['checkout_id' => $checkoutId, 'order_id' => $order->get_id()]);
                return $order;
            }
            $this->logger->log('Order recovery by checkout ID failed.', ['checkout_id' => $checkoutId], 'WARNING');
        }

        if ($paymentId !== '') {
            $this->logger->log('Attempting order recovery by payment ID.', ['payment_id' => $paymentId]);
            $order = $this->findOrderByPaymentId($paymentId);
            if ($order !== null) {
                $this->logger->log('Order recovery succeeded by payment ID.', ['payment_id' => $paymentId, 'order_id' => $order->get_id()]);
                return $order;
            }
            $this->logger->log('Order recovery by payment ID failed.', ['payment_id' => $paymentId], 'WARNING');
        }

        if ($customerEmail !== '') {
            $this->logger->log('Attempting order recovery by customer email.', ['customer_email' => $customerEmail]);
            $order = $this->findOrderByCustomerEmail($customerEmail);
            if ($order !== null) {
                $this->logger->log('Order recovery succeeded by customer email.', ['customer_email' => $customerEmail, 'order_id' => $order->get_id()]);
                return $order;
            }
            $this->logger->log('Order recovery by customer email failed.', ['customer_email' => $customerEmail], 'WARNING');
        }

        $identifiers = array_filter([
            'order_id' => $orderId !== null ? (string) $orderId : '',
            'checkout_id' => $checkoutId,
            'payment_id' => $paymentId,
            'customer_email' => $customerEmail,
        ]);

        $this->logger->log('Webhook order recovery failed. No matching WooCommerce order found.', ['identifiers' => $identifiers], 'ERROR');

        throw new RuntimeException(sprintf(
            /* translators: %s is a JSON-encoded list of webhook identifiers used to look up the WooCommerce order. */
            esc_html__('Unable to resolve WooCommerce order from webhook metadata. Identifiers: %s', 'whop-woocommerce'),
            wp_json_encode($identifiers)
        ));
    }

    private function findOrderById(int $orderId): ?WC_Order
    {
        if (! function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($orderId);
        return $order instanceof WC_Order ? $order : null;
    }

    private function claimAndValidateIdentity(WC_Order $order, string $checkoutId, string $paymentId, string $webhookId): bool
    {
        $orderId = $order->get_id();
        $existingCheckoutId = (string) get_post_meta($orderId, '_whop_checkout_id', true);
        $existingPaymentId = (string) get_post_meta($orderId, '_whop_payment_id', true);

        if ($checkoutId !== '') {
            $checkoutOwner = $this->identityService->getCheckoutOwnerOrderId($checkoutId);
            if ($checkoutOwner !== null && $checkoutOwner !== $orderId) {
                $this->logger->log('Webhook identity mismatch: checkout ID already belongs to another order.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'checkout_id' => $checkoutId, 'owner_order_id' => $checkoutOwner], 'ERROR');
                return false;
            }

            if ($existingCheckoutId === '') {
                if (! $this->identityService->claimCheckout($checkoutId, $orderId)) {
                    $this->logger->log('Webhook identity mismatch: checkout claim rejected.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'checkout_id' => $checkoutId], 'ERROR');
                    return false;
                }
            } elseif ($existingCheckoutId !== $checkoutId) {
                $this->logger->log('Webhook identity mismatch: checkout ID does not match the recovered order.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'checkout_id' => $checkoutId], 'ERROR');
                return false;
            } elseif ($checkoutOwner === null) {
                if (! $this->identityService->claimCheckout($checkoutId, $orderId)) {
                    $this->logger->log('Webhook identity mismatch: checkout claim rejected for existing order metadata.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'checkout_id' => $checkoutId], 'ERROR');
                    return false;
                }
            }
        }

        if ($paymentId !== '') {
            $paymentOwner = $this->identityService->getPaymentOwnerOrderId($paymentId);
            if ($paymentOwner !== null && $paymentOwner !== $orderId) {
                $this->logger->log('Webhook identity mismatch: payment ID already belongs to another order.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'payment_id' => $paymentId, 'owner_order_id' => $paymentOwner], 'ERROR');
                return false;
            }

            if ($existingPaymentId === '') {
                if (! $this->identityService->claimPayment($paymentId, $orderId)) {
                    $this->logger->log('Webhook identity mismatch: payment claim rejected.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'payment_id' => $paymentId], 'ERROR');
                    return false;
                }
            } elseif ($existingPaymentId !== $paymentId) {
                $this->logger->log('Webhook identity mismatch: payment ID does not match the recovered order.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'payment_id' => $paymentId], 'ERROR');
                return false;
            } elseif ($paymentOwner === null) {
                if (! $this->identityService->claimPayment($paymentId, $orderId)) {
                    $this->logger->log('Webhook identity mismatch: payment claim rejected for existing order metadata.', ['webhook_id' => $webhookId, 'order_id' => $orderId, 'payment_id' => $paymentId], 'ERROR');
                    return false;
                }
            }
        }

        return true;
    }

    private function findOrderByCheckoutId(string $checkoutId): ?WC_Order
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'status' => ['pending', 'failed', 'processing', 'on-hold', 'completed'],
            'meta_key' => '_whop_checkout_id',
            'meta_value' => $checkoutId,
            'meta_compare' => '=',
        ]);

        $order = reset($orders);
        return $order instanceof WC_Order ? $order : null;
    }

    private function findOrderByPaymentId(string $paymentId): ?WC_Order
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'status' => ['pending', 'failed', 'processing', 'on-hold', 'completed'],
            'meta_key' => '_whop_payment_id',
            'meta_value' => $paymentId,
            'meta_compare' => '=',
        ]);

        $order = reset($orders);
        return $order instanceof WC_Order ? $order : null;
    }

    private function findOrderByCustomerEmail(string $customerEmail): ?WC_Order
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'billing_email' => $customerEmail,
            'status' => ['pending', 'failed', 'processing', 'on-hold', 'completed'],
        ]);

        $order = reset($orders);
        return $order instanceof WC_Order ? $order : null;
    }

    private function isOrderAlreadyPaid(WC_Order $order): bool
    {
        return $order->is_paid();
    }

    private function sanitizeErrorMessage(?string $error): string
    {
        if ($error === null || $error === '') {
            return '';
        }

        $sanitized = preg_replace('/(api[_-]?key|webhook[_-]?secret|authorization)/i', '[REDACTED]', $error);

        return is_string($sanitized) ? substr($sanitized, 0, 500) : substr($error, 0, 500);
    }
}
