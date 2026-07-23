<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\PaymentAuditPayload;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Payments\PaymentOutboxEventTypes;
use Academy\Domain\Payments\PaymentProvider;
use Academy\Domain\Payments\Webhook\PaymentWebhookEventRepository;
use Academy\Domain\Payments\Webhook\WebhookEventNormalizer;
use Academy\Domain\Payments\Webhook\WebhookSignatureVerifier;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class RazorpayWebhookIngressService
{
    private const MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private readonly WebhookSignatureVerifier $signatures,
        private readonly WebhookEventNormalizer $normalizer,
        private readonly PaymentWebhookEventRepository $webhookEvents,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{duplicate: bool, webhook_event_id: int}
     */
    public function receive(string $rawBody, string $signatureHeader, ?string $contentType): array
    {
        if ($contentType === null || !str_contains(strtolower($contentType), 'application/json')) {
            throw new ValidationException('Unsupported content type.', ['content_type' => ['Must be application/json.']]);
        }

        if (strlen($rawBody) === 0) {
            throw new ValidationException('Empty webhook body.', ['body' => ['Body is required.']]);
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new ValidationException('Webhook body too large.', ['body' => ['Body exceeds size limit.']]);
        }

        try {
            $valid = $this->signatures->verify($rawBody, $signatureHeader);
        } catch (ExternalServiceException $exception) {
            throw $exception;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$valid) {
            $this->audit->record(
                new PaymentAuditPayload(
                    action: 'payment.webhook_rejected',
                    entityType: 'payment_webhook_event',
                    entityId: '0',
                    next: [
                        'provider' => PaymentProvider::RAZORPAY,
                        'result' => 'invalid_signature',
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'webhook',
            );
            throw new AuthenticationException('Invalid webhook signature.');
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException('Malformed webhook JSON.', ['body' => ['JSON is invalid.']]);
        }

        if (!is_array($payload)) {
            throw new ValidationException('Malformed webhook JSON.', ['body' => ['JSON object required.']]);
        }

        $normalized = $this->normalizer->normalize($payload, $rawBody);
        $inserted = $this->webhookEvents->insertIdempotent($normalized, $now, $now);
        $event = $inserted['event'];
        $created = $inserted['created'];

        if ($created) {
            $this->outbox->enqueue(
                PaymentOutboxEventTypes::WEBHOOK_RECEIVED,
                'payment_webhook_event',
                (string) $event->webhookEventId,
                [
                    'webhook_event_id' => $event->webhookEventId,
                    'provider' => $event->provider,
                    'provider_event_id' => $event->providerEventId,
                    'event_type' => $event->eventType,
                    'provider_order_id' => $event->providerOrderId,
                ],
                PaymentOutboxEventTypes::WEBHOOK_RECEIVED . ':' . $event->webhookEventId,
            );

            $this->audit->record(
                new PaymentAuditPayload(
                    action: 'payment.webhook_received',
                    entityType: 'payment_webhook_event',
                    entityId: (string) $event->webhookEventId,
                    next: [
                        'webhook_event_id' => $event->webhookEventId,
                        'provider' => $event->provider,
                        'provider_event_id' => $event->providerEventId,
                        'event_type' => $event->eventType,
                        'provider_order_id' => $event->providerOrderId,
                        'provider_payment_id' => $event->providerPaymentId,
                        'amount_minor' => $event->amountMinor,
                        'currency' => $event->currency,
                        'result' => 'received',
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'webhook',
            );
        }

        return [
            'duplicate' => !$created,
            'webhook_event_id' => $event->webhookEventId,
        ];
    }
}
