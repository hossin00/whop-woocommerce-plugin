<?php

namespace Whop\WooCommerce\Helpers;

final class Config
{
    private const OPTION_KEY = 'whop_woocommerce_settings';
    private const PRODUCTION_API_BASE = 'https://api.whop.com/api/v1';
    private const SANDBOX_API_BASE = 'https://sandbox-api.whop.com/api/v1';

    /** @var array<string, string> */
    private array $settings;

    public function __construct()
    {
        $savedSettings = get_option(self::OPTION_KEY, $this->get_defaults());

        if (! is_array($savedSettings)) {
            $savedSettings = $this->get_defaults();
        }

        $defaults = $this->get_defaults();
        $this->settings = [];

        foreach ($defaults as $key => $defaultValue) {
            $value = $savedSettings[$key] ?? $defaultValue;
            $this->settings[$key] = is_string($value) ? $value : (string) $value;
        }
    }

    public function get(string $key): string
    {
        return $this->settings[$key] ?? '';
    }

    public function is_sandbox_mode(): bool
    {
        return $this->settings['sandbox_mode'] === 'yes';
    }

    public function is_debug_mode(): bool
    {
        return $this->settings['debug_mode'] === 'yes';
    }

    public function get_api_base_url(): string
    {
        return $this->is_sandbox_mode() ? self::SANDBOX_API_BASE : self::PRODUCTION_API_BASE;
    }

    public function get_active_api_key(): string
    {
        if ($this->is_sandbox_mode()) {
            return $this->get('sandbox_api_key');
        }

        return $this->get('api_key');
    }

    /** @return array<string, string> */
    public function get_defaults(): array
    {
        return [
            'api_key' => '',
            'sandbox_api_key' => '',
            'plan_id' => '',
            'webhook_secret' => '',
            'checkout_domain' => '',
            'sandbox_mode' => 'no',
            'debug_mode' => 'no',
        ];
    }
}
