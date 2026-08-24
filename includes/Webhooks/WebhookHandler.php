<?php

declare(strict_types=1);

namespace Whop\WooCommerce\Webhooks;

use Whop\WooCommerce\Logger\Logger;
use Whop\WooCommerce\Orders\OrderHandler;
use Whop\WooCommerce\Payments\CardSetupService;

final class WebhookHandler
{
    private Logger $logger;
    private OrderHandler $orderHandler;
    private CardSetupService $cardSetupService;

    public function __construct(Logger $logger, OrderHandler $orderHandler, CardSetupService $cardSetupService)
    {
        $this->logger = $logger;
        $this->orderHandler = $orderHandler;
        $this->cardSetupService = $cardSetupService;
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, string $webhookId): void
    {
        $event = sanitize_text_field((string) ($payload['event'] ?? ''));

        if ($event === 'setup_intent.succeeded') {
            $this->cardSetupService->handleSetupSucceeded($payload, $webhookId);
            return;
        }

        if ($event === 'payment.succeeded') {
            $this->orderHandler->completeFromWebhookPayload($payload, $webhookId);
            return;
        }

        $this->logger->log('Webhook event ignored.', ['event' => $event, 'webhook_id' => $webhookId]);
    }
}
