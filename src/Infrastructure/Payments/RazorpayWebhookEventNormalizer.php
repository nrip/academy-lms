<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Payments\PaymentProvider;
use Academy\Domain\Payments\Webhook\NormalizedWebhookEvent;
use Academy\Domain\Payments\Webhook\RazorpayWebhookEventTypes;
use Academy\Domain\Payments\Webhook\WebhookEventNormalizer;
use DateTimeImmutable;
use DateTimeZone;

final class RazorpayWebhookEventNormalizer implements WebhookEventNormalizer
{
    public function normalize(array $payload, string $rawBody): NormalizedWebhookEvent
    {
        $eventType = isset($payload['event']) && is_string($payload['event']) ? $payload['event'] : '';
        $providerEventId = isset($payload['id']) && is_string($payload['id']) && $payload['id'] !== ''
            ? $payload['id']
            : ('digest_' . hash('sha256', $rawBody));

        $entity = [];
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            $entity = $payload['payload'];
        }

        $paymentEntity = [];
        if (isset($entity['payment']['entity']) && is_array($entity['payment']['entity'])) {
            $paymentEntity = $entity['payment']['entity'];
        }

        $orderEntity = [];
        if (isset($entity['order']['entity']) && is_array($entity['order']['entity'])) {
            $orderEntity = $entity['order']['entity'];
        }

        $providerPaymentId = isset($paymentEntity['id']) && is_string($paymentEntity['id'])
            ? $paymentEntity['id']
            : null;
        $providerOrderId = null;
        if (isset($paymentEntity['order_id']) && is_string($paymentEntity['order_id'])) {
            $providerOrderId = $paymentEntity['order_id'];
        } elseif (isset($orderEntity['id']) && is_string($orderEntity['id'])) {
            $providerOrderId = $orderEntity['id'];
        }

        $amountMinor = null;
        if (isset($paymentEntity['amount'])) {
            $amountMinor = (int) $paymentEntity['amount'];
        } elseif (isset($orderEntity['amount'])) {
            $amountMinor = (int) $orderEntity['amount'];
        }

        $currency = null;
        if (isset($paymentEntity['currency']) && is_string($paymentEntity['currency'])) {
            $currency = strtoupper($paymentEntity['currency']);
        } elseif (isset($orderEntity['currency']) && is_string($orderEntity['currency'])) {
            $currency = strtoupper($orderEntity['currency']);
        }

        $providerStatus = null;
        if (isset($paymentEntity['status']) && is_string($paymentEntity['status'])) {
            $providerStatus = $paymentEntity['status'];
        } elseif (isset($orderEntity['status']) && is_string($orderEntity['status'])) {
            $providerStatus = $orderEntity['status'];
        }

        $captured = null;
        if (array_key_exists('captured', $paymentEntity)) {
            $captured = (bool) $paymentEntity['captured'];
        } elseif ($providerStatus !== null) {
            $captured = in_array(strtolower($providerStatus), ['captured', 'paid'], true);
        }

        $failureCode = isset($paymentEntity['error_code']) && is_string($paymentEntity['error_code'])
            ? $paymentEntity['error_code']
            : null;
        $failureCategory = isset($paymentEntity['error_reason']) && is_string($paymentEntity['error_reason'])
            ? $paymentEntity['error_reason']
            : null;

        $occurredAt = null;
        if (isset($payload['created_at']) && is_numeric($payload['created_at'])) {
            $occurredAt = (new DateTimeImmutable('@' . (int) $payload['created_at']))
                ->setTimezone(new DateTimeZone('UTC'));
        }

        $supported = RazorpayWebhookEventTypes::isHandled($eventType)
            && $eventType !== ''
            && ($providerOrderId !== null || $providerPaymentId !== null);

        return new NormalizedWebhookEvent(
            provider: PaymentProvider::RAZORPAY,
            providerEventId: $providerEventId,
            eventType: $eventType !== '' ? $eventType : 'unknown',
            providerOrderId: $providerOrderId,
            providerPaymentId: $providerPaymentId,
            amountMinor: $amountMinor,
            currency: $currency,
            providerStatus: $providerStatus,
            captured: $captured,
            failureCode: $failureCode,
            failureCategory: $failureCategory,
            occurredAt: $occurredAt,
            payloadDigest: hash('sha256', $rawBody),
            supported: $supported,
        );
    }
}
