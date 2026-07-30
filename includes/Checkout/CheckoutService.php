<?php

namespace Whop\WooCommerce\Checkout;

use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WC_Product;
use Whop\WooCommerce\API\WhopClient;
use Whop\WooCommerce\Checkout\CheckoutStateService;
use Whop\WooCommerce\Checkout\CheckoutUrlValidator;
use Whop\WooCommerce\Checkout\RetryPolicy;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class CheckoutService
{
    private Config $config;
    private WhopClient $whopClient;
    private Logger $logger;
    private CheckoutStateService $stateService;
    private RetryPolicy $retryPolicy;
    private CheckoutUrlValidator $validator;

    public function __construct(Config $config, WhopClient $whopClient, Logger $logger, CheckoutStateService $stateService, RetryPolicy $retryPolicy, CheckoutUrlValidator $validator)
    {
        $this->config = $config;
        $this->whopClient = $whopClient;
        $this->logger = $logger;
        $this->stateService = $stateService;
        $this->retryPolicy = $retryPolicy;
        $this->validator = $validator;
    }

    public function create_order_for_product(int $productId): WC_Order
    {
        if (! function_exists('wc_get_product') || ! function_exists('wc_create_order')) {
            throw new RuntimeException(__('WooCommerce is not available.', 'whop-woocommerce'));
        }

        $product = wc_get_product($productId);

        if (! $product instanceof WC_Product || ! $product->is_purchasable()) {
            throw new InvalidArgumentException(__('The selected product cannot be purchased.', 'whop-woocommerce'));
        }

        $order = wc_create_order();

        if (! $order instanceof WC_Order) {
            throw new RuntimeException(__('Unable to create the order.', 'whop-woocommerce'));
        }

        $order->add_product($product, 1);
        $order->set_customer_id(get_current_user_id());
        $order->set_status('pending');
        $order->save();

        $this->stateService->setState($order, 'checkout_created');
        $this->logger->log('WooCommerce order created.', ['product_id' => $productId, 'order_id' => $order->get_id(), 'state' => 'checkout_created'], 'INFO');

        return $order;
    }

    public function create_checkout_link(WC_Order $order): string
    {
        $apiKey = $this->config->get_active_api_key();
        $planId = $this->config->get('plan_id');
        $orderId = $order->get_id();

        if (trim($apiKey) === '') {
            throw new InvalidArgumentException(__('Whop API key is not configured.', 'whop-woocommerce'));
        }

        if (trim($planId) === '') {
            throw new InvalidArgumentException(__('Whop Default Plan ID is not configured.', 'whop-woocommerce'));
        }

        $metadata = $this->buildCheckoutMetadata($order);

        $this->logger->log('CheckoutService called.', ['order_id' => $orderId, 'metadata' => $metadata], 'INFO');
        $this->logger->log('Whop API request started.', ['order_id' => $orderId, 'plan_id' => $planId], 'INFO');

        try {
            $checkoutUrl = $this->whopClient->create_checkout_link($planId, $metadata);

            $this->logger->log('Checkout URL received.', ['order_id' => $orderId, 'checkout_url' => $checkoutUrl], 'INFO');

            return $checkoutUrl;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->logger->log('Checkout link creation failed.', ['order_id' => $orderId, 'error' => $exception->getMessage()], 'ERROR');
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->log('Checkout link creation failed.', ['order_id' => $orderId, 'error' => $exception->getMessage()], 'ERROR');
            throw new RuntimeException(__('Unable to create the Whop checkout link.', 'whop-woocommerce'), 0, $exception);
        }
    }

    /** @return array{checkout_url: string, checkout_id: string} */
    public function create_order_and_checkout(int $productId): array
    {
        $existingOrder = $this->findExistingPendingOrder($productId);

        if ($existingOrder !== null) {
            $this->logger->log('Reusing existing unpaid order with pending checkout.', ['order_id' => $existingOrder->get_id(), 'product_id' => $productId], 'INFO');

            $checkoutUrl = $this->get_or_create_checkout_url($existingOrder);
            $checkoutId = get_post_meta($existingOrder->get_id(), '_whop_checkout_id', true);

            return [
                'checkout_url' => $checkoutUrl,
                'checkout_id' => is_string($checkoutId) ? $checkoutId : '',
            ];
        }

        $order = $this->create_order_for_product($productId);

        try {
            $checkoutUrl = $this->get_or_create_checkout_url($order);
            $checkoutId = get_post_meta($order->get_id(), '_whop_checkout_id', true);

            return [
                'checkout_url' => $checkoutUrl,
                'checkout_id' => is_string($checkoutId) ? $checkoutId : '',
            ];
        } catch (\Throwable $exception) {
            update_post_meta($order->get_id(), '_whop_checkout_failure_reason', $exception->getMessage());

            if ($this->stateService->canTransition($order, 'failed')) {
                $this->stateService->setState($order, 'failed');
            }

            $this->logger->log('Checkout creation failed and order kept pending.', ['order_id' => $order->get_id(), 'error' => $exception->getMessage()], 'ERROR');
            throw $exception;
        }
    }

    public function get_or_create_checkout_url(WC_Order $order): string
    {
        $orderId = $order->get_id();

        if ($this->has_valid_checkout($order)) {
            $checkoutUrl = get_post_meta($orderId, '_whop_checkout_url', true);
            $checkoutId = get_post_meta($orderId, '_whop_checkout_id', true);

            $this->logger->log('Reusing existing checkout URL from order metadata.', ['order_id' => $orderId, 'checkout_id' => $checkoutId, 'checkout_url' => $checkoutUrl], 'INFO');

            if ($this->stateService->canTransition($order, 'waiting_payment')) {
                $this->stateService->setState($order, 'waiting_payment');
            }

            return (string) $checkoutUrl;
        }

        $checkoutUrl = (string) $this->retryPolicy->execute(function () use ($order): string {
            return $this->create_checkout_link($order);
        });

        if (! $this->validator->isValidCheckoutUrl($checkoutUrl)) {
            $this->logger->log('Checkout URL validation failed.', ['order_id' => $orderId, 'checkout_url' => $checkoutUrl], 'ERROR');
            throw new RuntimeException(__('The returned checkout URL is invalid for this domain.', 'whop-woocommerce'));
        }

        $checkoutIdentifier = $this->extract_checkout_identifier($checkoutUrl);

        if ($checkoutIdentifier === '') {
            throw new RuntimeException(__('Invalid checkout identifier returned from Whop.', 'whop-woocommerce'));
        }

        update_post_meta($orderId, '_whop_checkout_id', $checkoutIdentifier);
        update_post_meta($orderId, '_whop_checkout_url', $checkoutUrl);
        update_post_meta($orderId, '_whop_plan_id', $this->config->get('plan_id'));
        update_post_meta($orderId, '_whop_order_id', $orderId);
        update_post_meta($orderId, '_whop_checkout_status', 'pending');
        update_post_meta($orderId, '_whop_checkout_created_at', current_time('timestamp', true));

        $this->stateService->setState($order, 'waiting_payment');
        $this->logger->log('Created new Whop checkout URL and stored order metadata.', ['order_id' => $orderId, 'checkout_id' => $checkoutIdentifier, 'checkout_url' => $checkoutUrl, 'checkout_status' => 'pending', 'state' => 'waiting_payment'], 'INFO');

        return $checkoutUrl;
    }

    private function has_valid_checkout(WC_Order $order): bool
    {
        if (! $this->isOrderUnpaid($order)) {
            $this->logger->log('Order is not unpaid; skipping checkout reuse.', ['order_id' => $order->get_id(), 'order_status' => $order->get_status()], 'INFO');
            return false;
        }

        $checkoutId = get_post_meta($order->get_id(), '_whop_checkout_id', true);
        $checkoutUrl = get_post_meta($order->get_id(), '_whop_checkout_url', true);
        $checkoutStatus = get_post_meta($order->get_id(), '_whop_checkout_status', true);

        if (! is_string($checkoutId) || $checkoutId === '') {
            return false;
        }

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            return false;
        }

        if ($checkoutStatus !== 'pending') {
            $this->logger->log('Checkout metadata exists but checkout status is not pending.', ['order_id' => $order->get_id(), 'checkout_status' => $checkoutStatus], 'INFO');
            return false;
        }

        return $this->validator->isValidCheckoutUrl($checkoutUrl);
    }

    private function isOrderUnpaid(WC_Order $order): bool
    {
        return ! $order->is_paid();
    }

    private function findExistingPendingOrder(int $productId): ?WC_Order
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'status' => ['pending', 'failed'],
            'meta_key' => '_whop_checkout_status',
            'meta_value' => 'pending',
            'meta_compare' => '=',
            'product_id' => $productId,
        ]);

        if (empty($orders)) {
            return null;
        }

        $order = reset($orders);

        if (! $order instanceof WC_Order) {
            return null;
        }

        if (! $this->has_valid_checkout($order)) {
            return null;
        }

        return $order;
    }

    /** @return array<string, string> */
    private function buildCheckoutMetadata(WC_Order $order): array
    {
        $productId = $this->extractProductId($order);
        $customerEmail = $this->extractCustomerEmail($order);
        $customerName = $this->extractCustomerName($order);

        $metadata = [
            'order_id' => (string) $order->get_id(),
            'product_id' => $productId !== null ? (string) $productId : '',
        ];

        if ($customerEmail !== '') {
            $metadata['customer_email'] = $customerEmail;
        }

        if ($customerName !== '') {
            $metadata['customer_name'] = $customerName;
        }

        return $metadata;
    }

    private function extractProductId(WC_Order $order): ?int
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if ($product instanceof WC_Product) {
                return $product->get_id();
            }

            if (isset($item['product_id'])) {
                return absint($item['product_id']);
            }
        }

        return null;
    }

    private function extractCustomerEmail(WC_Order $order): string
    {
        $email = trim((string) $order->get_billing_email());

        if ($email !== '') {
            return sanitize_email($email);
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();

            if ($user->exists() && $user->user_email !== '') {
                return sanitize_email($user->user_email);
            }
        }

        return '';
    }

    private function extractCustomerName(WC_Order $order): string
    {
        $firstName = sanitize_text_field(wp_unslash((string) $order->get_billing_first_name()));
        $lastName = sanitize_text_field(wp_unslash((string) $order->get_billing_last_name()));

        if ($firstName !== '' || $lastName !== '') {
            return trim($firstName . ' ' . $lastName);
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();

            if ($user->exists()) {
                $name = sanitize_text_field(wp_unslash((string) $user->display_name));

                if ($name !== '') {
                    return $name;
                }

                $name = trim(sanitize_text_field(wp_unslash((string) $user->user_firstname)) . ' ' . sanitize_text_field(wp_unslash((string) $user->user_lastname)));

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    private function extract_checkout_identifier(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);

        if ($path === null) {
            return '';
        }

        $identifier = trim($path, '/');
        $parts = explode('/', $identifier);

        return rawurlencode((string) end($parts));
    }

}
