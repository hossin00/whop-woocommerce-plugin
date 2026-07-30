<?php

namespace Whop\WooCommerce\Checkout;

use InvalidArgumentException;
use RuntimeException;
use WC_Product;
use Whop\WooCommerce\Checkout\RedirectService;
use Whop\WooCommerce\Logger\Logger;

final class Checkout
{
    private CheckoutService $checkoutService;
    private RedirectService $redirectService;
    private Logger $logger;

    public function __construct(CheckoutService $checkoutService, RedirectService $redirectService, Logger $logger)
    {
        $this->checkoutService = $checkoutService;
        $this->redirectService = $redirectService;
        $this->logger = $logger;
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
        $actionUrl = esc_url(admin_url('admin-post.php'));
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(20, false, false);

        printf(
            '<form method="post" action="%s" class="whop-buy-now-form"><input type="hidden" name="action" value="whop_buy_now"><input type="hidden" name="product_id" value="%d">',
            esc_url($actionUrl),
            absint($productId)
        );

        wp_nonce_field('whop_buy_now_' . $productId, 'whop_buy_now_nonce', true, true);

        printf(
            '<input type="hidden" name="whop_buy_now_token" value="%s"><button type="submit" class="button alt">%s</button></form>',
            esc_attr($token),
            esc_html__('Buy Now', 'whop-woocommerce')
        );
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

        $this->logger->log('Buy Now clicked.', ['product_id' => $productId, 'token' => $token], 'INFO');

        if (! wp_verify_nonce($nonce, 'whop_buy_now_' . $productId)) {
            $this->logger->log('Buy Now request failed due to invalid nonce.', ['product_id' => $productId, 'token' => $token], 'ERROR');
            $this->add_notice(__('Invalid request. Please try again.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        if (! $this->is_valid_token($token)) {
            $this->logger->log('Buy Now request failed due to invalid token.', ['product_id' => $productId, 'token' => $token], 'ERROR');
            $this->add_notice(__('Invalid request token. Please refresh the page and try again.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        $existing = $this->get_existing_checkout($token);

        if ($existing !== null) {
            if ($existing['status'] === 'completed' && $existing['checkout_url'] !== '') {
                $this->logger->log('Buy Now refresh detected, redirecting to existing checkout.', ['product_id' => $productId, 'checkout_url' => $existing['checkout_url'], 'checkout_id' => $existing['checkout_id']], 'INFO');
                $this->redirectService->redirectTo($existing['checkout_url'], $existing['checkout_id']);
            }

            $this->logger->log('Buy Now request already in progress.', ['product_id' => $productId, 'token' => $token], 'INFO');
            $this->add_notice(__('Your checkout is already being processed. Please wait a moment and try again.', 'whop-woocommerce'));
            $this->redirect_back();
        }

        $cartSnapshot = $this->get_cart_snapshot();
        $this->store_pending_checkout_token($token, $productId);

        try {
            $checkoutData = $this->checkoutService->create_order_and_checkout($productId);
            $this->store_checkout_token($token, $checkoutData['checkout_url'], $checkoutData['checkout_id']);

            $this->redirectService->redirectTo($checkoutData['checkout_url'], $checkoutData['checkout_id']);
        } catch (InvalidArgumentException $exception) {
            $this->logger->log('Whop Buy Now validation failed.', ['product_id' => $productId, 'error' => $exception->getMessage()], 'ERROR');
            $this->add_notice($exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->log('Whop Buy Now processing failed.', ['product_id' => $productId, 'error' => $exception->getMessage()], 'ERROR');
            $this->add_notice($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->log('Whop Buy Now unexpected error.', ['product_id' => $productId, 'error' => $exception->getMessage()], 'ERROR');
            $this->add_notice(__('Unable to start Whop checkout. Please try again later.', 'whop-woocommerce'));
        }

        $this->restore_cart($cartSnapshot);
        $this->clear_checkout_token($token);
        $this->redirect_back();
    }

    private function is_valid_token(string $token): bool
    {
        return trim($token) !== '';
    }

    /** @return array{status:string, product_id:int, checkout_url:string, checkout_id:string}|null */
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
            ],
            60 * 15
        );
    }

    private function store_checkout_token(string $token, string $checkoutUrl, string $checkoutId): void
    {
        set_transient(
            'whop_buy_now_' . $token,
            [
                'status' => 'completed',
                'checkout_url' => $checkoutUrl,
                'checkout_id' => $checkoutId,
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
        return [];
    }

    private function add_notice(string $message, string $noticeType = 'error'): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, $noticeType);
        }
    }
}
