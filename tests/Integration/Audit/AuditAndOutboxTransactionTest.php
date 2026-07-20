<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Audit;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\SecurityAuditPayload;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class AuditAndOutboxTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
    }

    public function testAuditTriggersRejectUpdateAndDelete(): void
    {
        $writer = new PdoAuditWriter(DatabaseTestCase::connectionFactory());
        $service = new AuditService($writer, new AuditRedactor());
        $service->record(
            new SecurityAuditPayload('security.test', 'system', '1', next: ['status' => 'ok']),
            'system',
            null,
            'test',
        );

        $pdo = DatabaseTestCase::pdo();
        $id = (int) $pdo->query('SELECT audit_id FROM audit_log LIMIT 1')->fetchColumn();

        try {
            $pdo->exec('UPDATE audit_log SET reason = \'x\' WHERE audit_id = ' . $id);
            self::fail('Expected update trigger to fire');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            $pdo->exec('DELETE FROM audit_log WHERE audit_id = ' . $id);
            self::fail('Expected delete trigger to fire');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function testDomainAuditOutboxCommitAtomically(): void
    {
        $factory = DatabaseTestCase::connectionFactory();
        $transactions = new TransactionManager($factory);
        $audit = new AuditService(new PdoAuditWriter($factory), new AuditRedactor());
        /** @var OutboxWriter $outbox */
        $outbox = new PdoOutboxRepository($factory);

        $transactions->run(static function () use ($audit, $outbox): void {
            $audit->record(
                new SecurityAuditPayload('security.harness', 'harness', '9', next: ['status' => 'ok']),
                'system',
                null,
                'test',
            );
            $outbox->enqueue('harness.event', 'harness', '9', ['status' => 'ok'], 'harness-9-v1');
        });

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testDomainAuditOutboxRollbackTogether(): void
    {
        $factory = DatabaseTestCase::connectionFactory();
        $transactions = new TransactionManager($factory);
        $audit = new AuditService(new PdoAuditWriter($factory), new AuditRedactor());
        $outbox = new PdoOutboxRepository($factory);

        try {
            $transactions->run(static function () use ($audit, $outbox): void {
                $audit->record(
                    new SecurityAuditPayload('security.harness', 'harness', '10', next: ['status' => 'ok']),
                    'system',
                    null,
                    'test',
                );
                $outbox->enqueue('harness.event', 'harness', '10', ['status' => 'ok'], 'harness-10-v1');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }
}
