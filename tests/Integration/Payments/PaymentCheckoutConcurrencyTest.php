<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Payments;

use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;

final class PaymentCheckoutConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testConcurrentInitiatesYieldExactlyOnePending(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $worker = dirname(__DIR__, 2) . '/Support/payment_initiate_worker.php';

        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['applicant_user_id'],
                (string) $fixture['applicant_auth_version'],
                (string) $fixture['application_id'],
            ],
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['applicant_user_id'],
                (string) $fixture['applicant_auth_version'],
                (string) $fixture['application_id'],
            ],
        ]);

        $pending = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'pending:')));
        $conflicts = array_values(array_filter(
            $results,
            static fn (string $r): bool => $r === 'conflict' || str_starts_with($r, 'conflict:'),
        ));

        self::assertSame(2, count($pending) + count($conflicts), 'Expected pending/conflict covering both workers, got: ' . implode(',', $results));
        self::assertGreaterThanOrEqual(1, count($pending), 'Expected at least one pending winner, got: ' . implode(',', $results));

        $pendingIds = array_map(
            static fn (string $r): int => (int) substr($r, strlen('pending:')),
            $pending,
        );
        self::assertCount(1, array_unique($pendingIds), 'Exactly one payment id should win, got: ' . implode(',', $results));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM payments WHERE application_id = ? AND status = ?',
        );
        $stmt->execute([$fixture['application_id'], 'pending']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * @param list<list<string>> $commands
     * @return list<string>
     */
    private function runWorkers(array $commands): array
    {
        $env = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
            'APP_ENV' => 'testing',
            'TOKEN_PEPPER' => $_ENV['TOKEN_PEPPER'] ?? 'testing-token-pepper-not-for-production',
            'OTP_PEPPER' => $_ENV['OTP_PEPPER'] ?? 'testing-otp-pepper-not-for-production',
            'RATE_LIMIT_PEPPER' => $_ENV['RATE_LIMIT_PEPPER'] ?? 'phpunit-rate-limit-pepper',
            'NOTIFICATION_DELIVERY_KEY' => $_ENV['NOTIFICATION_DELIVERY_KEY']
                ?? 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'PAYMENTS_FAKE_GATEWAY' => '1',
        ];

        $processes = [];
        $pipesList = [];
        foreach ($commands as $command) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($command, $descriptors, $pipes, null, $env);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $processes[] = $proc;
            $pipesList[] = $pipes;
        }

        $results = [];
        foreach ($processes as $index => $proc) {
            $stdout = stream_get_contents($pipesList[$index][1]);
            $stderr = stream_get_contents($pipesList[$index][2]);
            fclose($pipesList[$index][1]);
            fclose($pipesList[$index][2]);
            $status = proc_close($proc);
            self::assertSame(0, $status, 'Worker failed: ' . $stderr . ' / ' . $stdout);
            $line = trim((string) $stdout);
            // Keep the last non-empty line in case PHP notices polluted stdout.
            $parts = preg_split('/\R/', $line) ?: [$line];
            $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
            $results[] = $parts === [] ? '' : $parts[array_key_last($parts)];
        }

        return $results;
    }
}
