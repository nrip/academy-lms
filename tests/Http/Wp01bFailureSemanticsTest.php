<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\UserSecuritySnapshot;
use Academy\Domain\Identity\UserSecuritySnapshotRepository;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\AuthContext;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\RequirePermissionMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Security\SessionCookieSettings;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

final class Wp01bFailureSemanticsTest extends TestCase
{
    public function testBoundSessionSnapshotStoreFailureReturns503Semantics(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $session = new SessionRecord(
            1,
            'hash',
            99,
            [],
            null,
            $now,
            $now,
            $now->modify('+1 hour'),
            $now->modify('+1 hour'),
            null,
            1,
        );
        $snapshots = new class () implements UserSecuritySnapshotRepository {
            public function findById(int $userId): ?UserSecuritySnapshot
            {
                throw new \RuntimeException('forced snapshot failure');
            }
        };

        $sessions = new SessionService(
            new class () implements \Academy\Domain\Security\SessionRepository {
                public function findByTokenHash(string $tokenHash): ?SessionRecord
                {
                    return null;
                }

                public function create(string $tokenHash, ?string $csrfTokenHash, array $payload, \DateTimeImmutable $createdAt, \DateTimeImmutable $absoluteExpiresAt, \DateTimeImmutable $idleExpiresAt, ?string $ipAddress, ?string $userAgentHash): SessionRecord
                {
                    throw new \RuntimeException('unused');
                }

                public function regenerate(int $sessionId, string $newTokenHash, ?string $newCsrfTokenHash, \DateTimeImmutable $now, \DateTimeImmutable $absoluteExpiresAt, \DateTimeImmutable $idleExpiresAt): void
                {
                }

                public function updateCsrfHash(int $sessionId, string $csrfTokenHash): void
                {
                }

                public function touch(int $sessionId, \DateTimeImmutable $lastActivityAt, \DateTimeImmutable $idleExpiresAt): void
                {
                }

                public function revoke(int $sessionId, \DateTimeImmutable $revokedAt): void
                {
                }

                public function bindUser(int $sessionId, int $userId, int $authVersion, array $payloadMerge = []): void
                {
                }

                public function revokeAllForUser(int $userId, \DateTimeImmutable $revokedAt): int
                {
                    return 0;
                }

                public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
                {
                    return 0;
                }
            },
            new CsrfTokenManager(),
            new NullLogger(),
            ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
            ['idle_seconds' => 900, 'absolute_seconds' => 28800],
            300,
        );

        $middleware = new AuthenticationMiddleware(
            $snapshots,
            $sessions,
            new SessionCookieSettings('acad_session', 'acad_csrf', false),
        );

        $this->expectException(ServiceUnavailableException::class);
        $middleware->process(
            (new ServerRequest())->withAttribute(SessionMiddleware::ATTR_SESSION, $session),
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new JsonResponse(['ok' => true]);
                }
            },
        );
    }

    public function testPermissionStoreFailureReturns503Not403(): void
    {
        $permissions = new class () implements PermissionRepository {
            public function permissionKeysForUser(int $userId): array
            {
                throw new \RuntimeException('forced permission failure');
            }

            public function permissionKeysForRoleKey(string $roleKey): array
            {
                return [];
            }
        };
        $middleware = new RequirePermissionMiddleware(
            new AuthorizationService($permissions),
            'rbac.role.view',
        );
        $context = AuthContext::authenticated(1, 1, AuthStage::FULLY_AUTHENTICATED, 1, true, 'active');

        $this->expectException(ServiceUnavailableException::class);
        $middleware->process(
            (new ServerRequest())->withAttribute(AuthenticationMiddleware::ATTR_AUTH, $context),
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new JsonResponse(['ok' => true]);
                }
            },
        );
    }
}
