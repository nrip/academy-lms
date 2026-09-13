<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Notifications;

use Academy\Domain\Notifications\InAppNotificationHref;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use PHPUnit\Framework\TestCase;

final class InAppNotificationHrefTest extends TestCase
{
    public function testCertificateLinkBecomesAnInternalPath(): void
    {
        $href = InAppNotificationHref::fromVariables(
            TransactionalNotificationEventTypes::CERTIFICATE_ISSUED,
            ['certificate_link' => 'https://academy.example/certificates/42'],
        );

        self::assertSame('/certificates/42', $href);
    }

    public function testQueryStringIsDropped(): void
    {
        $href = InAppNotificationHref::internalPath('https://academy.example/certificates/42?token=secret');

        self::assertSame('/certificates/42', $href);
    }

    public function testRejectsExternalAndTokenLikeTargets(): void
    {
        self::assertNull(InAppNotificationHref::internalPath('javascript:alert(1)'));
        self::assertNull(InAppNotificationHref::internalPath('https://evil.example/admin/notifications'));
        self::assertNull(InAppNotificationHref::internalPath('//evil.example/dashboard'));
        self::assertNull(InAppNotificationHref::internalPath('/learning/../.env'));
    }

    public function testFallsBackToDashboard(): void
    {
        self::assertSame(
            '/dashboard',
            InAppNotificationHref::fromVariables(TransactionalNotificationEventTypes::APPLICATION_SUBMITTED, []),
        );
    }
}
