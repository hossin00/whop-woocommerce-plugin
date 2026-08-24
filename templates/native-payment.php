<?php
/**
 * Protected native WooCommerce order-pay template.
 *
 * @var WC_Order $order
 * @var string $checkoutId
 * @var string $gatewayId
 * @var string $environment
 * @var string $returnUrl
 */

defined('ABSPATH') || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <script async defer src="https://js.whop.com/static/checkout/loader.js"></script>
</head>
<body <?php body_class('whop-native-payment-page'); ?>>
<?php wp_body_open(); ?>
<main class="whop-native-payment-shell" aria-label="<?php esc_attr_e('Secure payment', 'whop-woocommerce'); ?>">
    <header class="whop-native-payment-header">
        <p class="whop-native-payment-kicker"><?php esc_html_e('Secure payment', 'whop-woocommerce'); ?></p>
        <h1><?php printf(esc_html__('Pay for order #%d', 'whop-woocommerce'), absint($order->get_id())); ?></h1>
        <p><?php echo wp_kses_post($order->get_formatted_order_total()); ?></p>
    </header>
    <section class="whop-native-payment-card" aria-label="<?php esc_attr_e('Whop payment checkout', 'whop-woocommerce'); ?>">
        <div
            id="whop-native-embedded-checkout"
            data-whop-checkout-environment="<?php echo esc_attr($environment); ?>"
            data-whop-checkout-return-url="<?php echo esc_url($returnUrl); ?>"
            data-whop-checkout-session="<?php echo esc_attr($checkoutId); ?>"
            data-whop-checkout-hide-tos="true"
            data-whop-checkout-style-container-padding-x="0"
            data-whop-checkout-style-container-padding-y="0"
        ></div>
        <noscript><p><?php esc_html_e('JavaScript is required to complete payment securely.', 'whop-woocommerce'); ?></p></noscript>
    </section>
</main>
<?php wp_footer(); ?>
</body>
</html>
