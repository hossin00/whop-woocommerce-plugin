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
        $order->calculate_totals();
        $order->save();

        $this->stateService->setState($order, 'checkout_created');
        $this->logger->log('WooCommerce order created.', ['product_id' => $productId, 'order_id' => $order->get_id(), 'state' => 'checkout_created'], 'INFO');

        return $order;
    }

    /**
     * @return array{checkout_id: string, checkout_url: string, plan_id: string}
     */
    public function create_checkout_configuration(WC_Order $order): array
    {
        $apiKey = $this->config->get_active_api_key();
        $planId = $this->config->get_active_plan_id();
        $returnUrl = $this->config->get_checkout_return_url();
        $orderId = $order->get_id();

        if (trim($apiKey) === '') {
            throw new InvalidArgumentException(__('Whop API key is not configured.', 'whop-woocommerce'));
        }

        if (trim($planId) === '') {
            throw new InvalidArgumentException(__('Whop Default Plan ID is not configured.', 'whop-woocommerce'));
        }

        $metadata = $this->buildCheckoutMetadata($order);
        $plan = $this->whopClient->retrieve_plan($planId);
        $checkoutPayload = $this->buildCheckoutConfigurationPayload($plan, $order, $metadata, $returnUrl);

        $this->logger->log('CheckoutService called.', ['order_id' => $orderId, 'metadata' => $metadata], 'INFO');
        $this->logger->log('Whop API request started.', ['order_id' => $orderId, 'plan_id' => $planId], 'INFO');

        try {
            $checkoutConfiguration = $this->whopClient->create_checkout_configuration($checkoutPayload);
            $checkoutId = sanitize_text_field((string) ($checkoutConfiguration['id'] ?? ''));
            $checkoutUrl = home_url('/checkout/');
            $checkoutPlanId = $this->pickString($checkoutConfiguration, ['plan_id', 'plan.id']);

            if ($checkoutId === '') {
                throw new RuntimeException(__('Whop API did not return a checkout configuration ID.', 'whop-woocommerce'));
            }

            if ($checkoutPlanId === '') {
                throw new RuntimeException(__('Whop API did not return the checkout plan ID.', 'whop-woocommerce'));
            }

            $this->validateCreatedCartPlan($order, $checkoutConfiguration);

            $this->logger->log('Checkout configuration received.', ['order_id' => $orderId, 'checkout_id' => $checkoutId, 'plan_id' => $checkoutPlanId], 'INFO');

            return [
                'checkout_id' => $checkoutId,
                'checkout_url' => esc_url_raw($checkoutUrl),
                'plan_id' => $checkoutPlanId,
            ];
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->logger->log('Checkout configuration creation failed.', ['order_id' => $orderId, 'error' => $exception->getMessage()], 'ERROR');
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->log('Checkout configuration creation failed.', ['order_id' => $orderId, 'error' => $exception->getMessage()], 'ERROR');
            throw new RuntimeException(__('Unable to create the Whop checkout configuration.', 'whop-woocommerce'), 0, $exception);
        }
    }

    /**
     * Create the first Whop Card stage. This payload intentionally has no
     * plan, plan_id, amount, initial_price or payment endpoint call.
     *
     * @return array{checkout_id:string,company_id:string}
     */
    public function createCardSetupCheckout(WC_Order $order, string $returnUrl, string $attemptFingerprint): array
    {
        $apiKey = $this->config->get_active_api_key();
        $sourcePlanId = $this->config->get_active_plan_id();

        if (trim($apiKey) === '' || trim($sourcePlanId) === '') {
            throw new InvalidArgumentException(__('Whop API key and Default Plan ID must be configured.', 'whop-woocommerce'));
        }

        $attemptFingerprint = sanitize_text_field($attemptFingerprint);
        $currency = strtolower((string) $order->get_currency());
        if ($attemptFingerprint === '' || $currency === '') {
            throw new InvalidArgumentException(__('The Card setup order could not be validated.', 'whop-woocommerce'));
        }

        $sourcePlan = $this->whopClient->retrieve_plan($sourcePlanId);
        $companyId = $this->pickString($sourcePlan, ['company.id', 'company_id']);
        if ($companyId === '') {
            throw new RuntimeException(__('Whop source plan is missing its company identifier.', 'whop-woocommerce'));
        }

        $payload = [
            'company_id' => $companyId,
            'mode' => 'setup',
            'currency' => $currency,
            'payment_method_configuration' => [
                'enabled' => ['card'],
                'disabled' => [],
                'include_platform_defaults' => false,
            ],
            'metadata' => [
                'wc_order_id' => (string) $order->get_id(),
                'wc_payment_attempt' => $attemptFingerprint,
                'wc_gateway_id' => 'whop_card',
            ],
            'redirect_url' => esc_url_raw($returnUrl),
        ];

        $checkoutConfiguration = $this->whopClient->create_checkout_configuration($payload);
        $checkoutId = sanitize_text_field((string) ($checkoutConfiguration['id'] ?? ''));

        if ($checkoutId === '') {
            throw new RuntimeException(__('Whop API did not return a Card setup checkout ID.', 'whop-woocommerce'));
        }

        $this->logger->log('Whop Card setup checkout created without a plan.', [
            'order_id' => $order->get_id(),
            'checkout_id' => $checkoutId,
        ], 'INFO');

        return [
            'checkout_id' => $checkoutId,
            'company_id' => $companyId,
        ];
    }

    /**
     * Create one asynchronous Whop payment after a signed setup_intent.succeeded
     * webhook provided the member and Card payment method.
     *
     * @return array{payment_id:string}
     */
    public function createSavedCardPayment(WC_Order $order, string $companyId, string $memberId, string $paymentMethodId, string $attemptFingerprint): array
    {
        $sourcePlanId = $this->config->get_active_plan_id();
        $companyId = sanitize_text_field($companyId);
        $memberId = sanitize_text_field($memberId);
        $paymentMethodId = sanitize_text_field($paymentMethodId);
        $attemptFingerprint = sanitize_text_field($attemptFingerprint);

        if (trim($sourcePlanId) === '' || $companyId === '' || $memberId === '' || $paymentMethodId === '' || $attemptFingerprint === '') {
            throw new InvalidArgumentException(__('The saved Card payment could not be validated.', 'whop-woocommerce'));
        }

        $sourcePlan = $this->whopClient->retrieve_plan($sourcePlanId);
        $sourceCompanyId = $this->pickString($sourcePlan, ['company.id', 'company_id']);
        $productId = $this->pickString($sourcePlan, ['product.id', 'product_id']);
        if ($sourceCompanyId === '' || $productId === '' || ! hash_equals($sourceCompanyId, $companyId)) {
            throw new RuntimeException(__('Whop saved Card payment does not match the configured source mapping.', 'whop-woocommerce'));
        }

        $total = (float) wc_format_decimal($order->get_total(), 2);
        $currency = strtolower((string) $order->get_currency());
        if ($total <= 0 || $currency === '') {
            throw new RuntimeException(__('The WooCommerce order total and currency are required for the saved Card payment.', 'whop-woocommerce'));
        }

        $metadata = $this->buildCheckoutMetadata($order);
        $metadata['wc_gateway_id'] = 'whop_card';
        $metadata['wc_payment_attempt'] = $attemptFingerprint;
        $metadata['wc_payment_flow'] = 'saved_card_after_setup';

        $plan = [
            'company_id' => $companyId,
            'product_id' => $productId,
            'plan_type' => 'one_time',
            'initial_price' => $total,
            'currency' => $currency,
            'force_create_new_plan' => true,
        ];

        if ('yes' === (string) $order->get_meta('_whop_cart_checkout', true)) {
            $plan['override_tax_type'] = 'inclusive';
        }

        $productName = $metadata['wc_product_name'] ?? '';
        $title = $this->buildInlinePlanTitle($order, $productName);
        if ($title !== '') {
            $plan['title'] = $title;
        }

        return $this->whopClient->create_saved_card_payment([
            'company_id' => $companyId,
            'member_id' => $memberId,
            'payment_method_id' => $paymentMethodId,
            'plan' => $plan,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a Whop checkout for a WooCommerce order already created by the
     * native checkout gateway. The selected Whop payment method is server-side
     * validated and is the only enabled method in the configuration.
     *
     * @return array{checkout_id:string,plan_id:string}
     */
    public function create_native_gateway_checkout(WC_Order $order, string $whopMethodType, string $gatewayId, string $returnUrl, string $attemptFingerprint): array
    {
        $apiKey = $this->config->get_active_api_key();
        $sourcePlanId = $this->config->get_active_plan_id();

        if (trim($apiKey) === '' || trim($sourcePlanId) === '') {
            throw new InvalidArgumentException(__('Whop API key and Default Plan ID must be configured.', 'whop-woocommerce'));
        }

        $whopMethodType = sanitize_text_field($whopMethodType);
        $gatewayId = sanitize_text_field($gatewayId);
        $attemptFingerprint = sanitize_text_field($attemptFingerprint);

        if ($whopMethodType === '' || $gatewayId === '' || $attemptFingerprint === '') {
            throw new InvalidArgumentException(__('The selected payment method could not be validated.', 'whop-woocommerce'));
        }

        $metadata = $this->buildCheckoutMetadata($order);
        $metadata['wc_gateway_id'] = $gatewayId;
        $metadata['wc_payment_attempt'] = $attemptFingerprint;
        $plan = $this->whopClient->retrieve_plan($sourcePlanId);
        $payload = $this->buildCheckoutConfigurationPayload($plan, $order, $metadata, $returnUrl);
        $payload['plan']['payment_method_configuration'] = [
            'enabled' => [$whopMethodType],
            'disabled' => [],
            'include_platform_defaults' => false,
        ];

        $checkoutConfiguration = $this->whopClient->create_checkout_configuration($payload);
        $checkoutId = sanitize_text_field((string) ($checkoutConfiguration['id'] ?? ''));
        $checkoutPlanId = $this->pickString($checkoutConfiguration, ['plan_id', 'plan.id']);
        $returnedAmount = $this->pickNumeric($checkoutConfiguration, ['plan.initial_price']);
        $returnedCurrency = strtolower($this->pickString($checkoutConfiguration, ['plan.currency']));
        $expectedAmount = wc_format_decimal($order->get_total(), 2);
        $expectedCurrency = strtolower((string) $order->get_currency());

        if ($checkoutId === '' || $checkoutPlanId === '') {
            throw new RuntimeException(__('Whop API did not return a complete native checkout configuration.', 'whop-woocommerce'));
        }

        if ($returnedAmount !== null && wc_format_decimal($returnedAmount, 2) !== $expectedAmount) {
            throw new RuntimeException(__('Whop returned a checkout amount that does not match the WooCommerce order total.', 'whop-woocommerce'));
        }

        if ($returnedCurrency !== '' && $returnedCurrency !== $expectedCurrency) {
            throw new RuntimeException(__('Whop returned a checkout currency that does not match the WooCommerce order currency.', 'whop-woocommerce'));
        }

        $this->logger->log('Native Whop gateway checkout created.', [
            'order_id' => $order->get_id(),
            'gateway_id' => $gatewayId,
            'whop_method_type' => $whopMethodType,
            'checkout_id' => $checkoutId,
        ], 'INFO');

        return [
            'checkout_id' => $checkoutId,
            'plan_id' => $checkoutPlanId,
        ];
    }

    /** @return array{checkout_url: string, checkout_id: string, order_id: int, plan_id: string, environment: string, return_url: string} */
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
                'order_id' => $existingOrder->get_id(),
                'plan_id' => (string) get_post_meta($existingOrder->get_id(), '_whop_plan_id', true),
                'environment' => $this->config->get_checkout_environment(),
                'return_url' => $this->config->get_checkout_return_url(),
            ];
        }

        $order = $this->create_order_for_product($productId);

        try {
            $checkoutUrl = $this->get_or_create_checkout_url($order);
            $checkoutId = get_post_meta($order->get_id(), '_whop_checkout_id', true);

            return [
                'checkout_url' => $checkoutUrl,
                'checkout_id' => is_string($checkoutId) ? $checkoutId : '',
                'order_id' => $order->get_id(),
                'plan_id' => (string) get_post_meta($order->get_id(), '_whop_plan_id', true),
                'environment' => $this->config->get_checkout_environment(),
                'return_url' => $this->config->get_checkout_return_url(),
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

    /**
     * Create or resume a checkout from the complete current WooCommerce cart.
     *
     * @return array{checkout_url: string, checkout_id: string, order_id: int, plan_id: string, environment: string, return_url: string}
     */
    public function create_order_and_checkout_from_cart(string $cartHash): array
    {
        $cartHash = sanitize_text_field($cartHash);

        if ($cartHash === '') {
            throw new InvalidArgumentException(__('The shopping cart could not be validated.', 'whop-woocommerce'));
        }

        $existingOrder = $this->findExistingPendingCartOrder($cartHash);

        if ($existingOrder !== null) {
            $this->logger->log('Reusing existing cart checkout.', ['order_id' => $existingOrder->get_id(), 'cart_hash' => $cartHash], 'INFO');
            return $this->buildCheckoutResult($existingOrder);
        }

        $order = $this->create_order_from_cart($cartHash);

        try {
            return $this->buildCheckoutResult($order);
        } catch (\Throwable $exception) {
            update_post_meta($order->get_id(), '_whop_checkout_failure_reason', $exception->getMessage());

            if ($this->stateService->canTransition($order, 'failed')) {
                $this->stateService->setState($order, 'failed');
            }

            $this->logger->log('Cart checkout creation failed and order kept pending.', ['order_id' => $order->get_id(), 'cart_hash' => $cartHash, 'error' => $exception->getMessage()], 'ERROR');
            throw $exception;
        }
    }

    /** @return array{checkout_url: string, checkout_id: string, order_id: int, plan_id: string, environment: string, return_url: string} */
    private function buildCheckoutResult(WC_Order $order): array
    {
        $checkoutUrl = $this->get_or_create_checkout_url($order);
        $checkoutId = get_post_meta($order->get_id(), '_whop_checkout_id', true);

        return [
            'checkout_url' => $checkoutUrl,
            'checkout_id' => is_string($checkoutId) ? $checkoutId : '',
            'order_id' => $order->get_id(),
            'plan_id' => (string) get_post_meta($order->get_id(), '_whop_plan_id', true),
            'environment' => $this->config->get_checkout_environment(),
            'return_url' => $this->config->get_checkout_return_url(),
        ];
    }

    private function create_order_from_cart(string $cartHash): WC_Order
    {
        if (! function_exists('WC') || ! function_exists('wc_create_order')) {
            throw new RuntimeException(__('WooCommerce is not available.', 'whop-woocommerce'));
        }

        $cart = WC()->cart;
        $checkout = WC()->checkout();

        if (! $cart || ! $checkout || $cart->is_empty()) {
            throw new InvalidArgumentException(__('Your cart is empty. Please add a product before checkout.', 'whop-woocommerce'));
        }

        $order = wc_create_order(['customer_id' => get_current_user_id()]);

        if (! $order instanceof WC_Order) {
            throw new RuntimeException(__('Unable to create the order.', 'whop-woocommerce'));
        }

        $order->set_created_via('whop_cart_checkout');
        $order->set_customer_id(get_current_user_id());
        $order->set_currency(get_woocommerce_currency());
        $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
        $order->set_customer_ip_address(\WC_Geolocation::get_ip_address());
        $order->set_customer_user_agent(wc_get_user_agent());
        $order->set_cart_hash($cartHash);
        $checkout->set_data_from_cart($order);
        $order->set_status('pending');
        $order->update_meta_data('_whop_cart_checkout', 'yes');
        $order->update_meta_data('_whop_cart_hash', $cartHash);
        $order->save();

        if (count($order->get_items('line_item')) === 0) {
            throw new RuntimeException(__('Order items could not be created from the cart.', 'whop-woocommerce'));
        }

        $this->stateService->setState($order, 'checkout_created');
        $this->logger->log('WooCommerce order created from cart.', [
            'order_id' => $order->get_id(),
            'cart_hash' => $cartHash,
            'line_item_count' => count($order->get_items('line_item')),
            'total' => wc_format_decimal($order->get_total(), 2),
            'currency' => strtolower((string) $order->get_currency()),
        ], 'INFO');

        return $order;
    }

    private function findExistingPendingCartOrder(string $cartHash): ?WC_Order
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }

        // Query a narrow candidate set with supported WC order arguments, then compare
        // the complete signature in PHP. This avoids reusing an unrelated cart when a
        // storage engine does not honor a compound meta_query consistently.
        $orders = wc_get_orders([
            'limit' => -1,
            'status' => ['pending', 'failed'],
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_whop_cart_checkout',
            'meta_value' => 'yes',
            'meta_compare' => '=',
        ]);

        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }

            $storedCartHash = (string) $order->get_meta('_whop_cart_hash', true);
            $checkoutStatus = (string) $order->get_meta('_whop_checkout_status', true);

            if (! hash_equals($cartHash, $storedCartHash) || $checkoutStatus !== 'pending') {
                continue;
            }

            if ($this->has_valid_checkout($order)) {
                return $order;
            }
        }

        return null;
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

        $checkoutData = $this->retryPolicy->execute(function () use ($order): array {
            return $this->create_checkout_configuration($order);
        });

        $checkoutUrl = isset($checkoutData['checkout_url']) ? (string) $checkoutData['checkout_url'] : '';
        $checkoutIdentifier = isset($checkoutData['checkout_id']) ? sanitize_text_field((string) $checkoutData['checkout_id']) : '';
        $checkoutPlanId = isset($checkoutData['plan_id']) ? sanitize_text_field((string) $checkoutData['plan_id']) : '';

        if (! $this->validator->isValidCheckoutUrl($checkoutUrl)) {
            $this->logger->log('Checkout URL validation failed.', ['order_id' => $orderId, 'checkout_url' => $checkoutUrl], 'ERROR');
            throw new RuntimeException(__('The returned checkout URL is invalid for this domain.', 'whop-woocommerce'));
        }

        if ($checkoutIdentifier === '') {
            throw new RuntimeException(__('Invalid checkout identifier returned from Whop.', 'whop-woocommerce'));
        }

        if ($checkoutPlanId === '') {
            throw new RuntimeException(__('Invalid checkout plan identifier returned from Whop.', 'whop-woocommerce'));
        }

        update_post_meta($orderId, '_whop_checkout_id', $checkoutIdentifier);
        update_post_meta($orderId, '_whop_checkout_configuration_id', $checkoutIdentifier);
        update_post_meta($orderId, '_whop_checkout_url', $checkoutUrl);
        update_post_meta($orderId, '_whop_plan_id', $checkoutPlanId);
        update_post_meta($orderId, '_whop_order_id', $orderId);
        update_post_meta($orderId, '_whop_checkout_status', 'pending');
        update_post_meta($orderId, '_whop_checkout_created_at', current_time('timestamp', true));
        update_post_meta($orderId, '_whop_checkout_amount', wc_format_decimal($order->get_total(), 2));
        update_post_meta($orderId, '_whop_checkout_currency', strtolower((string) $order->get_currency()));

        $this->stateService->setState($order, 'waiting_payment');
        $this->logger->log('Created new Whop checkout configuration and stored order metadata.', ['order_id' => $orderId, 'checkout_id' => $checkoutIdentifier, 'checkout_url' => $checkoutUrl, 'checkout_status' => 'pending', 'state' => 'waiting_payment'], 'INFO');

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

        $storedAmount = get_post_meta($order->get_id(), '_whop_checkout_amount', true);
        $storedCurrency = get_post_meta($order->get_id(), '_whop_checkout_currency', true);
        $currentAmount = wc_format_decimal($order->get_total(), 2);
        $currentCurrency = strtolower((string) $order->get_currency());

        if (! is_string($storedAmount) || $storedAmount === '' || ! is_string($storedCurrency) || $storedCurrency === '') {
            return false;
        }

        if ((float) $storedAmount !== (float) $currentAmount || strtolower($storedCurrency) !== $currentCurrency) {
            $this->logger->log('Existing checkout price/currency does not match current order and will not be reused.', [
                'order_id' => $order->get_id(),
                'stored_amount' => $storedAmount,
                'current_amount' => $currentAmount,
                'stored_currency' => $storedCurrency,
                'current_currency' => $currentCurrency,
            ], 'WARNING');
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
        $lineItems = $order->get_items('line_item');
        $lineItemCount = count($lineItems);
        $productId = $this->extractProductId($order);
        $customerEmail = $this->extractCustomerEmail($order);
        $customerName = $this->extractCustomerName($order);
        $productName = $this->extractProductName($order);
        $sku = $this->extractProductSku($order);

        if ($lineItemCount > 1) {
            $productId = null;
            $productName = sprintf(
                _n('%d item', '%d items', $lineItemCount, 'whop-woocommerce'),
                $lineItemCount
            );
            $sku = '';
        }

        $metadata = [
            'wc_order_id' => (string) $order->get_id(),
            'wc_order_key' => (string) $order->get_order_key(),
            'wc_product_id' => $productId !== null ? (string) $productId : '',
            'wc_product_name' => $productName,
            'wc_sku' => $sku,
            'wc_line_item_count' => (string) $lineItemCount,
            'wc_cart_line_summary' => $this->buildCartLineSummary($order),
            'wc_order_subtotal' => wc_format_decimal($order->get_subtotal(), 2),
            'wc_order_discount_total' => wc_format_decimal($order->get_discount_total(), 2),
            'wc_order_shipping_total' => wc_format_decimal($order->get_shipping_total(), 2),
            'wc_order_fee_total' => $this->getOrderFeeTotal($order),
            'wc_order_tax_total' => wc_format_decimal($order->get_total_tax(), 2),
            'wc_order_total' => wc_format_decimal($order->get_total(), 2),
            'wc_order_currency' => strtolower((string) $order->get_currency()),
        ];

        // Legacy compatibility. Do not falsely map a multi-product cart to its first line item.
        $metadata['order_id'] = (string) $order->get_id();
        $metadata['product_id'] = $productId !== null ? (string) $productId : '';

        if ($customerEmail !== '') {
            $metadata['customer_email'] = $customerEmail;
        }

        if ($customerName !== '') {
            $metadata['customer_name'] = $customerName;
        }

        return $metadata;
    }

    private function buildCartLineSummary(WC_Order $order): string
    {
        $lines = [];
        $maxLines = 10;
        $maxLength = 1000;

        foreach ($order->get_items('line_item') as $item) {
            if (count($lines) >= $maxLines) {
                break;
            }

            $product = $item->get_product();
            $productId = $product instanceof WC_Product ? $product->get_id() : (int) $item->get_product_id();
            $variationId = (int) $item->get_variation_id();
            $quantity = max(1, (int) $item->get_quantity());
            $name = sanitize_text_field((string) $item->get_name());
            $name = preg_replace('/\s+/', ' ', $name) ?: '';
            $lines[] = sprintf('%d:%d:%d:%s', $productId, $variationId, $quantity, $name);
        }

        $summary = implode(' | ', $lines);

        if (strlen($summary) > $maxLength) {
            return substr($summary, 0, $maxLength);
        }

        return $summary;
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

    private function extractProductName(WC_Order $order): string
    {
        foreach ($order->get_items() as $item) {
            return (string) $item->get_name();
        }

        return '';
    }

    private function extractProductSku(WC_Order $order): string
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product instanceof WC_Product) {
                return (string) $product->get_sku();
            }
        }

        return '';
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

    /**
     * @param array<string, mixed> $plan
     * @param array<string, string> $metadata
     * @return array<string, mixed>
     */
    private function buildCheckoutConfigurationPayload(array $plan, WC_Order $order, array $metadata, string $returnUrl): array
    {
        $planCompanyId = $this->pickString($plan, ['company.id', 'company_id']);
        $planProductId = $this->pickString($plan, ['product.id', 'product_id']);
        $configuredPlanType = $this->pickString($plan, ['plan_type']);
        $isCartCheckout = 'yes' === (string) $order->get_meta('_whop_cart_checkout', true);
        $planType = $isCartCheckout ? 'one_time' : ($configuredPlanType !== '' ? $configuredPlanType : 'one_time');
        $currency = strtolower((string) $order->get_currency());
        $initialPrice = (float) wc_format_decimal($order->get_total(), 2);

        if ($planCompanyId === '' || $planProductId === '') {
            throw new RuntimeException(__('Whop plan is missing the company or product identifier.', 'whop-woocommerce'));
        }

        if ($initialPrice <= 0) {
            throw new RuntimeException(__('The WooCommerce order total must be greater than zero before creating checkout.', 'whop-woocommerce'));
        }

        if ($currency === '') {
            throw new RuntimeException(__('The WooCommerce order currency is missing.', 'whop-woocommerce'));
        }

        $productName = $metadata['wc_product_name'] ?? '';
        $planTitle = $this->buildInlinePlanTitle($order, $productName);

        $planPayload = [
            'company_id' => $planCompanyId,
            'product_id' => $planProductId,
            'plan_type' => $planType,
            'initial_price' => $initialPrice,
            'currency' => $currency,
            'force_create_new_plan' => true,
        ];

        if ($isCartCheckout) {
            // WooCommerce owns the gross cart total. Inclusive tax prevents Whop
            // from charging a second tax amount on top of that total.
            $planPayload['override_tax_type'] = 'inclusive';
        }

        if ($planTitle !== '') {
            $planPayload['title'] = $planTitle;
        }

        $payload = [
            'mode' => 'payment',
            'plan' => $planPayload,
            'metadata' => $metadata,
            'redirect_url' => esc_url_raw($returnUrl),
        ];

        // Detailed logging for WP_DEBUG
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->logger->log('Whop Checkout Payload created.', [
                'wc_product_name' => $productName,
                'wc_product_id' => $metadata['wc_product_id'] ?? '',
                'wc_sku' => $metadata['wc_sku'] ?? '',
                'payload' => $payload,
            ], 'INFO');
        }

        return $payload;
    }

    private function getOrderFeeTotal(WC_Order $order): string
    {
        $feeTotal = 0.0;

        foreach ($order->get_items('fee') as $fee) {
            $feeTotal += (float) $fee->get_total();
        }

        return wc_format_decimal($feeTotal, 2);
    }

    private function buildInlinePlanTitle(WC_Order $order, string $productName): string
    {
        $lineItemCount = count($order->get_items('line_item'));

        if ($lineItemCount > 1) {
            $productName = sprintf(_n('%d item order', '%d item order', $lineItemCount, 'whop-woocommerce'), $lineItemCount);
        }

        $productName = sanitize_text_field($productName);

        if ($productName === '') {
            $productName = sprintf(__('Order #%d', 'whop-woocommerce'), $order->get_id());
        }

        return function_exists('mb_substr') ? mb_substr($productName, 0, 30) : substr($productName, 0, 30);
    }

    /**
     * @param array<string, mixed> $checkoutConfiguration
     */
    private function validateCreatedCartPlan(WC_Order $order, array $checkoutConfiguration): void
    {
        if ('yes' !== (string) $order->get_meta('_whop_cart_checkout', true)) {
            return;
        }

        $returnedAmount = $this->pickNumeric($checkoutConfiguration, ['plan.initial_price']);
        $returnedCurrency = strtolower($this->pickString($checkoutConfiguration, ['plan.currency']));
        $returnedTaxType = strtolower($this->pickString($checkoutConfiguration, ['plan.tax_type']));
        $expectedAmount = wc_format_decimal($order->get_total(), 2);
        $expectedCurrency = strtolower((string) $order->get_currency());

        if ($returnedAmount !== null && wc_format_decimal($returnedAmount, 2) !== $expectedAmount) {
            throw new RuntimeException(__('Whop returned a checkout amount that does not match the WooCommerce order total.', 'whop-woocommerce'));
        }

        if ($returnedCurrency !== '' && $returnedCurrency !== $expectedCurrency) {
            throw new RuntimeException(__('Whop returned a checkout currency that does not match the WooCommerce order currency.', 'whop-woocommerce'));
        }

        if ($returnedTaxType !== '' && $returnedTaxType !== 'inclusive') {
            throw new RuntimeException(__('Whop did not preserve the inclusive tax setting required for this cart checkout.', 'whop-woocommerce'));
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $paths
     */
    private function pickString(array $source, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->pickValue($source, $path);

            if (is_string($value) && trim($value) !== '') {
                return sanitize_text_field($value);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $paths
     */
    private function pickNumeric(array $source, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = $this->pickValue($source, $path);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $source */
    private function pickValue(array $source, string $path): mixed
    {
        $current = $source;
        $parts = explode('.', $path);

        foreach ($parts as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }

}
