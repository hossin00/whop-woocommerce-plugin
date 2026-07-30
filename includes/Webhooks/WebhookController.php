<?php

namespace Whop\WooCommerce\Webhooks;

use InvalidArgumentException;
use RuntimeException;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;
use Whop\WooCommerce\Webhooks\WebhookHandler;
use Whop\WooCommerce\Webhooks\WebhookIdempotencyService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WebhookController
{
    private Config $config;
    private Logger $logger;
    private WebhookHandler $handler;
    private WebhookIdempotencyService $idempotencyService;

    public function __construct(Config $config, Logger $logger, WebhookHandler $handler, WebhookIdempotencyService $idempotencyService)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->handler = $handler;
        $this->idempotencyService = $idempotencyService;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'whop-woocommerce/v1',
            '/webhook',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle_request'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** @return WP_REST_Response */
    public function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        $signature = $request->get_header('webhook-signature');
        if (! is_string($signature) || $signature === '') {
            $signature = $request->get_header('x-whop-signature');
        }

        $webhookTimestamp = $request->get_header('webhook-timestamp');
        $webhookId = $request->get_header('webhook-id');
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false) {
            $rawBody = '';
        }

        if (! is_string($signature) || $signature === '') {
            $this->logger->log('Webhook verification failed: missing signature.');

            return rest_ensure_response(
                [
                    'message' => __('Missing webhook signature.', 'whop-woocommerce'),
                ]
            )->set_status(401);
        }

        if (! is_string($webhookId) || trim($webhookId) === '') {
            $this->logger->log('Webhook verification failed: missing webhook-id.', ['signature_present' => true], 'ERROR');

            return rest_ensure_response(
                [
                    'message' => __('Missing webhook-id header.', 'whop-woocommerce'),
                ]
            )->set_status(400);
        }

        if (! is_string($webhookTimestamp) || trim($webhookTimestamp) === '') {
            $this->logger->log('Webhook verification failed: missing webhook-timestamp.', ['webhook_id' => $webhookId], 'ERROR');

            return rest_ensure_response(
                [
                    'message' => __('Missing webhook-timestamp header.', 'whop-woocommerce'),
                ]
            )->set_status(400);
        }

        $webhookSecret = $this->config->get('webhook_secret');

        if (trim($webhookSecret) === '') {
            $this->logger->log('Webhook verification failed: missing webhook secret.');

            return rest_ensure_response(
                [
                    'message' => __('Webhook secret is not configured.', 'whop-woocommerce'),
                ]
            )->set_status(401);
        }

        if (! $this->is_valid_signature($rawBody, $signature, $webhookSecret)) {
            $this->logger->log('Webhook verification failed: invalid signature.');

            return rest_ensure_response(
                [
                    'message' => __('Invalid webhook signature.', 'whop-woocommerce'),
                ]
            )->set_status(401);
        }

        $payload = $this->parse_payload($rawBody);

        if ($payload === null) {
            $this->logger->log('Webhook payload invalid JSON or could not be parsed.', [], 'ERROR');

            return rest_ensure_response(
                [
                    'message' => __('Invalid JSON payload.', 'whop-woocommerce'),
                ]
            )->set_status(400);
        }

        $this->logger->log('Webhook received.', ['event' => $payload['event'] ?? '', 'order_id' => $payload['data']['metadata']['order_id'] ?? '', 'webhook_id' => $webhookId], 'INFO');
        $this->logger->log('Webhook signature verified.', ['event' => $payload['event'] ?? '', 'order_id' => $payload['data']['metadata']['order_id'] ?? '', 'webhook_id' => $webhookId], 'INFO');

        try {
            $claimResult = $this->idempotencyService->beginProcessing($webhookId, (string) ($payload['event'] ?? ''));

            if ($claimResult['should_process'] === false) {
                $this->logger->log('Duplicate or deferred webhook.', ['webhook_id' => $webhookId, 'event' => $payload['event'] ?? '', 'status' => $claimResult['status'] ?? 'unknown'], 'INFO');

                return rest_ensure_response(['success' => true, 'duplicate' => true, 'status' => $claimResult['status'] ?? 'unknown'])->set_status(200);
            }

            $this->handler->handle($payload, $webhookId);

            return rest_ensure_response(['success' => true, 'duplicate' => false, 'status' => $claimResult['status'] ?? 'claimed'])->set_status(200);
        } catch (InvalidArgumentException $exception) {
            $this->logger->log('Webhook request invalid.', ['error' => $exception->getMessage()], 'ERROR');

            return rest_ensure_response(
                [
                    'message' => $exception->getMessage(),
                ]
            )->set_status(400);
        } catch (RuntimeException $exception) {
            $this->logger->log('Webhook order not found.', ['error' => $exception->getMessage(), 'webhook_id' => $webhookId], 'ERROR');

            return rest_ensure_response(
                [
                    'message' => $exception->getMessage(),
                ]
            )->set_status(404);
        } catch (\Throwable $exception) {
            $this->logger->log('Webhook processing failed: ' . $exception->getMessage(), ['webhook_id' => $webhookId], 'ERROR');

            return rest_ensure_response(
                [
                    'message' => __('Unable to process webhook.', 'whop-woocommerce'),
                ]
            )->set_status(500);
        }
    }

    private function is_valid_signature(string $payload, string $signatureHeader, string $secret): bool
    {
        $signature = $this->normalize_signature($signatureHeader);

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    private function normalize_signature(string $signatureHeader): string
    {
        $signatureHeader = trim($signatureHeader);

        if (stripos($signatureHeader, 'sha256=') === 0) {
            return substr($signatureHeader, 7);
        }

        return $signatureHeader;
    }

    /** @return array<string, mixed>|null */
    private function parse_payload(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $payload = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($payload)) {
            return null;
        }

        return $payload;
    }
}
