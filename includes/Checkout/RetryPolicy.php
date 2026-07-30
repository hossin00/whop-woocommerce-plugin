<?php

namespace Whop\WooCommerce\Checkout;

use Whop\WooCommerce\API\Exceptions\WhopClientTransientException;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;

final class RetryPolicy
{
    private const DEFAULT_MAX_RETRIES = 3;
    /** @var array<int, int> */
    private const DEFAULT_BACKOFF_SECONDS = [1, 2, 4];

    private Logger $logger;
    private int $maxRetries;
    /** @var array<int, int> */
    private array $backoffSeconds;

    public function __construct(Logger $logger, Config $config)
    {
        $this->logger = $logger;

        $max = (int) $config->get('retry_max');
        $backoff = $config->get('retry_backoff_seconds');

        $this->maxRetries = $max > 0 ? $max : self::DEFAULT_MAX_RETRIES;

        if (trim((string) $backoff) !== '') {
            $parts = array_filter(array_map('trim', explode(',', (string) $backoff)), static function (string $value): bool {
                return $value !== '';
            });
            $this->backoffSeconds = array_values(array_map('intval', $parts));
        } else {
            $this->backoffSeconds = self::DEFAULT_BACKOFF_SECONDS;
        }
    }

    /** @param callable(): mixed $operation */
    /** @return mixed */
    public function execute(callable $operation)
    {
        $attempt = 0;
        /** @var ?\Throwable $lastException */
        $lastException = null;

        do {
            try {
                $result = $operation();

                if ($attempt > 0) {
                    $this->logger->log('Checkout creation succeeded after retry.', ['attempts' => $attempt + 1], 'INFO');
                }

                return $result;
            } catch (WhopClientTransientException $exception) {
                $lastException = $exception;
                $attempt++;

                if ($attempt >= $this->maxRetries) {
                    $this->logger->log('Final checkout creation retry failed.', ['attempts' => $attempt, 'error' => $exception->getMessage()], 'ERROR');
                    throw $exception;
                }

                $backoff = $this->backoffSeconds[$attempt - 1] ?? end($this->backoffSeconds);
                $this->logger->log('Transient checkout creation failure, will retry immediately (non-blocking).', ['attempt' => $attempt, 'suggested_backoff_seconds' => $backoff, 'error' => $exception->getMessage()], 'WARNING');

                // Non-blocking retry: do not sleep to avoid blocking PHP workers.
                // Immediate retry loop continues.
            }
        } while ($attempt < $this->maxRetries);

        throw new \RuntimeException(__('Unable to complete checkout creation.', 'whop-woocommerce'));
    }
}
