<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use RuntimeException;
use WC_Order;
use Whop\WooCommerce\Checkout\CheckoutService;
use Whop\WooCommerce\Logger\Logger;

/**
 * Creates one server-side Whop checkout attempt per immutable native-order
 * fingerprint and safely reuses it on double-submit or retry.
 */
final class WhopPaymentAttemptService
{
    private const FINGERPRINT_META = '_whop_native_attempt_fingerprint';
    private const GATEWAY_META = '_whop_native_gateway_id';
    private const METHOD_META = '_whop_native_payment_method_type';

    private CheckoutService $checkoutService;
    private PaymentEligibilityService $eligibility;
    private CardSetupService $cardSetupService;
    private Logger $logger;

    public function __construct(CheckoutService $checkoutService, PaymentEligibilityService $eligibility, CardSetupService $cardSetupService, Logger $logger)
    {
        $this->checkoutService = $checkoutService;
        $this->eligibility = $eligibility;
        $this->cardSetupService = $cardSetupService;
        $this->logger = $logger;
    }

    /**
     * @return array{checkout_id:string,plan_id:string,whop_method_type:string}
     */
    public function getOrCreate(WC_Order $order, string $gatewayId, string $returnUrl): array
    {
        $eligibility = $this->eligibility->evaluateOrder($order, $gatewayId);

        if (! $eligibility['eligible']) {
            throw new RuntimeException($eligibility['reason']);
        }

        $methodType = $eligibility['whop_method_type'];
        $fingerprint = $this->fingerprint($order, $gatewayId, $methodType);
        if ($gatewayId === 'whop_card' && $methodType === 'card') {
            return $this->cardSetupService->getOrCreateSetup($order, $fingerprint, $returnUrl);
        }

        if ($gatewayId === 'whop_bank_transfer' && $methodType === 'eu_bank_transfer') {
            $order->update_meta_data('_whop_bank_deferred_fingerprint', $fingerprint);
            $order->update_meta_data('_whop_bank_deferred_state', 'pending_initialization');
            $order->update_meta_data('_whop_native_gateway_id', $gatewayId);
            $order->update_meta_data('_whop_payment_method_type', $methodType);
            $order->save();
            return ['checkout_id' => '', 'plan_id' => '', 'whop_method_type' => $methodType];
        }

        $storedFingerprint = (string) $order->get_meta(self::FINGERPRINT_META, true);
        $storedGateway = (string) $order->get_meta(self::GATEWAY_META, true);
        $storedMethod = (string) $order->get_meta(self::METHOD_META, true);
        $storedCheckoutId = (string) $order->get_meta('_whop_checkout_id', true);
        $storedPlanId = (string) $order->get_meta('_whop_plan_id', true);

        if ($storedCheckoutId !== ''
            && $storedFingerprint !== ''
            && hash_equals($storedFingerprint, $fingerprint)
            && hash_equals($storedGateway, $gatewayId)
            && hash_equals($storedMethod, $methodType)
            && ! $order->is_paid()) {
            $this->logger->log('Reusing native Whop payment attempt.', ['order_id' => $order->get_id(), 'gateway_id' => $gatewayId], 'INFO');

            return [
                'checkout_id' => $storedCheckoutId,
                'plan_id' => $storedPlanId,
                'whop_method_type' => $methodType,
            ];
        }

        $result = $this->checkoutService->create_native_gateway_checkout($order, $methodType, $gatewayId, $returnUrl, $fingerprint);

        $order->update_meta_data(self::FINGERPRINT_META, $fingerprint);
        $order->update_meta_data(self::GATEWAY_META, $gatewayId);
        $order->update_meta_data('_whop_native_gateway_id', $gatewayId);
        $order->update_meta_data(self::METHOD_META, $methodType);
        $order->update_meta_data('_whop_checkout_id', $result['checkout_id']);
        $order->update_meta_data('_whop_plan_id', $result['plan_id']);
        $order->update_meta_data('_whop_payment_method_type', $methodType);
        $order->save();

        return [
            'checkout_id' => $result['checkout_id'],
            'plan_id' => $result['plan_id'],
            'whop_method_type' => $methodType,
        ];
    }

    /** @return array{checkout_id:string,plan_id:string,whop_method_type:string}|null */
    public function initializeDeferredBank(WC_Order $order, string $returnUrl): ?array
    {
        if ((string) $order->get_meta('_whop_bank_deferred_state', true) !== 'pending_initialization') {
            return null;
        }
        $fingerprint = sanitize_text_field((string) $order->get_meta('_whop_bank_deferred_fingerprint', true));
        if ($fingerprint === '') {
            return null;
        }
        try {
            $result = $this->checkoutService->create_native_gateway_checkout($order, 'eu_bank_transfer', 'whop_bank_transfer', $returnUrl, $fingerprint);
            $order->update_meta_data('_whop_checkout_id', $result['checkout_id']);
            $order->update_meta_data('_whop_plan_id', $result['plan_id']);
            $order->update_meta_data('_whop_bank_deferred_state', 'ready');
            $order->save();
            return ['checkout_id' => $result['checkout_id'], 'plan_id' => $result['plan_id'], 'whop_method_type' => 'eu_bank_transfer'];
        } catch (\Throwable $exception) {
            $order->update_meta_data('_whop_bank_deferred_state', 'provider_rejected');
            $order->update_meta_data('_whop_bank_deferred_reason', sanitize_text_field(substr($exception->getMessage(), 0, 300)));
            $order->save();
            $this->logger->log('Deferred Bank initialization rejected.', ['order_id' => $order->get_id(), 'gateway' => 'whop_bank_transfer'], 'ERROR');
            return null;
        }
    }

    private function fingerprint(WC_Order $order, string $gatewayId, string $methodType): string
    {
        $items = [];

        foreach ($order->get_items('line_item') as $item) {
            $items[] = [
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity' => (int) $item->get_quantity(),
                'total' => (string) $item->get_total(),
                'tax' => (string) $item->get_total_tax(),
            ];
        }

        return hash('sha256', wp_json_encode([
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'total' => wc_format_decimal($order->get_total(), 2),
            'currency' => strtolower((string) $order->get_currency()),
            'shipping_total' => wc_format_decimal($order->get_shipping_total(), 2),
            'tax_total' => wc_format_decimal($order->get_total_tax(), 2),
            'gateway_id' => $gatewayId,
            'whop_method_type' => $methodType,
            'items' => $items,
        ]));
    }
}
