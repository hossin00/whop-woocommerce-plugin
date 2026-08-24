<?php

namespace Whop\WooCommerce\Webhooks;

use InvalidArgumentException;
use RuntimeException;
use Whop\WooCommerce\Logger\Logger;

final class WebhookIdempotencyService
{
    private const OPTION_PREFIX = 'whop_wc_webhook_';
    private const LOCK_PREFIX = 'whop_wc_webhook_lock_';
    private const LEASE_SECONDS = 300;
    private const MAX_ATTEMPTS = 3;
    private const STATUS_NEW = 'new';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $webhookId
     * @param string $event
     * @param int|null $orderId
     * @return array<string, mixed>
     */
    public function beginProcessing(string $webhookId, string $event, ?int $orderId = null): array
    {
        $normalizedWebhookId = trim($webhookId);

        if ($normalizedWebhookId === '') {
            throw new InvalidArgumentException(esc_html__('Webhook ID is required for idempotency tracking.', 'whop-woocommerce'));
        }

        $optionName = $this->get_option_name($normalizedWebhookId);
        $lockName = $this->get_lock_name($normalizedWebhookId);
        $existingRecord = get_option($optionName);

        if (is_array($existingRecord)) {
            return $this->handle_existing_record($normalizedWebhookId, $event, $optionName, $lockName, $existingRecord);
        }

        if (! $this->claim_lock($lockName, $normalizedWebhookId)) {
            $existingRecord = get_option($optionName);

            if (is_array($existingRecord)) {
                return $this->handle_existing_record($normalizedWebhookId, $event, $optionName, $lockName, $existingRecord);
            }

            $this->logger->log('Webhook idempotency lock acquisition failed.', [
                'webhook_id' => $normalizedWebhookId,
                'event' => $event,
            ], 'ERROR');

            throw new RuntimeException(esc_html__('Unable to claim webhook idempotency lock.', 'whop-woocommerce'));
        }

        $record = $this->build_record($normalizedWebhookId, $event, $orderId, self::STATUS_NEW, 1);
        $created = add_option($optionName, $record, '', 'no');

        if ($created === false) {
            $this->release_lock($lockName);
            $existingRecord = get_option($optionName);

            if (is_array($existingRecord)) {
                return $this->handle_existing_record($normalizedWebhookId, $event, $optionName, $lockName, $existingRecord);
            }

            $this->logger->log('Webhook idempotency persistence failed.', [
                'webhook_id' => $normalizedWebhookId,
                'event' => $event,
            ], 'ERROR');

            throw new RuntimeException(esc_html__('Unable to persist webhook idempotency state.', 'whop-woocommerce'));
        }

        $record['status'] = self::STATUS_PROCESSING;
        $record['attempt_count'] = 1;
        $record['claimed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['lease_expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + self::LEASE_SECONDS);

        update_option($optionName, $record);

        $this->logger->log('First webhook claim accepted.', [
            'webhook_id' => $normalizedWebhookId,
            'event' => $event,
            'attempt_count' => 1,
        ], 'INFO');

        return [
            'should_process' => true,
            'status' => 'claimed',
        ];
    }

    public function updateContext(string $webhookId, string $event, ?int $orderId = null, ?string $paymentId = null, ?string $checkoutId = null): void
    {
        $normalizedWebhookId = trim($webhookId);

        if ($normalizedWebhookId === '') {
            return;
        }

        $optionName = $this->get_option_name($normalizedWebhookId);
        $record = get_option($optionName);

        if (! is_array($record)) {
            return;
        }

        $record['event_type'] = $event;
        $record['order_id'] = $orderId;
        $record['payment_id'] = $paymentId;
        $record['checkout_id'] = $checkoutId;
        $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        update_option($optionName, $record);
    }

    public function markProcessed(string $webhookId, string $event, ?int $orderId = null): void
    {
        $normalizedWebhookId = trim($webhookId);

        if ($normalizedWebhookId === '') {
            throw new InvalidArgumentException(esc_html__('Webhook ID is required for idempotency tracking.', 'whop-woocommerce'));
        }

        $optionName = $this->get_option_name($normalizedWebhookId);
        $lockName = $this->get_lock_name($normalizedWebhookId);
        $record = get_option($optionName);

        if (! is_array($record)) {
            throw new RuntimeException(esc_html__('Unable to find webhook idempotency record for completion.', 'whop-woocommerce'));
        }

        $record['status'] = self::STATUS_COMPLETED;
        $record['processed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['event_type'] = $event;
        $record['order_id'] = $orderId;
        $record['last_error'] = '';

        if (! update_option($optionName, $record)) {
            $this->logger->log('Webhook idempotency persistence failed during completion.', [
                'webhook_id' => $normalizedWebhookId,
                'event' => $event,
                'order_id' => $orderId,
            ], 'ERROR');

            throw new RuntimeException(esc_html__('Unable to persist webhook idempotency completion state.', 'whop-woocommerce'));
        }

        $this->release_lock($lockName);
    }

    public function markFailed(string $webhookId, string $event, ?int $orderId = null, ?string $error = null): void
    {
        $normalizedWebhookId = trim($webhookId);

        if ($normalizedWebhookId === '') {
            return;
        }

        $optionName = $this->get_option_name($normalizedWebhookId);
        $lockName = $this->get_lock_name($normalizedWebhookId);
        $record = get_option($optionName);

        if (! is_array($record)) {
            return;
        }

        $record['status'] = self::STATUS_FAILED;
        $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['event_type'] = $event;
        $record['order_id'] = $orderId;
        $record['last_error'] = $this->sanitize_error_message($error);
        $record['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');

        if (! update_option($optionName, $record)) {
            $this->logger->log('Webhook idempotency persistence failed during failure handling.', [
                'webhook_id' => $normalizedWebhookId,
                'event' => $event,
                'order_id' => $orderId,
            ], 'ERROR');
        }

        $this->release_lock($lockName);
    }

    private function get_option_name(string $webhookId): string
    {
        return self::OPTION_PREFIX . substr(sha1($webhookId), 0, 24);
    }

    private function get_lock_name(string $webhookId): string
    {
        return self::LOCK_PREFIX . substr(sha1($webhookId), 0, 24);
    }

    /**
     * @param string $webhookId
     * @param string $event
     * @param int|null $orderId
     * @param string $status
     * @param int $attemptCount
     * @return array<string, mixed>
     */
    private function build_record(string $webhookId, string $event, ?int $orderId, string $status, int $attemptCount): array
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        return [
            'webhook_id' => $webhookId,
            'status' => $status,
            'claimed_at' => $timestamp,
            'updated_at' => $timestamp,
            'event_type' => $event,
            'order_id' => $orderId,
            'payment_id' => null,
            'checkout_id' => null,
            'attempt_count' => $attemptCount,
            'last_error' => '',
            'last_attempt_at' => $timestamp,
            'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::LEASE_SECONDS),
            'processed_at' => null,
            'created_at' => $timestamp,
        ];
    }

