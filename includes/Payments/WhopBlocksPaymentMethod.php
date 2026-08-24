<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Whop\WooCommerce\Helpers\Config;

/**
 * WooCommerce Checkout Block representation of one registered Whop gateway.
 */
final class WhopBlocksPaymentMethod extends AbstractPaymentMethodType
{
    protected $name;

    private PaymentMethodRegistry $registry;
    private Config $config;
    private string $gatewayId;

    public function __construct(PaymentMethodRegistry $registry, Config $config, string $gatewayId)
    {
        $this->registry = $registry;
        $this->config = $config;
        $this->gatewayId = $gatewayId;
        $this->name = $gatewayId;
    }

    public function initialize(): void
    {
        // This integration has no separate settings; it reuses plugin settings.
    }

    public function is_active(): bool
    {
        return $this->registry->get($this->gatewayId) !== null
            && trim($this->config->get_active_api_key()) !== ''
            && trim($this->config->get_active_plan_id()) !== '';
    }

    /**
     * @return array<int, string>
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'whop-woocommerce-native-gateways-blocks';

        if (! wp_script_is($handle, 'registered')) {
            wp_register_script(
                $handle,
                WHOP_WOOCOMMERCE_ASSETS_URL . '/js/native-gateways-blocks.js',
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
                WHOP_WOOCOMMERCE_VERSION,
                true
            );
        }

        return [$handle];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $definition = $this->registry->get($this->gatewayId);

        if (! is_array($definition)) {
            return [];
        }

        return [
            'title' => (string) $definition['title'],
            'description' => (string) $definition['description'],
            'icon_url' => esc_url_raw(WHOP_WOOCOMMERCE_ASSETS_URL . '/images/payments/' . rawurlencode((string) $definition['icon'])),
            'supports' => ['products'],
        ];
    }
}
