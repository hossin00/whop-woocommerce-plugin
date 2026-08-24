<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use RuntimeException;
use Throwable;
use WC_Order;
use Whop\WooCommerce\Checkout\CheckoutService;
use Whop\WooCommerce\Identity\WhopIdentityService;
use Whop\WooCommerce\Logger\Logger;
use Whop\WooCommerce\Webhooks\WebhookIdempotencyService;

/**
 * Card-only, two-stage Whop flow.
 *
 * Stage one creates a Whop checkout configuration in setup mode with no plan
 * and no amount. Stage two is reached only from a verified
 * setup_intent.succeeded webhook and creates one saved-card payment.
 */
final class CardSetupService
{
    private const GATEWAY_ID = 'whop_card';
    private const META_ATTEMPT = '_whop_card_setup_attempt_id';
    private const META_CHECKOUT = '_whop_card_setup_checkout_id';
    private const META_COMPANY = '_whop_card_setup_company_id';
    private const META_SETUP_INTENT = '_whop_card_setup_intent_id';
    private const META_MEMBER = '_whop_card_member_id';
    private const META_METHOD = '_whop_card_payment_method_id';
    private const META_STATE = '_whop_card_payment_state';
    private const META_PAYMENT = '_whop_card_deferred_payment_id';
    private const META_LOCK = '_whop_card_charge_lock';
    private const META_TOTAL = '_whop_card_expected_total';
    private const META_CURRENCY = '_whop_card_expected_currency';

    private CheckoutService $checkoutService;
    private WhopIdentityService $identityService;
    private WebhookIdempotencyService $idempotencyService;
    private Logger $logger;

    public function __construct(
        CheckoutService $checkoutService,
        WhopIdentityService $identityService,
        WebhookIdempotencyService $idempotencyService,
        Logger $logger
    ) {
        $this->checkoutService = $checkoutService;
        $this->identityService = $identityService;
        $this->idempotencyService = $idempotencyService;
        $this->logger = $logger;
    }

    /**
     * @return array{checkout_id:string,plan_id:string,whop_method_type:string}
     */
    public function getOrCreateSetup(WC_Order $order, string $attemptId, string $returnUrl): array
    {
        $attemptId = sanitize_text_field($attemptId);
        $storedAttempt = sanitize_text_field((string) $order->get_meta(self::META_ATTEMPT, true));
        $storedCheckout = sanitize_text_field((string) $order->get_meta(self::META_CHECKOUT, true));

        if (! $order->is_paid()
            && $attemptId !== ''
            && $storedCheckout !== ''
            && $storedAttempt !== ''
            && hash_equals($storedAttempt, $attemptId)) {
            $this->logger->log('Reusing Whop Card setup checkout.', [
                'order_id' => $order->get_id(),
                'checkout_id' => $storedCheckout,
            ], 'INFO');

            return [
                'checkout_id' => $storedCheckout,
                'plan_id' => '',
                'whop_method_type' => 'card',
            ];
        }

        if ($storedCheckout !== '' && $storedAttempt !== '' && ! hash_equals($storedAttempt, $attemptId)) {
            throw new RuntimeException(esc_html__('The pending Card setup no longer matches this order. Please contact the store.', 'whop-woocommerce'));
        }

        $result = $this->checkoutService->createCardSetupCheckout($order, $returnUrl, $attemptId);
        $checkoutId = sanitize_text_field($result['checkout_id']);
        $companyId = sanitize_text_field($result['company_id']);

        if ($checkoutId === '' || $companyId === '') {
            throw new RuntimeException(esc_html__('Whop did not return a complete Card setup checkout.', 'whop-woocommerce'));
        }

        if (! $this->identityService->claimCheckout($checkoutId, $order->get_id())) {
            throw new RuntimeException(esc_html__('The Whop Card setup checkout belongs to a different order.', 'whop-woocommerce'));
        }

        $order->update_meta_data(self::META_ATTEMPT, $attemptId);
        $order->update_meta_data(self::META_CHECKOUT, $checkoutId);
        $order->update_meta_data(self::META_COMPANY, $companyId);
        $order->update_meta_data(self::META_STATE, 'setup_pending');
        $order->update_meta_data(self::META_TOTAL, wc_format_decimal($order->get_total(), 2));
        $order->update_meta_data(self::META_CURRENCY, strtolower((string) $order->get_currency()));
        // NativePaymentPage deliberately keeps this established key and will render the real Whop checkout.
        $order->update_meta_data('_whop_checkout_id', $checkoutId);
        $order->update_meta_data('_whop_plan_id', '');
        $order->update_meta_data('_whop_native_gateway_id', self::GATEWAY_ID);
        $order->update_meta_data('_whop_native_payment_method_type', 'card');
        $order->save();

        $this->logger->log('Whop Card setup checkout created without a plan.', [
            'order_id' => $order->get_id(),
            'checkout_id' => $checkoutId,
        ], 'INFO');

        return [
            'checkout_id' => $checkoutId,
            'plan_id' => '',
            'whop_method_type' => 'card',
        ];
    }

