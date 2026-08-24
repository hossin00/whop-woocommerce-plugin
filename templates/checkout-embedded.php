<?php
/**
 * Embedded checkout route template.
 *
 * @var string $checkoutSession
 * @var string $planId
 * @var string $environment
 * @var string $returnUrl
 * @var int $orderId
 */

defined('ABSPATH') || exit;

$hasSession = is_string($checkoutSession) && $checkoutSession !== '';
$hasPlan = is_string($planId) && $planId !== '';
$order = null;
$orderItems = [];
$orderTotal = '';
$orderCurrency = '';
$orderSummaryRows = [];

if ($orderId > 0 && function_exists('wc_get_order')) {
    $order = wc_get_order($orderId);

    if ($order) {
        $orderTotal = $order->get_formatted_order_total();
        $orderCurrency = $order->get_currency();

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $image = '';

            if ($product) {
                $image = $product->get_image('woocommerce_thumbnail', ['loading' => 'lazy']);
            }

            $orderItems[] = [
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'total' => wc_price((float) $item->get_total() + (float) $item->get_total_tax(), ['currency' => $orderCurrency]),
                'image' => $image,
                'sku' => $product ? $product->get_sku() : '',
            ];
        }

        $feeTotal = 0.0;
        foreach ($order->get_items('fee') as $fee) {
            $feeTotal += (float) $fee->get_total();
        }

        $summaryValues = [
            __('Subtotal', 'whop-woocommerce') => (float) $order->get_subtotal(),
            __('Discount', 'whop-woocommerce') => (float) $order->get_discount_total(),
            __('Fees', 'whop-woocommerce') => $feeTotal,
            __('Shipping', 'whop-woocommerce') => (float) $order->get_shipping_total(),
            __('Tax', 'whop-woocommerce') => (float) $order->get_total_tax(),
        ];

        foreach ($summaryValues as $label => $value) {
            if ((float) $value === 0.0) {
                continue;
            }

            $orderSummaryRows[] = [
                'label' => $label,
                'value' => wc_price($value, ['currency' => $orderCurrency]),
                'negative' => $label === __('Discount', 'whop-woocommerce'),
            ];
        }
    }
}

$policyLinks = [];
$privacyUrl = get_privacy_policy_url();
$termsUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('terms') : '';
if (is_string($termsUrl) && $termsUrl !== '') {
    $policyLinks[] = ['label' => __('Terms & Conditions', 'whop-woocommerce'), 'url' => $termsUrl];
}
if (is_string($privacyUrl) && $privacyUrl !== '') {
    $policyLinks[] = ['label' => __('Privacy Policy', 'whop-woocommerce'), 'url' => $privacyUrl];
}

$policyPageSlugs = [
    'shipping-policy',
    'shipping',
    'refund-returns',
    'refund_returns',
    'returns-refunds',
];
foreach ($policyPageSlugs as $slug) {
    $policyPage = get_page_by_path($slug);
    if ($policyPage instanceof WP_Post) {
        $policyUrl = get_permalink($policyPage);
        if (is_string($policyUrl) && $policyUrl !== '') {
            $policyLinks[] = [
                'label' => str_contains($slug, 'shipping') ? __('Shipping Policy', 'whop-woocommerce') : __('Refund & Returns Policy', 'whop-woocommerce'),
                'url' => $policyUrl,
            ];
            break;
        }
    }
}

