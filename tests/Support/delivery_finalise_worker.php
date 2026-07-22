<?php

declare(strict_types=1);

/**
 * Concurrent delivery finaliser worker.
 * Args: kind record_id outbox_message_id locked_by claim_token attempt_count provider_message_id
 * Prints: ok | stale | error:<class>
 */

use Academy\Application\Notifications\DeliveryFinaliser;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Outbox\OutboxMessage;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kind = (string) ($argv[1] ?? '');
$recordId = (int) ($argv[2] ?? 0);
$outboxId = (int) ($argv[3] ?? 0);
$lockedBy = (string) ($argv[4] ?? '');
$claimToken = (string) ($argv[5] ?? '');
$attemptCount = (int) ($argv[6] ?? 1);
$providerMessageId = (string) ($argv[7] ?? 'prov');

if ($kind === '' || $recordId < 1 || $outboxId < 1 || $lockedBy === '' || $claimToken === '') {
    fwrite(STDERR, "usage: delivery_finalise_worker.php <kind> <record_id> <outbox_id> <locked_by> <claim_token> [attempt] [provider_id]\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$message = new OutboxMessage(
    id: $outboxId,
    eventType: 'identity.email_verification.send',
    aggregateType: 'verification_token',
    aggregateId: (string) $recordId,
    payload: ['verification_token_id' => $recordId, 'purpose' => 'email_verify'],
    idempotencyKey: 'worker-' . $outboxId,
    status: 'processing',
    attemptCount: $attemptCount,
    lockedBy: $lockedBy,
    claimToken: $claimToken,
);

try {
    $container = ApplicationFactory::container('testing');
    /** @var DeliveryFinaliser $finaliser */
    $finaliser = $container->get(DeliveryFinaliser::class);
    $finaliser->finalizeDelivered($kind, $recordId, $message, $providerMessageId);
    echo 'ok';
    exit(0);
} catch (DomainRuleException) {
    echo 'stale';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
