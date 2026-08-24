<?php

namespace Whop\WooCommerce\Checkout;

use InvalidArgumentException;
use RuntimeException;
use WC_Product;
use Whop\WooCommerce\Checkout\RedirectService;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class Checkout
{
    private const CHECKOUT_ROUTE = 'checkout';
    private const CHECKOUT_COMPLETE_ROUTE = 'checkout/complete';
    private const CHECKOUT_QUERY_VAR = 'whop_checkout';
    private const CHECKOUT_COMPLETE_QUERY_VAR = 'whop_checkout_complete';
    private const CHECKOUT_COOKIE = 'whop_checkout_ref';
    private const CART_CHECKOUT_QUERY_ARG = 'whop_cart_checkout';
    private const CART_CHECKOUT_SESSION_KEY = 'whop_cart_checkout_context';

    private CheckoutService $checkoutService;
    private RedirectService $redirectService;
    private Logger $logger;
    private Config $config;

    public function __construct(CheckoutService $checkoutService, RedirectService $redirectService, Logger $logger, Config $config)
    {
        $this->checkoutService = $checkoutService;
        $this->redirectService = $redirectService;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function render_buy_now_button(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        global $product;

        if (! $product instanceof WC_Product) {
            return;
        }

        $productId = $product->get_id();
        // Keep the submission on the product request. WooCommerce initializes its
        // cart/session on this frontend lifecycle; admin-post does not provide the
        // same product-form context for a native checkout transition.
        $actionUrl = esc_url(add_query_arg('whop_buy_now', '1', get_permalink($productId)));
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(20, false, false);

        printf(
            '<input type="hidden" name="product_id" value="%d">',
            absint($productId)
        );

        wp_nonce_field('whop_buy_now_' . $productId, 'whop_buy_now_nonce', true, true);

        printf(
            '<input type="hidden" name="whop_buy_now_token" value="%s"><button type="submit" class="button alt whop-buy-now-button" name="whop_buy_now_action" value="1" formaction="%s" formmethod="post">%s</button>',
            esc_attr($token),
            esc_url($actionUrl),
            esc_html__('Buy Now', 'whop-woocommerce')
        );
    }

    /**
     * Handle only the scoped frontend Buy Now POST before WordPress renders the
     * product template. The route remains a normal product URL and never starts
     * a Whop checkout directly.
     */
    public function maybe_handle_native_buy_now(): void
    {
        if (is_admin() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return;
        }

        if ($this->get_query_param('whop_buy_now') !== '1') {
            return;
        }

        $this->handle_buy_now();
    }

    public function handle_buy_now(): void
    {
        $postData = wp_unslash($_POST);

        if (! isset($postData['product_id'], $postData['whop_buy_now_nonce'], $postData['whop_buy_now_token'])) {
            $this->redirect_back();
        }

        $productId = absint($postData['product_id']);
        $nonce = sanitize_text_field($postData['whop_buy_now_nonce']);
        $token = sanitize_text_field($postData['whop_buy_now_token']);

        if (! wp_verify_nonce($nonce, 'whop_buy_now_' . $productId) || ! $this->is_valid_token($token)) {
            $this->add_notice(__('Invalid request. Please refresh the page and try again.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        if (! function_exists('WC') || ! WC()->cart) {
            $this->add_notice(__('WooCommerce cart is unavailable. Please try again.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        $product = wc_get_product($productId);

        if (! $product instanceof WC_Product || ! $product->is_purchasable()) {
            $this->add_notice(__('The selected product is not available for purchase.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        $quantity = isset($postData['quantity']) ? max(1, wc_stock_amount($postData['quantity'])) : 1;
        $variationId = isset($postData['variation_id']) ? absint($postData['variation_id']) : 0;
        $variation = [];

        foreach ($postData as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'attribute_')) {
                $variation[sanitize_title($key)] = wc_clean($value);
            }
        }

        if ($product->is_type('variable') && $variationId < 1) {
            $this->add_notice(__('Please choose product options before using Buy Now.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        if ($variationId > 0) {
            $variationProduct = wc_get_product($variationId);

            if (! $variationProduct instanceof \WC_Product_Variation || (int) $variationProduct->get_parent_id() !== $productId) {
                $this->add_notice(__('The selected product variation is invalid.', 'whop-woocommerce'));
                $this->redirect_back();
            }
        }

        // Preserve v0.1.51 intent: Buy Now adds the selected line to the existing
        // WooCommerce cart; it does not clear unrelated cart lines or create an
        // order/Whop session before Place Order.
        $added = WC()->cart->add_to_cart($productId, $quantity, $variationId, $variation);

        if (! $added) {
            $this->add_notice(__('The selected product could not be added to your cart.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Add a private marker to WooCommerce's normal checkout URL when a cart exists.
     *
     * @param string $checkoutUrl
     */
    public function mark_cart_checkout_url(string $checkoutUrl): string
    {
        // Native mode preserves WooCommerce's configured checkout URL.
        return $checkoutUrl;
    }

    private function handle_cart_checkout_request(): void
    {
        $cartContext = $this->get_cart_checkout_context();

        if ($cartContext === null) {
            $this->add_notice(__('Your cart is empty. Please add a product before checkout.', 'whop-woocommerce'));
            $this->redirect_to_cart();
        }

        $token = $cartContext['token'];
        $existing = $this->get_existing_checkout($token);

        if ($existing !== null) {
            if ($existing['status'] === 'completed' && $existing['checkout_url'] !== '') {
                $this->set_checkout_cookie($token);
                $this->redirectService->redirectTo($existing['checkout_url'], $existing['checkout_id'], $token);
            }

            $this->add_notice(__('Your cart checkout is already being processed. Please wait a moment and try again.', 'whop-woocommerce'));
            $this->redirect_to_cart();
        }

        $this->store_pending_checkout_token($token, 0);

        try {
            $checkoutData = $this->checkoutService->create_order_and_checkout_from_cart($cartContext['signature']);
            $this->store_checkout_token(
                $token,
                $checkoutData['checkout_url'],
                $checkoutData['checkout_id'],
                (int) $checkoutData['order_id'],
                (string) $checkoutData['plan_id'],
                (string) $checkoutData['environment'],
                (string) $checkoutData['return_url']
            );

            $this->set_checkout_cookie($token);
            $this->redirectService->redirectTo($checkoutData['checkout_url'], $checkoutData['checkout_id'], $token);
        } catch (InvalidArgumentException $exception) {
            $this->logger->log('Whop cart checkout validation failed.', ['cart_signature' => $cartContext['signature'], 'error' => $exception->getMessage()], 'ERROR');
            $this->add_notice($exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->log('Whop cart checkout processing failed.', ['cart_signature' => $cartContext['signature'], 'error' => $exception->getMessage()], 'ERROR');
            $this->add_notice($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->log('Whop cart checkout unexpected error.', ['cart_signature' => $cartContext['signature'], 'error' => $exception->getMessage()], 'ERROR');
            $this->add_notice(__('Unable to start Whop checkout. Please try again later.', 'whop-woocommerce'));
        }

        $this->clear_checkout_token($token);
        $this->clear_cart_checkout_context($cartContext['signature'], $token);
        $this->redirect_to_cart();
    }

    /** @return array{signature:string, token:string}|null */
    private function get_cart_checkout_context(): ?array
    {
        if (! function_exists('WC') || ! WC()->cart || ! WC()->session || WC()->cart->is_empty()) {
            return null;
        }

        $cart = WC()->cart;
        $cartHash = (string) $cart->get_cart_hash();
        $total = wc_format_decimal($cart->get_total('edit'), 2);
        $currency = strtolower((string) get_woocommerce_currency());
        $signature = hash('sha256', $cartHash . '|' . $total . '|' . $currency);
        $stored = WC()->session->get(self::CART_CHECKOUT_SESSION_KEY);

        if (is_array($stored)
            && isset($stored['signature'], $stored['token'])
            && is_string($stored['signature'])
            && is_string($stored['token'])
            && hash_equals($signature, $stored['signature'])
            && $this->is_valid_token($stored['token'])) {
            return [
                'signature' => $signature,
                'token' => $stored['token'],
            ];
        }

        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(20, false, false);
        WC()->session->set(self::CART_CHECKOUT_SESSION_KEY, [
            'signature' => $signature,
            'token' => $token,
        ]);

        return [
            'signature' => $signature,
            'token' => $token,
        ];
    }

    private function clear_cart_checkout_context(string $signature, string $token): void
    {
        if (! function_exists('WC') || ! WC()->session) {
            return;
        }

        $stored = WC()->session->get(self::CART_CHECKOUT_SESSION_KEY);

        if (! is_array($stored)
            || ! isset($stored['signature'], $stored['token'])
            || ! is_string($stored['signature'])
            || ! is_string($stored['token'])
            || ! hash_equals($signature, $stored['signature'])
            || ! hash_equals($token, $stored['token'])) {
            return;
        }

        WC()->session->__unset(self::CART_CHECKOUT_SESSION_KEY);
    }

    private function is_cart_checkout_request(): bool
    {
        return $this->get_query_param(self::CART_CHECKOUT_QUERY_ARG) === '1';
    }

    private function redirect_to_cart(): void
    {
        $cartUrl = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/');
        wp_safe_redirect($cartUrl);
        exit;
    }

    private function is_valid_token(string $token): bool
    {
        return trim($token) !== '';
    }

    /** @return array{status:string, product_id:int, checkout_url:string, checkout_id:string, order_id:int, plan_id:string, environment:string, return_url:string}|null */
    private function get_existing_checkout(string $token): ?array
    {
        $data = get_transient('whop_buy_now_' . $token);

        if (! is_array($data)) {
            return null;
        }

        $status = $data['status'] ?? '';

        if (! is_string($status) || $status === '') {
            return null;
        }

        return [
            'status' => $status,
            'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : 0,
            'checkout_url' => isset($data['checkout_url']) ? (string) $data['checkout_url'] : '',
            'checkout_id' => isset($data['checkout_id']) ? (string) $data['checkout_id'] : '',
            'order_id' => isset($data['order_id']) ? (int) $data['order_id'] : 0,
            'plan_id' => isset($data['plan_id']) ? (string) $data['plan_id'] : '',
            'environment' => isset($data['environment']) ? (string) $data['environment'] : '',
            'return_url' => isset($data['return_url']) ? (string) $data['return_url'] : '',
        ];
    }

    private function store_pending_checkout_token(string $token, int $productId): void
    {
        set_transient(
            'whop_buy_now_' . $token,
            [
                'status' => 'pending',
                'product_id' => $productId,
                'checkout_url' => '',
                'checkout_id' => '',
                'order_id' => 0,
                'plan_id' => '',
                'environment' => '',
                'return_url' => '',
            ],
            60 * 15
        );
    }

    private function store_checkout_token(string $token, string $checkoutUrl, string $checkoutId, int $orderId, string $planId, string $environment, string $returnUrl): void
    {
        set_transient(
            'whop_buy_now_' . $token,
            [
                'status' => 'completed',
                'checkout_url' => $checkoutUrl,
                'checkout_id' => $checkoutId,
                'order_id' => $orderId,
                'plan_id' => $planId,
                'environment' => $environment,
                'return_url' => $returnUrl,
            ],
            60 * 15
        );
    }

    private function clear_checkout_token(string $token): void
    {
        delete_transient('whop_buy_now_' . $token);
    }

    private function redirect_back(): void
    {
        $redirectUrl = wp_get_referer();

        if (empty($redirectUrl)) {
            $redirectUrl = wc_get_page_permalink('shop') ?: home_url();
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /** @param array<int, array{product_id:int, quantity:int}> $cartSnapshot */
    private function restore_cart(array $cartSnapshot): void
    {
        if (! function_exists('WC')) {
            return;
        }

        $cart = WC()->cart;

        if (! $cart) {
            return;
        }

        $cart->empty_cart();

        foreach ($cartSnapshot as $item) {
            if (empty($item['product_id']) || empty($item['quantity'])) {
                continue;
            }

            $cart->add_to_cart((int) $item['product_id'], (int) $item['quantity']);
        }
    }

    /** @return array<int, array{product_id:int, quantity:int}> */
    private function get_cart_snapshot(): array
    {
        if (! function_exists('WC')) {
            return [];
        }

        $cart = WC()->cart;

        if (! $cart) {
            return [];
        }

        $snapshot = [];

        foreach ($cart->get_cart() as $cartItem) {
            $snapshot[] = [
                'product_id' => $cartItem['product_id'],
                'quantity' => $cartItem['quantity'],
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<int, mixed> $gateways
     * @return array<int, mixed>
     */
    public function disable_payment_gateways(array $gateways): array
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return $gateways;
        }

        $allowed = ['whop_card', 'whop_bank_transfer', 'whop_crypto'];

        return array_filter($gateways, static function ($gateway) use ($allowed): bool {
            return is_object($gateway)
                && isset($gateway->id)
                && in_array((string) $gateway->id, $allowed, true);
        });
    }

    public function register_rewrite_rules(): void
    {
        // Native mode has no custom Whop checkout route. Flush legacy rules once.
        if (get_option('whop_woocommerce_flush_rewrite') === 'yes') {
            flush_rewrite_rules(false);
            delete_option('whop_woocommerce_flush_rewrite');
        }
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function add_query_vars(array $vars): array
    {
        $vars[] = self::CHECKOUT_QUERY_VAR;
        $vars[] = self::CHECKOUT_COMPLETE_QUERY_VAR;

        return $vars;
    }

    public function handle_checkout_routes(): void
    {
        // Native mode leaves checkout, order-pay and thank-you rendering to WooCommerce.
    }

    private function is_woocommerce_checkout_page(): bool
    {
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return false;
        }

        return ! function_exists('is_order_received_page') || ! is_order_received_page();
    }

    public function enqueue_checkout_assets(): void
    {
        // Payment-method assets are loaded by the native gateway integration.
    }

    /**
     * Determine whether the current request is WooCommerce's configured Cart page.
     *
     * `is_cart()` is preferred. The page-ID fallback covers Cart Block requests
     * where the conditional is not resolved when frontend assets are enqueued.
     */
    private function is_cart_page(): bool
    {
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }

        if (! function_exists('wc_get_page_id') || ! function_exists('is_page')) {
            return false;
        }

        $cartPageId = absint(wc_get_page_id('cart'));

        return $cartPageId > 0 && is_page($cartPageId);
    }

    private function is_checkout_route(): bool
    {
        return (string) get_query_var(self::CHECKOUT_QUERY_VAR) === '1';
    }

    private function is_checkout_complete_route(): bool
    {
        return (string) get_query_var(self::CHECKOUT_COMPLETE_QUERY_VAR) === '1';
    }

    private function render_checkout_route(): void
    {
        status_header(200);
        nocache_headers();

        $cookieToken = $this->get_checkout_cookie();
        $queryToken = $this->get_query_param('whop_token');
        $token = $cookieToken;

        // A Cart transition carries its newly resolved context token in the URL.
        // The marker request has already set the same HttpOnly cookie. Require both
        // values to match so the URL cannot be used to select another session's
        // checkout context. A valid matching token also wins over any stale context.
        if ($queryToken !== '') {
            if ($cookieToken === '' || ! hash_equals($cookieToken, $queryToken)) {
                $this->logger->log('Rejected checkout context token that does not match the session cookie.', ['has_cookie' => $cookieToken !== ''], 'WARNING');
                $token = '';
            } else {
                $token = $queryToken;
                $this->set_checkout_cookie($token);
            }
        }

        $checkoutContext = $this->get_existing_checkout($token);

        $checkoutSession = '';
        $orderId = 0;

        if (is_array($checkoutContext) && $checkoutContext['status'] === 'completed') {
            $checkoutSession = $checkoutContext['checkout_id'];
            $orderId = $checkoutContext['order_id'];
        }

        $planId = $this->config->get_active_plan_id();

        if (is_array($checkoutContext) && $checkoutContext['plan_id'] !== '') {
            $planId = $checkoutContext['plan_id'];
        }

        $environment = $this->config->get_checkout_environment();

        if (is_array($checkoutContext) && $checkoutContext['environment'] !== '') {
            $environment = $checkoutContext['environment'];
        }

        $returnUrl = $this->config->get_checkout_return_url();

        if (is_array($checkoutContext) && $checkoutContext['return_url'] !== '') {
            $returnUrl = $checkoutContext['return_url'];
        }

        $templatePath = WHOP_WOOCOMMERCE_TEMPLATES . '/checkout-embedded.php';

        if (! file_exists($templatePath)) {
            wp_die(esc_html__('Checkout template not found.', 'whop-woocommerce'));
        }

        include $templatePath;
    }

    private function render_checkout_complete_route(): void
    {
        status_header(200);
        nocache_headers();

        $status = $this->resolve_return_status();
        $this->clear_checkout_cookie();
        $templatePath = WHOP_WOOCOMMERCE_TEMPLATES . '/checkout-complete.php';

        if (! file_exists($templatePath)) {
            wp_die(esc_html__('Completion template not found.', 'whop-woocommerce'));
        }

        include $templatePath;
    }

    private function resolve_return_status(): string
    {
        $queryStatus = $this->get_query_param('status');
        $querySuccess = $this->get_query_param('success');
        $queryError = $this->get_query_param('error');

        if (strtolower($queryStatus) === 'success' || strtolower($querySuccess) === 'true' || $querySuccess === '1') {
            return 'success';
        }

        if (strtolower($queryStatus) === 'error' || $queryError !== '') {
            return 'error';
        }

        return 'unknown';
    }

    private function set_checkout_cookie(string $token): void
    {
        $token = sanitize_text_field($token);

        if ($token === '' || headers_sent()) {
            return;
        }

        setcookie(
            self::CHECKOUT_COOKIE,
            $token,
            [
                'expires' => time() + (15 * MINUTE_IN_SECONDS),
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function get_checkout_cookie(): string
    {
        if (! isset($_COOKIE[self::CHECKOUT_COOKIE])) {
            return '';
        }

        $token = sanitize_text_field(wp_unslash($_COOKIE[self::CHECKOUT_COOKIE]));
        return $this->is_valid_token($token) ? $token : '';
    }

    private function clear_checkout_cookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(
            self::CHECKOUT_COOKIE,
            '',
            [
                'expires' => time() - HOUR_IN_SECONDS,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function get_query_param(string $key): string
    {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);

        if (! is_string($value) || $value === '') {
            return '';
        }

        return sanitize_text_field(wp_unslash($value));
    }

    private function add_notice(string $message, string $noticeType = 'error'): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, $noticeType);
        }
    }
}
