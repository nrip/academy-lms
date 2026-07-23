<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Notifications\NotificationFailureCategory;

/**
 * Resolves verified email from authoritative account data. Never trusts client input.
 */
final class NotificationRecipientResolver
{
    public function __construct(
        private readonly UserWriteRepository $users,
        private readonly string $recipientHashPepper,
    ) {
    }

    /**
     * @return array{
     *   email: string,
     *   recipient_hash: string,
     *   recipient_masked: string,
     *   display_name: string
     * }
     */
    public function resolveVerifiedEmail(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new DomainRuleException(NotificationFailureCategory::ACCOUNT_DELETED);
        }

        $status = (string) $user['account_status'];
        if ($status === AccountStatus::SUSPENDED) {
            throw new DomainRuleException(NotificationFailureCategory::ACCOUNT_SUSPENDED);
        }
        if ($status !== AccountStatus::ACTIVE) {
            throw new DomainRuleException(NotificationFailureCategory::MISSING_VERIFICATION);
        }

        if ($user['email_verified_at'] === null || $user['email_verified_at'] === '') {
            throw new DomainRuleException(NotificationFailureCategory::MISSING_VERIFICATION);
        }

        $email = trim((string) $user['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleException(NotificationFailureCategory::INVALID_RECIPIENT);
        }

        $displayName = $email;
        $at = strpos($email, '@');
        if ($at !== false && $at > 0) {
            $displayName = substr($email, 0, $at);
        }

        return [
            'email' => $email,
            'recipient_hash' => hash_hmac('sha256', strtolower($email), $this->recipientHashPepper),
            'recipient_masked' => $this->maskEmail($email),
            'display_name' => $displayName,
        ];
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', strtolower($email), 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        $localMasked = strlen($local) <= 1
            ? '*'
            : substr($local, 0, 1) . str_repeat('*', min(3, strlen($local) - 1));

        return $localMasked . '@' . $domain;
    }
}