// Professional SVG Icons
$icons = [
    'lock' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>',
    'shield' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>',
    'check' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
    'credit-card' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>',
    'truck' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>',
];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <script async defer src="https://js.whop.com/static/checkout/loader.js"></script>
</head>
<body <?php body_class('whop-checkout-page'); ?>>
<?php wp_body_open(); ?>
<main class="whop-checkout-shell" aria-label="Checkout">
    
    <!-- HEADER SECTION (Now outside the main grid for alignment) -->
    <div class="whop-checkout-header-v3">
        <div class="whop-checkout-secure-title">
            <span class="whop-icon-lock"><?php echo $icons['lock']; ?></span>
            <?php esc_html_e('SECURE CHECKOUT', 'whop-woocommerce'); ?>
        </div>
        <h1><?php esc_html_e('Complete Your Purchase', 'whop-woocommerce'); ?></h1>
        <p class="whop-checkout-subtitle-v3"><?php esc_html_e('Protected by Stripe encryption.', 'whop-woocommerce'); ?></p>
    </div>

    <div class="whop-checkout-layout-v3">
        
        <!-- LEFT COLUMN: PAYMENT -->
        <div class="whop-checkout-left-col">
            <section class="whop-payment-panel-v3" aria-label="Payment">
                <div class="whop-payment-card-v3">
                    <div class="whop-payment-panel-header-v3">
                        <span class="whop-payment-label-v3"><?php esc_html_e('Payment', 'whop-woocommerce'); ?></span>
                        <span class="whop-payment-secure-badge-v3">
                            <?php echo $icons['lock']; ?>
                            <?php esc_html_e('Stripe Protected', 'whop-woocommerce'); ?>
                        </span>
                    </div>

                    <?php if (! $hasSession && ! $hasPlan) : ?>
                        <div class="whop-checkout-alert whop-checkout-alert-error">
                            <?php esc_html_e('Unable to initialize checkout. Please return to the product page and try again.', 'whop-woocommerce'); ?>
                        </div>
                    <?php else : ?>
                        <div
                            id="whop-embedded-checkout"
                            data-whop-checkout-environment="<?php echo esc_attr($environment); ?>"
                            data-whop-checkout-return-url="<?php echo esc_url($returnUrl); ?>"
                            data-whop-checkout-hide-tos="true"
                            data-whop-checkout-style-container-padding-x="0"
                            data-whop-checkout-style-container-padding-y="0"
                            <?php if ($hasSession) : ?>
                                data-whop-checkout-session="<?php echo esc_attr($checkoutSession); ?>"
                            <?php endif; ?>
                            <?php if ($hasPlan) : ?>
                                data-whop-checkout-plan-id="<?php echo esc_attr($planId); ?>"
                            <?php endif; ?>
                        ></div>
                        <noscript>
                            <div class="whop-checkout-alert whop-checkout-alert-error">
                                <?php esc_html_e('JavaScript is required to load embedded checkout.', 'whop-woocommerce'); ?>
                            </div>
                        </noscript>
                    <?php endif; ?>

                    <div class="whop-trust-badges-v3">
                        <div class="whop-trust-badge-item">
                            <?php echo $icons['shield']; ?>
                            <span>Stripe Protected</span>
                        </div>
                        <div class="whop-trust-badge-item">
                            <?php echo $icons['lock']; ?>
                            <span>SSL Encryption</span>
                        </div>
                        <div class="whop-trust-badge-item">
                            <?php echo $icons['check']; ?>
                            <span>Secure Payments</span>
                        </div>
                        <div class="whop-trust-badge-item">
                            <?php echo $icons['truck']; ?>
                            <span>Free Shipping</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- RIGHT COLUMN: ORDER SUMMARY -->
        <aside class="whop-order-summary-v3" aria-label="Order summary">
            <div class="whop-summary-card-v3">
                <h2 class="whop-summary-title-v3"><?php esc_html_e('Order Summary', 'whop-woocommerce'); ?></h2>
                
                <div class="whop-order-items-v3">
                    <?php foreach ($orderItems as $item) : ?>
                        <div class="whop-order-item-v3">
                            <div class="whop-order-item-media-v3">
                                <?php if ($item['image'] !== '') : ?>
                                    <?php echo wp_kses_post($item['image']); ?>
                                <?php else : ?>
                                    <div class="whop-order-item-placeholder-v3" aria-hidden="true">N</div>
                                <?php endif; ?>
                            </div>
                            <div class="whop-order-item-body-v3">
                                <div class="whop-order-item-name-v3"><?php echo esc_html($item['name']); ?></div>
                                <div class="whop-order-item-qty-v3"><?php printf(esc_html__('Qty %d', 'whop-woocommerce'), absint($item['quantity'])); ?></div>
                            </div>
                            <div class="whop-order-item-price-v3"><?php echo wp_kses_post($item['total']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($orderSummaryRows as $summaryRow) : ?>
                    <div class="whop-order-total-v3 whop-order-summary-row-v3">
                        <span><?php echo esc_html($summaryRow['label']); ?></span>
                        <span><?php echo $summaryRow['negative'] ? '-' : ''; ?><?php echo wp_kses_post($summaryRow['value']); ?></span>
                    </div>
                <?php endforeach; ?>

                <?php if ($orderTotal !== '') : ?>
                    <div class="whop-order-total-v3">
                        <span><?php esc_html_e('Total', 'whop-woocommerce'); ?></span>
                        <strong><?php echo wp_kses_post($orderTotal); ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="whop-trust-box-v3">
                <div class="whop-trust-box-header">
                    <?php echo $icons['lock']; ?>
                    <span>Secure Checkout</span>
                </div>
                <ul class="whop-trust-box-list">
                    <li><?php echo $icons['check']; ?> Stripe Protected</li>
                    <li><?php echo $icons['check']; ?> SSL Encryption</li>
                    <li><?php echo $icons['check']; ?> Secure Payments</li>
                    <li><?php echo $icons['check']; ?> Free Shipping</li>
                </ul>
            </div>
        </aside>
        
    </div>

    <?php if (! empty($policyLinks)) : ?>
        <nav class="whop-checkout-policies-v3" aria-label="Website policies">
            <div class="whop-checkout-policies-links-v3">
                <?php foreach ($policyLinks as $policyLink) : ?>
                    <a href="<?php echo esc_url($policyLink['url']); ?>"><?php echo esc_html($policyLink['label']); ?></a>
                <?php endforeach; ?>
            </div>
        </nav>
    <?php endif; ?>

    <?php
    $siteName = trim(wp_strip_all_tags((string) get_bloginfo('name')));

    if ($siteName === '') {
        $siteName = __('this website', 'whop-woocommerce');
    }

    $legalNotice = sprintf(
        __('By continuing, you agree to %s’s website policies and terms.', 'whop-woocommerce'),
        $siteName
    );
    ?>
    <p class="whop-checkout-legal-v3">
        <?php echo esc_html($legalNotice); ?>
    </p>
</main>
<?php wp_footer(); ?>
</body>
</html>
