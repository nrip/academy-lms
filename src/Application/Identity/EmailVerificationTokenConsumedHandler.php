<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\IdentityRegistrationAuditPayload;
use Academy\Domain\Identity\TokenConsumedHandler;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\UserWriteRepository;

/**
 * Ambient-only handler: runs inside TokenConfirmationService's transaction.
 * Must not open TransactionManager.
 */
final class EmailVerificationTokenConsumedHandler implements TokenConsumedHandler
{
    public function __construct(
        private readonly UserWriteRepository $users,
        private readonly AuditService $audit,
    ) {
    }

    public function onConsumed(int $userId, string $purpose, int $verificationTokenId): void
    {
        if ($purpose !== TokenPurpose::EMAIL_VERIFY) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = $this->users->applyEmailVerification($userId, $now);

        $this->audit->record(
            new IdentityRegistrationAuditPayload(
                action: 'identity.email_verified',
                entityType: 'user',
                entityId: (string) $userId,
                previous: [
                    'user_id' => $userId,
                    'verification_token_id' => $verificationTokenId,
                    'purpose' => $purpose,
                    'email_verified' => $result['email_was_null'] ? 0 : 1,
                ],
                next: [
                    'user_id' => $userId,
                    'verification_token_id' => $verificationTokenId,
                    'purpose' => $purpose,
                    'email_verified' => 1,
                    'account_status' => $result['account_status'],
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: 'http',
        );

        if ($result['activated']) {
            $this->audit->record(
                new IdentityRegistrationAuditPayload(
                    action: 'identity.account_activated',
                    entityType: 'user',
                    entityId: (string) $userId,
                    previous: [
                        'user_id' => $userId,
                        'from_status' => 'pending_verification',
                    ],
                    next: [
                        'user_id' => $userId,
                        'to_status' => $result['account_status'],
                        'account_status' => $result['account_status'],
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'http',
            );
        }
    }
}