    /**
     * @param string $webhookId
     * @param string $event
     * @param string $optionName
     * @param string $lockName
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function handle_existing_record(string $webhookId, string $event, string $optionName, string $lockName, array $record): array
    {
        $status = (string) ($record['status'] ?? self::STATUS_NEW);

        if ($status === self::STATUS_COMPLETED) {
            $this->logger->log('Duplicate webhook completed already.', [
                'webhook_id' => $webhookId,
                'event' => $event,
            ], 'INFO');

            return [
                'should_process' => false,
                'status' => 'completed',
            ];
        }

        if ($status === self::STATUS_PROCESSING && $this->is_lease_valid($record)) {
            $this->logger->log('Duplicate webhook already in progress.', [
                'webhook_id' => $webhookId,
                'event' => $event,
            ], 'INFO');

            return [
                'should_process' => false,
                'status' => 'processing',
            ];
        }

        if ($status === self::STATUS_FAILED || $status === self::STATUS_PROCESSING) {
            if (! $this->can_retry($record)) {
                $this->logger->log('Max webhook attempts reached.', [
                    'webhook_id' => $webhookId,
                    'event' => $event,
                    'attempt_count' => (int) ($record['attempt_count'] ?? 0),
                ], 'WARNING');

                return [
                    'should_process' => false,
                    'status' => 'max_attempts_reached',
                ];
            }

            if (! $this->claim_lock($lockName, $webhookId)) {
                $this->logger->log('Webhook already being reclaimed by another worker.', [
                    'webhook_id' => $webhookId,
                    'event' => $event,
                ], 'INFO');

                return [
                    'should_process' => false,
                    'status' => 'processing',
                ];
            }

            $record['status'] = self::STATUS_PROCESSING;
            $record['attempt_count'] = (int) ($record['attempt_count'] ?? 0) + 1;
            $record['claimed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $record['last_error'] = '';
            $record['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $record['lease_expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + self::LEASE_SECONDS);
            update_option($optionName, $record);

            $this->logger->log('Webhook lease expired or previous attempt failed; reclaiming processing state.', [
                'webhook_id' => $webhookId,
                'event' => $event,
                'attempt_count' => (int) $record['attempt_count'],
            ], 'INFO');

            return [
                'should_process' => true,
                'status' => 'reclaimed',
            ];
        }

        $this->logger->log('Duplicate webhook already in progress.', [
            'webhook_id' => $webhookId,
            'event' => $event,
        ], 'INFO');

        return [
            'should_process' => false,
            'status' => 'processing',
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function is_lease_valid(array $record): bool
    {
        $leaseExpiresAt = (string) ($record['lease_expires_at'] ?? '');

        if ($leaseExpiresAt === '') {
            return false;
        }

        return strtotime($leaseExpiresAt) > time();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function can_retry(array $record): bool
    {
        $attemptCount = (int) ($record['attempt_count'] ?? 0);

        return $attemptCount < self::MAX_ATTEMPTS;
    }

    private function claim_lock(string $lockName, string $webhookId): bool
    {
        $existingLock = get_option($lockName);

        if (is_array($existingLock)) {
            $lockedAt = (int) ($existingLock['locked_at'] ?? 0);
            $lockExpiresAt = $lockedAt + self::LEASE_SECONDS;

            if ($lockExpiresAt > time()) {
                return false;
            }

            delete_option($lockName);
        }

        $created = add_option($lockName, [
            'webhook_id' => $webhookId,
            'locked_at' => time(),
        ], '', 'no');

        return $created === true;
    }

    private function release_lock(string $lockName): void
    {
        delete_option($lockName);
    }

    private function sanitize_error_message(?string $error): string
    {
        if ($error === null || $error === '') {
            return '';
        }

        $sanitized = preg_replace('/(api[_-]?key|webhook[_-]?secret|authorization)/i', '[REDACTED]', $error);

        return is_string($sanitized) ? substr($sanitized, 0, 500) : substr($error, 0, 500);
    }
}
