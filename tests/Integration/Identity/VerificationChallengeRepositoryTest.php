<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Domain\Notifications\SealedSecret;
use Academy\Infrastructure\Identity\PdoVerificationChallengeRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PDOException;
use PHPUnit\Framework\TestCase;

final class VerificationChallengeRepositoryTest extends TestCase
{
    private PdoVerificationChallengeRepository $repo;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
        $this->repo = new PdoVerificationChallengeRepository(DatabaseTestCase::connectionFactory());
    }

    public function testSupersedeConsumeAndDelivery(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires = $now->modify('+10 minutes');

        $first = $this->repo->insertPendingCurrent(
            $user['user_id'],
            'sms',
            hash('sha256', 'dest-1'),
            random_bytes(16),
            hash('sha256', 'otp-1'),
            $expires,
            10,
            $now,
        );
        $this->repo->clearCurrentMarker($first);
        $second = $this->repo->insertPendingCurrent(
            $user['user_id'],
            'sms',
            hash('sha256', 'dest-2'),
            random_bytes(16),
            hash('sha256', 'otp-2'),
            $expires,
            10,
            $now,
        );

        $current = $this->repo->findCurrentByUserChannelForUpdate($user['user_id'], 'sms');
        self::assertSame($second, $current?->verificationChallengeId);

        $this->repo->updateSealedDelivery(
            $second,
            new SealedSecret(str_repeat('c', 16), str_repeat('n', 24), 1),
            $now,
        );

        self::assertTrue($this->repo->markDelivered($second, 'sms-prov', $now));
        $delivered = $this->repo->findById($second);
        self::assertSame('delivered', $delivered?->deliveryStatus);
        self::assertNull($delivered?->otpDeliveryCiphertext);

        // New current for consume path.
        $this->repo->clearCurrentMarker($second);
        $third = $this->repo->insertPendingCurrent(
            $user['user_id'],
            'sms',
            hash('sha256', 'dest-3'),
            random_bytes(16),
            hash('sha256', 'otp-3'),
            $expires,
            10,
            $now,
        );
        self::assertTrue($this->repo->conditionalConsumeById($third, $now));
        self::assertNotNull($this->repo->findById($third)?->consumedAt);
        self::assertNull($this->repo->findById($third)?->currentMarker);
    }

    public function testMarkTerminal(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->repo->insertPendingCurrent(
            $user['user_id'],
            'sms',
            hash('sha256', 'dest-t'),
            random_bytes(16),
            hash('sha256', 'otp-t'),
            $now->modify('+10 minutes'),
            5,
            $now,
        );

        self::assertTrue($this->repo->markTerminal($id, 'delivery_failed:Y', $now));
        self::assertSame('terminal', $this->repo->findById($id)?->deliveryStatus);
    }

    public function testCheckConstraintRejectsBadChannel(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO verification_challenges (
                user_id, channel, destination_hmac, otp_binding_nonce, otp_hmac, expires_at,
                attempt_count, max_attempts, current_marker, delivery_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, 10, 1, ?, ?)',
        )->execute([
            $user['user_id'],
            'email',
            hash('sha256', 'd'),
            random_bytes(16),
            hash('sha256', 'o'),
            $now->modify('+10 minutes')->format('Y-m-d H:i:s.u'),
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
            'INSERT INTO verification_challenges (
                user_id, channel, destination_hmac, otp_binding_nonce, otp_hmac, expires_at,
                attempt_count, max_attempts, current_marker, delivery_status, created_at,
                otp_delivery_ciphertext, otp_delivery_nonce, otp_delivery_key_version
            ) VALUES (?, ?, ?, ?, ?, ?, 0, 10, 1, ?, ?, ?, NULL, NULL)',
        )->execute([
            $user['user_id'],
            'sms',
            hash('sha256', 'seal-dest'),
            random_bytes(16),
            hash('sha256', 'seal-otp'),
            $now->modify('+10 minutes')->format('Y-m-d H:i:s.u'),
            'pending',
            $now->format('Y-m-d H:i:s.u'),
            'cipher-only',
        ]);
    }

    public function testCheckConstraintPendingRejectsDeliveredAt(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ts = $now->format('Y-m-d H:i:s.u');
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO verification_challenges (
                user_id, channel, destination_hmac, otp_binding_nonce, otp_hmac, expires_at,
                attempt_count, max_attempts, current_marker, delivery_status, created_at, delivered_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, 10, 1, ?, ?, ?)',
        )->execute([
            $user['user_id'],
            'sms',
            hash('sha256', 'pending-delivered-at'),
            random_bytes(16),
            hash('sha256', 'otp-pending-da'),
            $now->modify('+10 minutes')->format('Y-m-d H:i:s.u'),
            'pending',
            $ts,
            $ts,
        ]);
    }

    public function testCheckConstraintPendingRejectsTerminalAt(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ts = $now->format('Y-m-d H:i:s.u');
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO verification_challenges (
                user_id, channel, destination_hmac, otp_binding_nonce, otp_hmac, expires_at,
                attempt_count, max_attempts, current_marker, delivery_status, created_at, terminal_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, 10, 1, ?, ?, ?)',
        )->execute([
            $user['user_id'],
            'sms',
            hash('sha256', 'pending-terminal-at'),
            random_bytes(16),
            hash('sha256', 'otp-pending-ta'),
            $now->modify('+10 minutes')->format('Y-m-d H:i:s.u'),
            'pending',
            $ts,
            $ts,
        ]);
    }

    public function testCheckConstraintDeliveredRequiresDeliveredAtAndClearedSeal(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->insertPendingChallenge($user['user_id'], 'chk-delivered-req', $now);
        $pdo = DatabaseTestCase::pdo();
        $ts = $now->format('Y-m-d H:i:s.u');

        // delivered without delivered_at — rejected by delivered_state CHECK.
        try {
            $pdo->prepare(
                'UPDATE verification_challenges SET
                    delivery_status = ?,
                    delivered_at = NULL,
                    terminal_at = NULL,
                    otp_delivery_ciphertext = NULL,
                    otp_delivery_nonce = NULL,
                    otp_delivery_key_version = NULL
                 WHERE verification_challenge_id = ?',
            )->execute(['delivered', $id]);
            self::fail('Expected PDOException for delivered without delivered_at.');
        } catch (PDOException) {
            // expected
        }

        // delivered with seal still populated — rejected by delivered_state CHECK.
        $this->expectException(PDOException::class);
        $pdo->prepare(
            'UPDATE verification_challenges SET
                delivery_status = ?,
                delivered_at = ?,
                terminal_at = NULL,
                otp_delivery_ciphertext = ?,
                otp_delivery_nonce = ?,
                otp_delivery_key_version = ?
             WHERE verification_challenge_id = ?',
        )->execute([
            'delivered',
            $ts,
            str_repeat('c', 16),
            str_repeat('n', 24),
            1,
            $id,
        ]);
    }

    public function testCheckConstraintTerminalRequiresTerminalAtAndClearedSeal(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->insertPendingChallenge($user['user_id'], 'chk-terminal-req', $now);
        $pdo = DatabaseTestCase::pdo();
        $ts = $now->format('Y-m-d H:i:s.u');

        // terminal without terminal_at — rejected by terminal_state CHECK.
        try {
            $pdo->prepare(
                'UPDATE verification_challenges SET
                    delivery_status = ?,
                    terminal_at = NULL,
                    delivered_at = NULL,
                    otp_delivery_ciphertext = NULL,
                    otp_delivery_nonce = NULL,
                    otp_delivery_key_version = NULL
                 WHERE verification_challenge_id = ?',
            )->execute(['terminal', $id]);
            self::fail('Expected PDOException for terminal without terminal_at.');
        } catch (PDOException) {
            // expected
        }

        // terminal with seal still populated — rejected by terminal_state CHECK.
        $this->expectException(PDOException::class);
        $pdo->prepare(
            'UPDATE verification_challenges SET
                delivery_status = ?,
                terminal_at = ?,
                delivered_at = NULL,
                otp_delivery_ciphertext = ?,
                otp_delivery_nonce = ?,
                otp_delivery_key_version = ?
             WHERE verification_challenge_id = ?',
        )->execute([
            'terminal',
            $ts,
            str_repeat('c', 16),
            str_repeat('n', 24),
            1,
            $id,
        ]);
    }

    public function testCheckConstraintRejectsBothDeliveredAtAndTerminalAt(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->insertPendingChallenge($user['user_id'], 'chk-both-timestamps', $now);
        $pdo = DatabaseTestCase::pdo();
        $ts = $now->format('Y-m-d H:i:s.u');

        // Reach a valid delivered row first, then attempt to set terminal_at as well.
        $pdo->prepare(
            'UPDATE verification_challenges SET
                delivery_status = ?,
                delivered_at = ?,
                terminal_at = NULL,
                otp_delivery_ciphertext = NULL,
                otp_delivery_nonce = NULL,
                otp_delivery_key_version = NULL,
                otp_delivery_cleared_at = ?
             WHERE verification_challenge_id = ?',
        )->execute(['delivered', $ts, $ts, $id]);

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'UPDATE verification_challenges SET terminal_at = ? WHERE verification_challenge_id = ?',
        )->execute([$ts, $id]);
    }

    private function insertPendingChallenge(int $userId, string $suffix, \DateTimeImmutable $now): int
    {
        return $this->repo->insertPendingCurrent(
            $userId,
            'sms',
            hash('sha256', 'dest-' . $suffix),
            random_bytes(16),
            hash('sha256', 'otp-' . $suffix),
            $now->modify('+10 minutes'),
            10,
            $now,
        );
    }
}
