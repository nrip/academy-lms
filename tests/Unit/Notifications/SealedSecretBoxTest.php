<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\SealedSecret;
use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SealedSecretBoxTest extends TestCase
{
    public function testRoundTripSealUnseal(): void
    {
        $box = $this->boxWithCurrentOnly();
        $aad = SealedSecretBox::tokenAad(1, 'email_verify', 9);
        $sealed = $box->seal('hello-secret', $aad);

        self::assertSame('hello-secret', $box->unseal($sealed, $aad));
    }

    public function testWrongKeyVersionRejected(): void
    {
        $box = $this->boxWithCurrentOnly();
        $aad = '1|email_verify|1';
        $sealed = $box->seal('payload', $aad);
        $wrong = new SealedSecret($sealed->ciphertext, $sealed->nonce, 99);

        $this->expectException(DomainRuleException::class);
        $box->unseal($wrong, $aad);
    }

    public function testPreviousKeyStillWorks(): void
    {
        $prevRaw = random_bytes(32);
        $currRaw = random_bytes(32);
        $keys = new NotificationKeyMaterial(
            base64_encode($currRaw),
            2,
            base64_encode($prevRaw),
            1,
        );
        $box = new SealedSecretBox($keys);
        $aad = SealedSecretBox::challengeAad(5, 'sms', 3);

        // Seal with previous key material by constructing a box that only knows v1 as current,
        // then open with rotated keys.
        $legacyBox = new SealedSecretBox(new NotificationKeyMaterial(base64_encode($prevRaw), 1));
        $sealed = $legacyBox->seal('otp=123456', $aad);
        self::assertSame(1, $sealed->keyVersion);
        self::assertSame('otp=123456', $box->unseal($sealed, $aad));
    }

    public function testWrongAadRejected(): void
    {
        $box = $this->boxWithCurrentOnly();
        $sealed = $box->seal('payload', 'correct-aad');

        $this->expectException(DomainRuleException::class);
        $box->unseal($sealed, 'wrong-aad');
    }

    public function testTruncatedCiphertextRejected(): void
    {
        $box = $this->boxWithCurrentOnly();
        $aad = 'aad';
        $sealed = $box->seal('payload', $aad);
        $truncated = new SealedSecret(substr($sealed->ciphertext, 0, 4), $sealed->nonce, $sealed->keyVersion);

        $this->expectException(DomainRuleException::class);
        $box->unseal($truncated, $aad);
    }

    public function testNotificationKeyMaterialRejectsMalformedBase64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid base64');
        new NotificationKeyMaterial('!!!not-base64!!!', 1);
    }

    public function testNotificationKeyMaterialRejectsWrongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        new NotificationKeyMaterial(base64_encode('too-short'), 1);
    }

    public function testNotificationKeyMaterialRejectsNonCanonicalBase64(): void
    {
        $canonical = base64_encode(str_repeat("\1", 32));
        // Strip padding — non-canonical for strict round-trip check.
        $padded = rtrim($canonical, '=');
        if ($padded === $canonical) {
            self::markTestSkipped('Canonical encoding had no padding to strip.');
        }

        $this->expectException(InvalidArgumentException::class);
        new NotificationKeyMaterial($padded, 1);
    }

    private function boxWithCurrentOnly(): SealedSecretBox
    {
        return new SealedSecretBox(
            new NotificationKeyMaterial(base64_encode(str_repeat("\2", 32)), 1),
        );
    }
}
