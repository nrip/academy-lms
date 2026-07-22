<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\RBAC;

use Academy\Domain\RBAC\PendingVerificationAllowList;
use PHPUnit\Framework\TestCase;

final class PendingVerificationAllowListTest extends TestCase
{
    private const EXPECTED_KEYS = [
        'identity.session.view_own',
        'identity.session.revoke_own',
        'identity.password.change_own',
        'identity.verification.view_own',
        'identity.verification.resend_own',
    ];

    public function testContainsExactlyTheFiveExpectedKeys(): void
    {
        $keys = PendingVerificationAllowList::keys();
        sort($keys);
        $expected = self::EXPECTED_KEYS;
        sort($expected);

        self::assertCount(5, $keys);
        self::assertSame($expected, $keys);
    }

    /**
     * @dataProvider expectedKeysProvider
     */
    public function testContainsReturnsTrueForEachExpectedKey(string $key): void
    {
        self::assertTrue(PendingVerificationAllowList::contains($key));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function expectedKeysProvider(): array
    {
        return array_map(static fn (string $key): array => [$key], self::EXPECTED_KEYS);
    }

    public function testContainsReturnsFalseForUnknownKey(): void
    {
        self::assertFalse(PendingVerificationAllowList::contains('application.create'));
    }

    public function testIntersectionWithPrivilegedOrSensitiveKeysIsEmpty(): void
    {
        $allowListed = PendingVerificationAllowList::keys();
        $privileged = PendingVerificationAllowList::privilegedOrSensitiveKeysForRegression();

        self::assertSame([], array_values(array_intersect($allowListed, $privileged)));
    }

    public function testNoPrivilegedOrSensitiveKeyPassesContains(): void
    {
        foreach (PendingVerificationAllowList::privilegedOrSensitiveKeysForRegression() as $key) {
            self::assertFalse(
                PendingVerificationAllowList::contains($key),
                sprintf('Privileged/sensitive key "%s" must never be pending-allow-listed.', $key),
            );
        }
    }

    public function testPrivilegedRegressionListIsNotEmpty(): void
    {
        self::assertNotEmpty(PendingVerificationAllowList::privilegedOrSensitiveKeysForRegression());
    }
}
