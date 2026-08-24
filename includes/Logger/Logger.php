<?php

namespace Whop\WooCommerce\Logger;

use Whop\WooCommerce\Helpers\Config;

final class Logger
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @param array<string, mixed> $context */
    public function log(string $message, array $context = [], string $level = 'INFO'): void
    {
        $level = strtoupper($level);

        // Always log errors and critical regardless of debug mode.
        if (! $this->config->is_debug_mode() && $level !== 'ERROR' && $level !== 'CRITICAL') {
            return;
        }

        $safeContext = $this->sanitize_context($context);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $contextOutput = wp_json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $logLine = sprintf(
            '%s [%s] Whop WooCommerce: %s %s',
            $timestamp,
            strtoupper($level),
            $message,
            $contextOutput ?: ''
        );

        error_log($logLine);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, bool|int|float|string>
     */
    private function sanitize_context(array $context): array
    {
        $allowed = [];

        foreach ($context as $key => $value) {
            if (in_array($key, ['api_key', 'sandbox_api_key', 'webhook_secret'], true)) {
                continue;
            }

            if (is_scalar($value)) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }
}
