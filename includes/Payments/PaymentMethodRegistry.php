<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Payments;

/**
 * Registry of the intentionally supported WooCommerce-native Whop methods.
 *
 * This registry deliberately excludes every Whop method outside the approved
 * first-release scope. It uses only Whop PaymentMethodTypes values documented
 * by the API contract; client-side values are never used as a source of truth.
 */
final class PaymentMethodRegistry
{
    public const CARD = 'whop_card';
    public const BANK_TRANSFER = 'whop_bank_transfer';
    public const CRYPTO = 'whop_crypto';
    public const PAYPAL = 'whop_paypal';

    /** @var array<int, string> */
    private const PAYPAL_CURRENCIES = ['usd', 'eur', 'gbp', 'sek', 'nok', 'czk'];

    /** @var array<int, string> */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            self::CARD => [
                'id' => self::CARD,
                'title' => __('Credit / Debit Card', 'whop-woocommerce'),
                'description' => __('Pay securely by card through Whop.', 'whop-woocommerce'),
                'icon' => 'card.svg',
                'method_type' => 'card',
                'kind' => 'card',
            ],
            self::BANK_TRANSFER => [
                'id' => self::BANK_TRANSFER,
                'title' => __('Bank Transfer', 'whop-woocommerce'),
                'description' => __('Pay by eligible bank transfer through Whop.', 'whop-woocommerce'),
                'icon' => 'bank-transfer.svg',
                'method_type' => '',
                'kind' => 'bank_transfer',
            ],
            self::CRYPTO => [
                'id' => self::CRYPTO,
                'title' => __('Crypto', 'whop-woocommerce'),
                'description' => __('Pay with eligible cryptocurrency through Whop.', 'whop-woocommerce'),
                'icon' => 'crypto.svg',
                'method_type' => 'crypto',
                'kind' => 'crypto',
            ],
            self::PAYPAL => [
                'id' => self::PAYPAL,
                'title' => __('PayPal', 'whop-woocommerce'),
                'description' => __('Pay securely with PayPal through Whop.', 'whop-woocommerce'),
                'icon' => 'paypal.png',
                'method_type' => 'paypal',
                'kind' => 'paypal',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $gatewayId): ?array
    {
        $methods = $this->all();

        return $methods[$gatewayId] ?? null;
    }

    /**
     * Resolve Whop's exact method type after server-side order validation.
     */
    public function resolveWhopMethodType(string $gatewayId, string $country, string $currency): string
    {
        $country = strtoupper(sanitize_text_field($country));
        $currency = strtolower(sanitize_text_field($currency));

        if ($gatewayId === self::BANK_TRANSFER) {
            if ($country === 'US' && $currency === 'usd') {
                return 'us_bank_transfer';
            }

            if (in_array($country, self::EU_COUNTRIES, true) && $currency === 'eur') {
                return 'eu_bank_transfer';
            }

            return '';
        }

        $method = $this->get($gatewayId);

        return is_array($method) ? (string) ($method['method_type'] ?? '') : '';
    }

    public function isPayPalCurrency(string $currency): bool
    {
        return in_array(strtolower($currency), self::PAYPAL_CURRENCIES, true);
    }

    public function isEuCountry(string $country): bool
    {
        return in_array(strtoupper($country), self::EU_COUNTRIES, true);
    }
}
