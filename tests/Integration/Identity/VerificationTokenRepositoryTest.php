<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Notifications\SealedSecret;
use Academy\Infrastructure\Identity\PdoVerificationTokenRepository;
use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Academy\Tests\Support\DatabaseTestCase;
use PDOException;
use PHPUnit\Framework\TestCase;

final class VerificationTokenRepositoryTest extends TestCase
{
    private PdoVerificationTokenRepository $repo;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
        $this->repo = new PdoVerificationTokenRepository(DatabaseTestCase::connectionFactory());
    }

    public function testSupersedeCurrentMarkerAndUniqueCurrent(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires = $now->modify('+1 hour');

        $firstId = $this->repo->insertPendingCurrent(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'first'),
            $expires,
            $now,
        );
        $this->repo->clearCurrentMarker($firstId);
        $secondId = $this->repo->insertPendingCurrent(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'second'),
            $expires,
            $now,
        );

        $current = $this->repo->findCurrentByUserPurposeForUpdate($user['user_id'], TokenPurpose::EMAIL_VERIFY);
        self::assertNotNull($current);
        self::assertSame($secondId, $current->verificationTokenId);
        self::assertNull($this->repo->findById($firstId)?->currentMarker);

        $pdo = DatabaseTestCase::pdo();
        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO verification_tokens (
                user_id, purpose, token_hash, expires_at, consumed_at, current_marker,
                delivery_status, created_at
            ) VALUES (?, ?, ?, ?, NULL, 1, ?, ?)',
        )->execute([
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'dup-current'),
            $expires->format('Y-m-d H:i:s.u'),
            'pending',
            $now->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function testConditionalConsumeClearsSealAndMarker(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->repo->insertPendingCurrent(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'consume-me'),
            $now->modify('+1 hour'),
            $now,
        );
        $box = new SealedSecretBox(new NotificationKeyMaterial(base64_encode(str_repeat("\3", 32)), 1));
        $sealed = $box->seal('{"email":"a@b.test"}', SealedSecretBox::tokenAad($id, TokenPurpose::EMAIL_VERIFY, $user['user_id']));
        $this->repo->updateSealedDelivery($id, $sealed, $now);

        self::assertTrue($this->repo->conditionalConsumeById($id, $now));
        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertNotNull($row->consumedAt);
        self::assertNull($row->currentMarker);
        self::assertNull($row->deliveryCiphertext);
        self::assertFalse($this->repo->conditionalConsumeById($id, $now));
    }

    public function testMarkDeliveredClearsSeal(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->repo->insertPendingCurrent(
            $user['user_id'],
            TokenPurpose::PASSWORD_RESET,
            hash('sha256', 'deliver-me'),
            $now->modify('+1 hour'),
            $now,
        );
        $this->repo->updateSealedDelivery(
            $id,
            new SealedSecret(str_repeat('c', 32), str_repeat('n', 24), 1),
            $now,
        );

        self::assertTrue($this->repo->markDelivered($id, 'prov-1', $now));
        $row = $this->repo->findById($id);
        self::assertSame('delivered', $row?->deliveryStatus);
        self::assertNull($row?->deliveryCiphertext);
        self::assertSame('prov-1', $row?->providerMessageId);
        self::assertFalse($this->repo->markDelivered($id, 'prov-2', $now));
    }

    public function testMarkTerminal(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->repo->insertPendingCurrent(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'terminal-me'),
            $now->modify('+1 hour'),
            $now,
        );

        self::assertTrue($this->repo->markTerminal($id, 'delivery_failed:X', $now));
        $row = $this->repo->findById($id);
        self::assertSame('terminal', $row?->deliveryStatus);
        self::assertSame('delivery_failed:X', $row?->deliveryLastError);
        self::assertFalse($this->repo->markTerminal($id, 'again', $now));
    }

    public function testCheckConstraintRejectsInvalidPurpose(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO verification_tokens (
                user_id, purpose, token_hash, expires_at, delivery_status, created_at, current_marker
            ) VALUES (?, ?, ?, ?, ?, ?, 1)',
        )->execute([
            $user['user_id'],
            'not_a_purpose',
            hash('sha256', 'bad-purpose'),
            $now->modify('+1 hour')->format('Y-m-d H:i:s.u'),
            'pending',
            $now->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function testCheckConstraintRejectsIncoherentSeal(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO verification_tokens (
                user_id, purpose, token_hash, expires_at, delivery_status, created_at,
                current_marker, delivery_ciphertext, delivery_nonce, delivery_key_version
            ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NULL, NULL)',
        )->execute([
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'bad-seal'),
            $now->modify('+1 hour')->format('Y-m-d H:i:s.u'),
            'pending',
            $now->format('Y-m-d H:i:s.u'),
            'cipher-only',
        ]);
    }
}
