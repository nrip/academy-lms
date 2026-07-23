<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Payments;

use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;

final class WebhookAdmissionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('PAYMENTS_FAKE_GATEWAY=1');
        $_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testDuplicateWebhookWorkersProduceOneEnrolment(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $worker = dirname(__DIR__, 2) . '/Support/webhook_capture_worker.php';

        $bootstrap = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/webhook_capture_prepare.php',
            (string) $fixture['applicant_user_id'],
            (string) $fixture['applicant_auth_version'],
            (string) $fixture['application_id'],
        ];
        $prepared = $this->runWorkers([$bootstrap]);
        self::assertCount(1, $prepared);
        self::assertTrue(str_starts_with($prepared[0], 'ready:'), $prepared[0]);
        $paymentId = (int) substr($prepared[0], strlen('ready:'));

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, (string) $paymentId, 'evt_dup_1'],
            [PHP_BINARY, $worker, (string) $paymentId, 'evt_dup_1'],
        ]);

        self::assertSame(2, count($results), implode(',', $results));
        foreach ($results as $result) {
            self::assertTrue(
                str_starts_with($result, 'ok:') || $result === 'duplicate_or_noop',
                'Unexpected: ' . implode(',', $results),
            );
        }

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM payments WHERE application_id = ? AND successful_marker = 1',
        );
        $stmt->execute([$fixture['application_id']]);
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
            'RAZORPAY_WEBHOOK_SECRET' => 'local-ci-razorpay-webhook-secret-not-for-production',
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
        foreach ($processes as $i => $proc) {
            $stdout = stream_get_contents($pipesList[$i][1]);
            $stderr = stream_get_contents($pipesList[$i][2]);
            fclose($pipesList[$i][1]);
            fclose($pipesList[$i][2]);
            $code = proc_close($proc);
            $line = trim((string) $stdout);
            if ($line === '' && $code !== 0) {
                $line = 'error:' . trim((string) $stderr);
            }
            $results[] = $line;
        }

        return $results;
    }
}