    /** @param array<string,mixed> $payload */
    public function handleSetupSucceeded(array $payload, string $webhookId): void
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        $orderId = absint((string) ($metadata['wc_order_id'] ?? ''));
        $attemptId = sanitize_text_field((string) ($metadata['wc_payment_attempt'] ?? ''));
        $gatewayId = sanitize_text_field((string) ($metadata['wc_gateway_id'] ?? ''));
        $setupIntentId = sanitize_text_field((string) ($data['id'] ?? ''));
        $checkoutId = sanitize_text_field((string) ($data['checkout_configuration']['id'] ?? $data['checkout_configuration_id'] ?? ''));
        $companyId = sanitize_text_field((string) ($data['company_id'] ?? $data['account_id'] ?? $data['company']['id'] ?? ''));
        $memberId = sanitize_text_field((string) ($data['member']['id'] ?? $data['member_id'] ?? ''));
        $paymentMethodId = sanitize_text_field((string) ($data['payment_method']['id'] ?? $data['payment_method_id'] ?? ''));
        $paymentMethodType = sanitize_text_field((string) ($data['payment_method']['type'] ?? $data['payment_method']['payment_method_type'] ?? $data['payment_method_type'] ?? ''));

        if ($orderId <= 0 || $attemptId === '' || $gatewayId !== self::GATEWAY_ID || $setupIntentId === '' || $checkoutId === '' || $memberId === '' || $paymentMethodId === '') {
            throw new RuntimeException(esc_html__('Whop Card setup webhook is missing required identity fields.', 'whop-woocommerce'));
        }

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (! $order instanceof WC_Order || $order->is_paid()) {
            throw new RuntimeException(esc_html__('Whop Card setup does not belong to an unpaid WooCommerce order.', 'whop-woocommerce'));
        }

        $this->validateSetupOwnership($order, $attemptId, $checkoutId, $companyId, $paymentMethodType);

        if (! $this->identityService->claimSetupIntent($setupIntentId, $order->get_id())) {
            throw new RuntimeException(esc_html__('Whop Card setup intent belongs to a different WooCommerce order.', 'whop-woocommerce'));
        }

        $existingSetupIntent = sanitize_text_field((string) $order->get_meta(self::META_SETUP_INTENT, true));
        if ($existingSetupIntent !== '' && ! hash_equals($existingSetupIntent, $setupIntentId)) {
            throw new RuntimeException(esc_html__('Whop Card setup intent does not match the pending order.', 'whop-woocommerce'));
        }

        $order->update_meta_data(self::META_SETUP_INTENT, $setupIntentId);
        $order->update_meta_data(self::META_MEMBER, $memberId);
        $order->update_meta_data(self::META_METHOD, $paymentMethodId);
        $order->update_meta_data(self::META_STATE, 'setup_succeeded');
        $order->update_meta_data('_whop_card_setup_completed_at', gmdate('c'));
        $order->save();

