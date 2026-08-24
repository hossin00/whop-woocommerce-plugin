<?php
/**
 * Checkout completion route template.
 *
 * @var string $status
 */

defined('ABSPATH') || exit;

$statusClass = 'whop-checkout-alert-info';
$statusTitle = __('Checkout Update', 'whop-woocommerce');
$statusMessage = __('We are verifying your payment status. This page does not finalize orders by itself.', 'whop-woocommerce');

if ($status === 'success') {
    $statusClass = 'whop-checkout-alert-success';
    $statusTitle = __('Payment Submitted', 'whop-woocommerce');
    $statusMessage = __('Your payment was submitted successfully. Order fulfillment only occurs after the payment.succeeded webhook is verified.', 'whop-woocommerce');
} elseif ($status === 'error') {
    $statusClass = 'whop-checkout-alert-error';
    $statusTitle = __('Payment Not Completed', 'whop-woocommerce');
    $statusMessage = __('Your checkout was cancelled or failed. No order is fulfilled unless a verified webhook confirms payment.', 'whop-woocommerce');
}

// Translations for CTA
$ctaText = __('Continue Shopping', 'whop-woocommerce');
$helpText = __("Need help? Contact our support anytime.", 'whop-woocommerce');

// Detect locale for specific translations if needed, though __() handles it via .mo files
// But for direct requirements:
$locale = get_locale();
if (str_starts_with($locale, 'fr')) {
    $ctaText = "Continuer vos achats";
    $helpText = "Besoin d'aide ? Contactez notre support à tout moment.";
} elseif (str_starts_with($locale, 'de')) {
    $ctaText = "Weiter einkaufen";
    $helpText = "Brauchen Sie Hilfe? Kontaktieren Sie jederzeit unseren Support.";
} elseif (str_starts_with($locale, 'es')) {
    $ctaText = "Continuar comprando";
    $helpText = "¿Necesitas ayuda? Contacta con nuestro soporte en cualquier momento.";
}

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('whop-checkout-complete-page'); ?>>
<?php wp_body_open(); ?>
<main class="whop-checkout-shell" aria-label="Checkout Completion">
    <section class="whop-checkout-card whop-complete-card">
        <h1><?php esc_html_e('Checkout Complete', 'whop-woocommerce'); ?></h1>
        
        <div class="whop-checkout-alert <?php echo esc_attr($statusClass); ?>">
            <strong><?php echo esc_html($statusTitle); ?></strong>
            <p><?php echo esc_html($statusMessage); ?></p>
        </div>

        <div class="whop-complete-actions">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="whop-button-primary">
                <?php echo esc_html($ctaText); ?>
            </a>
            <p class="whop-help-text">
                <?php echo esc_html($helpText); ?>
            </p>
        </div>

        <p class="whop-checkout-subtitle"><?php esc_html_e('If your order does not update shortly, please contact support with your order reference.', 'whop-woocommerce'); ?></p>
    </section>
</main>
<?php wp_footer(); ?>
</body>
</html>
