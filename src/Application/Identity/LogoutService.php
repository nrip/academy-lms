<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Security\SessionService;
use Academy\Domain\Audit\IdentityAuthAuditPayload;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Security\SessionCookieClearance;

/**
 * Revokes the current session only. Does not revoke all sessions.
 */
final class LogoutService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuditService $audit,
    ) {
    }

    public function logout(
        ?SessionRecord $session,
        ?int $userId,
        ?SessionCookieClearance $clearance,
    ): void {
        if ($session !== null) {
            $sessionRecordId = $session->sessionId;
            try {
                $this->sessions->revoke($session);
            } catch (\Throwable) {
                // Idempotent: proceed to clear cookies even if revoke fails.
            }

            if ($clearance !== null) {
                $clearance->requestClear();
            }

            $this->audit->record(
                new IdentityAuthAuditPayload(
                    action: 'identity.logout',
                    entityType: 'session',
                    entityId: (string) $sessionRecordId,
                    next: [
                        'user_id' => $userId,
                        'result' => 'logged_out',
                        'session_record_id' => $sessionRecordId,
                    ],
                ),
                actorType: $userId !== null ? 'user' : 'system',
                actorUserId: $userId,
                source: 'logout',
            );

            return;
        }

        if ($clearance !== null) {
            $clearance->requestClear();
        }
    }
}
