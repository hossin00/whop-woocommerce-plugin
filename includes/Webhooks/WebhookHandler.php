<?php

namespace Whop\WooCommerce\Webhooks;

use Whop\WooCommerce\Orders\OrderHandler;
use Whop\WooCommerce\Logger\Logger;

final class WebhookHandler
{
    private Logger $logger;
    private OrderHandler $orderHandler;

    public function __construct(Logger $logger, OrderHandler $orderHandler)
    {
        $this->logger = $logger;
        $this->orderHandler = $orderHandler;
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, string $webhookId): void
    {
        $event = $payload['event'] ?? '';

        if ($event !== 'payment.succeeded') {
            $this->logger->log('Webhook event ignored.', ['event' => $event, 'webhook_id' => $webhookId]);
            return;
        }

        $this->orderHandler->completeFromWebhookPayload($payload, $webhookId);
    }
}
