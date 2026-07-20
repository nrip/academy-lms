<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Http\Security;

use Academy\Http\Security\SessionCookieSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionCookieSettingsTest extends TestCase
{
    public function testLocalDevelopmentCookieNames(): void
    {
        $settings = new SessionCookieSettings('acad_session', 'acad_csrf', false);

        self::assertSame('acad_session', $settings->sessionCookieName);
        self::assertSame('acad_csrf', $settings->csrfCookieName);

        $sessionCookie = $settings->buildSessionSetCookie('token-value');
        self::assertStringStartsWith('acad_session=token-value;', $sessionCookie);
        self::assertStringContainsString('HttpOnly', $sessionCookie);
        self::assertStringContainsString('Path=/', $sessionCookie);
        self::assertStringContainsString('SameSite=Lax', $sessionCookie);
        self::assertStringNotContainsString('Secure', $sessionCookie);
        self::assertStringNotContainsString('Domain=', $sessionCookie);

        $csrfCookie = $settings->buildCsrfSetCookie('csrf-value');
        self::assertStringStartsWith('acad_csrf=csrf-value;', $csrfCookie);
        self::assertStringNotContainsString('HttpOnly', $csrfCookie);
    }

    public function testStagingProductionHostPrefixedCookies(): void
    {
        $settings = new SessionCookieSettings('__Host-acad_session', '__Host-acad_csrf', true);

        $sessionCookie = $settings->buildSessionSetCookie('token-value');
        self::assertStringStartsWith('__Host-acad_session=token-value;', $sessionCookie);
        self::assertStringContainsString('Secure', $sessionCookie);
        self::assertStringContainsString('Path=/', $sessionCookie);
        self::assertStringNotContainsString('Domain=', $sessionCookie);

        $csrfCookie = $settings->buildCsrfSetCookie('csrf-value');
        self::assertStringStartsWith('__Host-acad_csrf=csrf-value;', $csrfCookie);
        self::assertStringContainsString('Secure', $csrfCookie);
        self::assertStringNotContainsString('HttpOnly', $csrfCookie);
    }

    public function testHostPrefixWithoutSecureIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__Host- cookies require the Secure attribute.');

        new SessionCookieSettings('__Host-acad_session', 'acad_csrf', false);
    }

    public function testHostPrefixWithDomainIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__Host- cookies must not include a Domain attribute.');

        new SessionCookieSettings('__Host-acad_session', '__Host-acad_csrf', true, domain: 'example.com');
    }

    public function testHostPrefixWithNonRootPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__Host- cookies require Path=/');

        new SessionCookieSettings('__Host-acad_session', '__Host-acad_csrf', true, path: '/app');
    }

    public function testFromSessionConfigUsesSecurityArray(): void
    {
        $settings = SessionCookieSettings::fromSessionConfig([
            'cookie_secure' => true,
            'cookies' => [
                'session_name' => '__Host-acad_session',
                'csrf_name' => '__Host-acad_csrf',
            ],
        ]);

        self::assertSame('__Host-acad_session', $settings->sessionCookieName);
        self::assertSame('__Host-acad_csrf', $settings->csrfCookieName);
        self::assertTrue($settings->secure);
    }
}
