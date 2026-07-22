<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Audit\IdentityTokenAuditPayload;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\RawVerificationToken;
use Academy\Domain\Identity\TokenConfirmationContextRepository;
use Academy\Domain\Identity\TokenConsumedHandler;
use Academy\Domain\Identity\TokenHmac;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Infrastructure\Database\TransactionManager;
use PDO;

/**
 * Scanner-safe confirmation: GET creates a short-lived context; POST consumes the parent token.
 */
final class TokenConfirmationService
{
    private const GENERIC_INVALID = 'This confirmation link is no longer valid.';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly VerificationTokenRepository $tokens,
        private readonly TokenConfirmationContextRepository $contexts,
        private readonly TokenHmac $tokenHmac,
        private readonly TokenConsumedHandler $consumedHandler,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * GET path: validate token without locking; create confirmation context in a short TX.
     *
     * @return array{
     *   status: 'ok'|'invalid_or_expired',
     *   confirmation_secret?: string,
     *   purpose?: string,
     *   verification_token_id?: int
     * }
     */
    public function beginConfirmationFromRawToken(
        string $rawToken,
        string $expectedPurpose,
        string $clientIp,
        int $contextTtlSeconds,
    ): array {
        TokenPurpose::assertValid($expectedPurpose);

        try {
            $parsed = RawVerificationToken::parse($rawToken);
        } catch (ValidationException) {
            $this->rateLimiter->hit('auth.verify_link.ip', [
                ['type' => 'ip', 'value' => $clientIp],
            ]);
            // Bound HMAC input: never feed unbounded query strings into crypto.
            $this->rateLimiter->hit('auth.verify_link.token', [
                [
                    'type' => 'token_hmac',
                    'value' => $this->tokenHmac->missingTokenDimension(
                        'malformed|' . hash('sha256', substr($rawToken, 0, 256)),
                    ),
                ],
            ]);

            return ['status' => 'invalid_or_expired'];
        }

        $this->rateLimiter->hit('auth.verify_link.ip', [
            ['type' => 'ip', 'value' => $clientIp],
        ]);

        $tokenHash = $this->tokenHmac->hash($parsed->value());
        // GET must NOT lock the parent verification token.
        $candidate = $this->tokens->findByHash($tokenHash);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($candidate === null) {
            $this->rateLimiter->hit('auth.verify_link.token', [
                [
                    'type' => 'token_hmac',
                    'value' => $this->tokenHmac->missingTokenDimension($parsed->value()),
                ],
            ]);

            return ['status' => 'invalid_or_expired'];
        }

        $this->rateLimiter->hit('auth.verify_link.token', [
            [
                'type' => 'token_id',
                'value' => (string) $candidate->verificationTokenId,
            ],
        ]);

        if (
            $candidate->purpose !== $expectedPurpose
            || !$candidate->isUsable($now)
        ) {
            return ['status' => 'invalid_or_expired'];
        }

        $rawConfirmationSecret = bin2hex(random_bytes(32));
        $confirmationHash = $this->tokenHmac->hash($rawConfirmationSecret);
        $expiresAt = $now->modify('+' . max(1, $contextTtlSeconds) . ' seconds');

        $contextId = $this->transactions->run(function (PDO $pdo) use (
            $confirmationHash,
            $candidate,
            $expiresAt,
            $now,
        ): int {
            unset($pdo);
            $contextId = $this->contexts->insert(
                $confirmationHash,
                $candidate->verificationTokenId,
                $candidate->userId,
                $candidate->purpose,
                $expiresAt,
                $now,
            );

            $this->audit->record(
                new IdentityTokenAuditPayload(
                    'identity.token_context_created',
                    'token_confirmation_context',
                    (string) $contextId,
                    next: [
                        'context_id' => $contextId,
                        'verification_token_id' => $candidate->verificationTokenId,
                        'user_id' => $candidate->userId,
                        'purpose' => $candidate->purpose,
                    ],
                ),
                'system',
                null,
                'http',
            );

            return $contextId;
        });

        unset($contextId);

        return [
            'status' => 'ok',
            'confirmation_secret' => $rawConfirmationSecret,
            'purpose' => $candidate->purpose,
            'verification_token_id' => $candidate->verificationTokenId,
        ];
    }

    /**
     * POST path: lock context then token; consume both; invoke TokenConsumedHandler.
     */
    public function confirm(string $rawConfirmationSecret, string $expectedPurpose): void
    {
        TokenPurpose::assertValid($expectedPurpose);

        try {
            $this->transactions->run(function (PDO $pdo) use ($rawConfirmationSecret, $expectedPurpose): void {
                unset($pdo);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                if ($rawConfirmationSecret === '' || strlen($rawConfirmationSecret) !== 64 || !ctype_xdigit($rawConfirmationSecret)) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                $confirmationHash = $this->tokenHmac->hash(strtolower($rawConfirmationSecret));
                // Lock order: context then token.
                $context = $this->contexts->findByHashForUpdate($confirmationHash);
                if ($context === null || !$context->isUsable($now) || $context->purpose !== $expectedPurpose) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                $token = $this->tokens->findByIdForUpdate($context->verificationTokenId);
                if ($token === null || !$token->isUsable($now) || $token->purpose !== $expectedPurpose) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                if (!$this->tokens->conditionalConsumeById($token->verificationTokenId, $now)) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                if (!$this->contexts->markConsumed($context->tokenConfirmationContextId, $now)) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                $this->consumedHandler->onConsumed(
                    $token->userId,
                    $token->purpose,
                    $token->verificationTokenId,
                );

                $this->audit->record(
                    new IdentityTokenAuditPayload(
                        'identity.token_context_consumed',
                        'token_confirmation_context',
                        (string) $context->tokenConfirmationContextId,
                        previous: [
                            'context_id' => $context->tokenConfirmationContextId,
                            'verification_token_id' => $token->verificationTokenId,
                            'user_id' => $token->userId,
                            'purpose' => $token->purpose,
                        ],
                        next: [
                            'context_id' => $context->tokenConfirmationContextId,
                            'verification_token_id' => $token->verificationTokenId,
                            'user_id' => $token->userId,
                            'purpose' => $token->purpose,
                        ],
                    ),
                    'system',
                    null,
                    'http',
                );
            });
        } catch (DomainRuleException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new DomainRuleException(self::GENERIC_INVALID);
        }
    }
}