        $this->idempotencyService->updateContext($webhookId, 'setup_intent.succeeded', $order->get_id(), '', $checkoutId);
        $this->createDeferredPaymentOnce($order, $attemptId, $webhookId);
    }

    private function validateSetupOwnership(WC_Order $order, string $attemptId, string $checkoutId, string $companyId, string $paymentMethodType): void
    {
        if (sanitize_text_field((string) $order->get_meta('_whop_native_gateway_id', true)) !== self::GATEWAY_ID) {
            throw new RuntimeException(esc_html__('Whop Card setup gateway does not match the WooCommerce order.', 'whop-woocommerce'));
        }

        $expectedAttempt = sanitize_text_field((string) $order->get_meta(self::META_ATTEMPT, true));
        $expectedCheckout = sanitize_text_field((string) $order->get_meta(self::META_CHECKOUT, true));
        $expectedCompany = sanitize_text_field((string) $order->get_meta(self::META_COMPANY, true));

        if ($expectedAttempt === '' || ! hash_equals($expectedAttempt, $attemptId)
            || $expectedCheckout === '' || ! hash_equals($expectedCheckout, $checkoutId)) {
            throw new RuntimeException(esc_html__('Whop Card setup metadata does not match the pending WooCommerce order.', 'whop-woocommerce'));
        }

        if ($companyId !== '' && $expectedCompany !== '' && ! hash_equals($expectedCompany, $companyId)) {
            throw new RuntimeException(esc_html__('Whop Card setup company does not match the pending WooCommerce order.', 'whop-woocommerce'));
        }

        if ($paymentMethodType !== '' && $paymentMethodType !== 'card') {
            throw new RuntimeException(esc_html__('Whop Card setup did not return a Card payment method.', 'whop-woocommerce'));
        }
    }

    private function createDeferredPaymentOnce(WC_Order $order, string $attemptId, string $webhookId): void
    {
        $existingPayment = sanitize_text_field((string) $order->get_meta(self::META_PAYMENT, true));
        if ($existingPayment !== '') {
            $this->logger->log('Whop saved-card payment already exists; setup webhook is idempotent.', [
                'order_id' => $order->get_id(),
                'payment_id' => $existingPayment,
                'webhook_id' => $webhookId,
            ], 'INFO');
            $this->idempotencyService->markProcessed($webhookId, 'setup_intent.succeeded', $order->get_id());
            return;
        }

        if (! add_post_meta($order->get_id(), self::META_LOCK, $attemptId, true)) {
            $this->logger->log('Whop saved-card charge is already locked; setup webhook is idempotent.', [
                'order_id' => $order->get_id(),
                'webhook_id' => $webhookId,
            ], 'INFO');
            $this->idempotencyService->markProcessed($webhookId, 'setup_intent.succeeded', $order->get_id());
            return;
        }

        $order->update_meta_data(self::META_STATE, 'charge_creating');
        $order->save();

        try {
            $this->validateOrderSnapshot($order);
            $payment = $this->checkoutService->createSavedCardPayment(
                $order,
                sanitize_text_field((string) $order->get_meta(self::META_COMPANY, true)),
                sanitize_text_field((string) $order->get_meta(self::META_MEMBER, true)),
                sanitize_text_field((string) $order->get_meta(self::META_METHOD, true)),
                $attemptId
            );
            $paymentId = sanitize_text_field($payment['payment_id']);

            if ($paymentId === '') {
                throw new RuntimeException(esc_html__('Whop did not return a payment identifier for the saved Card charge.', 'whop-woocommerce'));
            }

            if (! $this->identityService->claimPayment($paymentId, $order->get_id())) {
                throw new RuntimeException(esc_html__('Whop payment identifier belongs to a different WooCommerce order.', 'whop-woocommerce'));
            }

            $order->update_meta_data(self::META_PAYMENT, $paymentId);
            $order->update_meta_data('_whop_payment_id', $paymentId);
            $order->update_meta_data(self::META_STATE, 'charge_pending');
            $order->delete_meta_data('_whop_card_failure_class');
            $order->delete_meta_data('_whop_card_provider_diagnostic');
            $order->save();
            $order->add_order_note(esc_html__('Card details were saved securely by Whop. The payment is being processed.', 'whop-woocommerce'));

            $this->idempotencyService->markProcessed($webhookId, 'setup_intent.succeeded', $order->get_id());
            $this->logger->log('Whop saved-card payment created after verified setup.', [
                'order_id' => $order->get_id(),
                'payment_id' => $paymentId,
                'webhook_id' => $webhookId,
            ], 'INFO');
        } catch (Throwable $exception) {
            $diagnostic = sanitize_text_field(substr($exception->getMessage(), 0, 500));
            $order->update_meta_data(self::META_STATE, 'charge_rejected');
            $order->update_meta_data('_whop_card_failure_class', 'provider_charge_rejected');
            $order->update_meta_data('_whop_card_provider_diagnostic', $diagnostic);
            $order->save();
            $order->add_order_note(esc_html__('Whop could not complete the saved Card charge. The order remains unpaid.', 'whop-woocommerce'));
            $this->idempotencyService->markProcessed($webhookId, 'setup_intent.succeeded', $order->get_id());
            $this->logger->log('Whop saved-card payment creation failed after setup.', [
                'order_id' => $order->get_id(),
                'webhook_id' => $webhookId,
                'failure_class' => 'provider_charge_rejected',
                'diagnostic' => $diagnostic,
            ], 'ERROR');
        }
    }

    private function validateOrderSnapshot(WC_Order $order): void
    {
        $expectedTotal = sanitize_text_field((string) $order->get_meta(self::META_TOTAL, true));
        $expectedCurrency = strtolower(sanitize_text_field((string) $order->get_meta(self::META_CURRENCY, true)));
        $currentTotal = wc_format_decimal($order->get_total(), 2);
        $currentCurrency = strtolower((string) $order->get_currency());

        if ($expectedTotal === '' || $expectedCurrency === ''
            || ! hash_equals($expectedTotal, $currentTotal)
            || ! hash_equals($expectedCurrency, $currentCurrency)) {
            throw new RuntimeException(esc_html__('The WooCommerce order changed after Card setup and cannot be charged automatically.', 'whop-woocommerce'));
        }
    }
}
