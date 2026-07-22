<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Http;

use Academy\Http\Security\ConfirmationCookieSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfirmationCookieSettingsTest extends TestCase
{
    public function testSeparateEmailAndResetNames(): void
    {
        $settings = ConfirmationCookieSettings::fromEnvFlags(false, false);

        self::assertSame(ConfirmationCookieSettings::EMAIL_CONFIRM_PLAIN, $settings->emailConfirmCookieName);
        self::assertSame(ConfirmationCookieSettings::RESET_CONFIRM_PLAIN, $settings->resetConfirmCookieName);
        self::assertSame(ConfirmationCookieSettings::RESET_AUTH_PLAIN, $settings->resetAuthCookieName);
        self::assertNotSame($settings->emailConfirmCookieName, $settings->resetConfirmCookieName);
        self::assertNotSame($settings->resetConfirmCookieName, $settings->resetAuthCookieName);

        $email = $settings->buildSetCookie('email_verify', 'abc');
        $reset = $settings->buildSetCookie('password_reset', 'abc');
        $auth = $settings->buildResetAuthSetCookie('def');
        self::assertStringStartsWith('acad_email_confirm=', $email);
        self::assertStringStartsWith('acad_reset_confirm=', $reset);
        self::assertStringStartsWith('acad_reset_auth=', $auth);
        self::assertStringContainsString('HttpOnly', $email);
        self::assertStringContainsString('Path=/', $email);
        self::assertStringNotContainsString('Domain=', $email);
    }

    public function testHostPrefixRequiresSecure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__Host- cookies require the Secure attribute.');
        new ConfirmationCookieSettings(
            ConfirmationCookieSettings::EMAIL_CONFIRM_HOST,
            ConfirmationCookieSettings::RESET_CONFIRM_HOST,
            ConfirmationCookieSettings::RESET_AUTH_HOST,
            false,
        );
    }

    public function testHostPrefixOkWithSecureAndEmptyDomainRootPath(): void
    {
        $settings = ConfirmationCookieSettings::fromEnvFlags(true, true);
        $header = $settings->buildSetCookie('email_verify', 'secret');

        self::assertStringStartsWith('__Host-acad_email_confirm=', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringNotContainsString('Domain=', $header);
    }

    public function testNonRootPathRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path=/');
        new ConfirmationCookieSettings(
            'acad_email_confirm',
            'acad_reset_confirm',
            'acad_reset_auth',
            false,
            path: '/app',
        );
    }

    public function testDomainRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Domain');
        new ConfirmationCookieSettings(
            'acad_email_confirm',
            'acad_reset_confirm',
            'acad_reset_auth',
            false,
            domain: 'example.com',
        );
    }
}
