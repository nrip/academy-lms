<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Infrastructure\Identity\PdoLearnerProfileRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationWp01b2dTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
    }

    public function testMigrateAddsProfileColumnsAndQualificationsTable(): void
    {
        DatabaseTestCase::migrate();
        $pdo = DatabaseTestCase::pdo();

        self::assertTrue($this->tableExists($pdo, 'learner_qualifications'));

        foreach ([
            'first_name',
            'certificate_name',
            'certificate_name_confirmed',
            'date_of_birth',
            'postal_code',
            'alternate_mobile',
            'years_of_experience',
            'registration_valid_from',
            'registration_valid_until',
        ] as $column) {
            self::assertTrue($this->columnExists($pdo, 'learner_profiles', $column), $column . ' must exist on learner_profiles.');
        }
    }

    public function testInsertStubStillWorksAfterMigration(): void
    {
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $user = DatabaseTestCase::applicantFixture();
        $repository = new PdoLearnerProfileRepository(DatabaseTestCase::connectionFactory());
        $profileId = $repository->insertStub($user['user_id'], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        self::assertGreaterThan(0, $profileId);
        $profile = $repository->findByUserId($user['user_id']);
        self::assertNotNull($profile);
        self::assertSame(1, $profile->rowVersion);
        self::assertNull($profile->firstName);
        self::assertFalse($profile->certificateNameConfirmed);
    }

    public function testRollbackThenReapply(): void
    {
        DatabaseTestCase::migrate();
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'learner_qualifications'));

        $this->runPhinxRollback('20260722000002');

        self::assertFalse($this->tableExists($pdo, 'learner_qualifications'));
        self::assertFalse($this->columnExists($pdo, 'learner_profiles', 'first_name'));
        // learner_profiles itself (created in an earlier migration) must remain.
        self::assertTrue($this->tableExists($pdo, 'learner_profiles'));

        $this->runPhinxMigrate();
        self::assertTrue($this->tableExists($pdo, 'learner_qualifications'));
        self::assertTrue($this->columnExists($pdo, 'learner_profiles', 'first_name'));
    }

    private function runPhinxMigrate(): void
    {
        $this->runPhinx(['migrate', '-e', 'testing']);
    }

    private function runPhinxRollback(string $targetVersion): void
    {
        $this->runPhinx(['rollback', '-e', 'testing', '-t', $targetVersion]);
    }

    /**
     * @param list<string> $args
     */
    private function runPhinx(array $args): void
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge([PHP_BINARY, $root . '/vendor/bin/phinx'], $args),
            $descriptors,
            $pipes,
            $root,
            $this->phinxEnv(),
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        self::assertSame(0, $status, trim($stdout . "\n" . $stderr));
    }

    /**
     * @return array<string, string>
     */
    private function phinxEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
