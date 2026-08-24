<?php

namespace Whop\WooCommerce\Webhooks;

use InvalidArgumentException;
use RuntimeException;
use Whop\WooCommerce\Helpers\Config;
use Whop\WooCommerce\Logger\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WebhookController
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

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

    public function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        $signature = $request->get_header('webhook-signature');
        $webhookTimestamp = $request->get_header('webhook-timestamp');
        $webhookId = $request->get_header('webhook-id');
        $rawBody = $request->get_body();

        if (! is_string($signature) || trim($signature) === '') {
            $this->logger->log('Webhook verification failed: missing signature.', [], 'ERROR');

            return $this->response(
                ['message' => __('Missing webhook signature.', 'whop-woocommerce')],
                401
            );
        }

        if (! is_string($webhookId) || trim($webhookId) === '') {
            $this->logger->log('Webhook verification failed: missing webhook-id.', ['signature_present' => true], 'ERROR');

            return $this->response(
                ['message' => __('Missing webhook-id header.', 'whop-woocommerce')],
                400
            );
        }

        if (! is_string($webhookTimestamp) || trim($webhookTimestamp) === '') {
            $this->logger->log('Webhook verification failed: missing webhook-timestamp.', ['webhook_id' => $webhookId], 'ERROR');

            return $this->response(
                ['message' => __('Missing webhook-timestamp header.', 'whop-woocommerce')],
                400
            );
        }

        if (! $this->is_fresh_timestamp($webhookTimestamp)) {
            $this->logger->log('Webhook verification failed: invalid or stale timestamp.', ['webhook_id' => $webhookId], 'ERROR');

            return $this->response(
                ['message' => __('Invalid webhook timestamp.', 'whop-woocommerce')],
                401
            );
        }

        $webhookSecret = trim($this->config->get('webhook_secret'));

        if ($webhookSecret === '') {
            $this->logger->log('Webhook verification failed: missing webhook secret.', [], 'ERROR');

            return $this->response(
                ['message' => __('Webhook secret is not configured.', 'whop-woocommerce')],
                401
            );
        }

        if (! $this->is_valid_signature($rawBody, $signature, $webhookSecret, $webhookId, $webhookTimestamp)) {
            $this->logger->log('Webhook verification failed: invalid signature.', ['webhook_id' => $webhookId], 'ERROR');

            return $this->response(
                ['message' => __('Invalid webhook signature.', 'whop-woocommerce')],
                401
            );
        }

        $payload = $this->parse_payload($rawBody);

        if ($payload === null) {
            $this->logger->log('Webhook payload invalid JSON or could not be parsed.', ['webhook_id' => $webhookId], 'ERROR');

            return $this->response(
                ['message' => __('Invalid JSON payload.', 'whop-woocommerce')],
                400
            );
        }

        $event = $this->normalize_event($payload);
        $payload['event'] = $event;

        $this->logger->log('Webhook received.', [
            'event' => $event,
            'order_id' => $payload['data']['metadata']['wc_order_id'] ?? $payload['data']['metadata']['order_id'] ?? '',
            'webhook_id' => $webhookId,
        ], 'INFO');
        $this->logger->log('Webhook signature verified.', [
            'event' => $event,
            'webhook_id' => $webhookId,
        ], 'INFO');

        try {
            $claimResult = $this->idempotencyService->beginProcessing($webhookId, $event);

            if ($claimResult['should_process'] === false) {
                $this->logger->log('Duplicate or deferred webhook.', [
                    'webhook_id' => $webhookId,
                    'event' => $event,
                    'status' => $claimResult['status'] ?? 'unknown',
                ], 'INFO');

                return $this->response([
                    'success' => true,
                    'duplicate' => true,
                    'status' => $claimResult['status'] ?? 'unknown',
                ], 200);
            }

            if (! in_array($event, ['payment.succeeded', 'setup_intent.succeeded'], true)) {
                $this->logger->log('Webhook event ignored.', ['event' => $event, 'webhook_id' => $webhookId], 'INFO');
                $this->idempotencyService->markProcessed($webhookId, $event);

                return $this->response([
                    'success' => true,
                    'ignored' => true,
                    'duplicate' => false,
                    'status' => $claimResult['status'] ?? 'claimed',
                ], 200);
            }

            $this->handler->handle($payload, $webhookId);

            return $this->response([
                'success' => true,
                'duplicate' => false,
                'status' => $claimResult['status'] ?? 'claimed',
            ], 200);
        } catch (InvalidArgumentException $exception) {
            $this->logger->log('Webhook request invalid.', ['error' => $exception->getMessage()], 'ERROR');

            return $this->response(
                ['message' => $exception->getMessage()],
                400
            );
        } catch (RuntimeException $exception) {
            $this->logger->log('Webhook order not found.', ['error' => $exception->getMessage(), 'webhook_id' => $webhookId], 'ERROR');

            return $this->response(
                ['message' => $exception->getMessage()],
                404
            );
        } catch (\Throwable $exception) {
            $this->logger->log('Webhook processing failed: ' . $exception->getMessage(), ['webhook_id' => $webhookId], 'ERROR');

            return $this->response(
                ['message' => __('Unable to process webhook.', 'whop-woocommerce')],
                500
            );
        }
    }

    private function is_fresh_timestamp(string $timestamp): bool
    {
        $timestamp = trim($timestamp);

        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= self::SIGNATURE_TOLERANCE_SECONDS;
    }

    private function is_valid_signature(string $payload, string $signatureHeader, string $secret, string $webhookId, string $webhookTimestamp): bool
    {
        $signedContent = $webhookId . '.' . $webhookTimestamp . '.' . $payload;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        foreach ($this->extract_v1_signatures($signatureHeader) as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function extract_v1_signatures(string $signatureHeader): array
    {
        $signatures = [];
        $parts = preg_split('/\s+/', trim($signatureHeader));

        if (! is_array($parts)) {
            return $signatures;
        }

        foreach ($parts as $part) {
            if (strpos($part, 'v1,') !== 0) {
                continue;
            }

            $signature = substr($part, 3);

            if ($signature !== '') {
                $signatures[] = $signature;
            }
        }

        return $signatures;
    }

    /** @param array<string, mixed> $payload */
    private function normalize_event(array $payload): string
    {
        $event = $payload['type'] ?? $payload['event'] ?? '';

        if (! is_string($event)) {
            return '';
        }

        if ($event === 'payment_succeeded') {
            return 'payment.succeeded';
        }

        return trim($event);
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

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status): WP_REST_Response
    {
        return new WP_REST_Response($data, $status);
    }
}
