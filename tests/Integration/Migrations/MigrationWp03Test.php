<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Application\Admissions\DraftApplicationService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationWp03Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testMigrateCreatesDocumentTables(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach (['document_submissions', 'document_upload_authorizations'] as $table) {
            self::assertTrue($this->tableExists($pdo, $table), $table . ' must exist.');
        }
    }

    public function testApplicationsHaveWp03Columns(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach ([
            'application_number',
            'state_version',
            'declaration_accepted_version',
            'declaration_accepted_at',
        ] as $column) {
            self::assertTrue(
                $this->columnExists($pdo, 'applications', $column),
                'applications.' . $column . ' must exist.',
            );
        }
    }

    public function testExistingDraftSurvivesAfterMigrate(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $user = DatabaseTestCase::applicantFixture();
        $auth = AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $container = ApplicationFactory::container('testing');
        /** @var DraftApplicationService $service */
        $service = $container->get(DraftApplicationService::class);
        $application = $service->createDraft($auth, $seeded['batch_id']);

        self::assertSame(ApplicationStatus::DRAFT, $application->status);
        self::assertNotSame('', $application->applicationNumber);
        self::assertSame(1, $application->stateVersion);
        self::assertNull($application->declarationAcceptedVersion);
        self::assertNull($application->declarationAcceptedAt);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT application_number, state_version, declaration_accepted_version, declaration_accepted_at
             FROM applications WHERE application_id = ?',
        );
        $stmt->execute([$application->applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);
        self::assertSame($application->applicationNumber, $row['application_number']);
        self::assertSame(1, (int) $row['state_version']);
        self::assertNull($row['declaration_accepted_version']);
        self::assertNull($row['declaration_accepted_at']);
    }

    public function testUniqueCurrentMarkerConstraintFailsForDuplicateCurrentRows(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements();
        $user = DatabaseTestCase::applicantFixture();
        $applicationId = $this->insertDraftApplication(
            $user['user_id'],
            $seeded['version_id'],
            $seeded['batch_id'],
        );
        $requirementId = $seeded['requirement_ids'][0];
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $this->insertCurrentSubmission($applicationId, $requirementId, $user['user_id'], $now, 'key-a.pdf', 1);

        $this->expectException(PDOException::class);
        $this->insertCurrentSubmission($applicationId, $requirementId, $user['user_id'], $now, 'key-b.pdf', 2);
    }

    public function testRollbackThenReapply(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'document_submissions'));
        self::assertTrue($this->columnExists($pdo, 'applications', 'application_number'));

        $this->runPhinxRollback('20260722000004');

        self::assertFalse($this->tableExists($pdo, 'document_submissions'));
        self::assertFalse($this->tableExists($pdo, 'document_upload_authorizations'));
        self::assertFalse($this->columnExists($pdo, 'applications', 'application_number'));
        self::assertTrue($this->tableExists($pdo, 'applications'), 'WP-02 applications table must remain.');

        DatabaseTestCase::migrate();
        self::assertTrue($this->tableExists($pdo, 'document_submissions'));
        self::assertTrue($this->columnExists($pdo, 'applications', 'application_number'));
    }

    private function insertDraftApplication(int $userId, int $versionId, int $batchId): int
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO applications (
                application_number, user_id, course_version_id, batch_id, status, state_version,
                submitted_at, declaration_accepted_version, declaration_accepted_at, created_at, updated_at
            ) VALUES (
                :application_number, :user_id, :course_version_id, :batch_id, :status, 1,
                NULL, NULL, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'application_number' => 'APP-TEST-' . bin2hex(random_bytes(6)),
            'user_id' => $userId,
            'course_version_id' => $versionId,
            'batch_id' => $batchId,
            'status' => ApplicationStatus::DRAFT,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertCurrentSubmission(
        int $applicationId,
        int $requirementId,
        int $userId,
        string $now,
        string $objectKey,
        int $suffix,
    ): void {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO document_submissions (
                application_id, requirement_id, object_key, display_filename, mime_type, size_bytes,
                checksum_sha256, status, scan_status, uploaded_by_user_id, submitted_at, superseded_at,
                current_marker, row_version, scan_attempt_count, created_at, updated_at
            ) VALUES (
                :application_id, :requirement_id, :object_key, :display_filename, :mime_type, :size_bytes,
                :checksum_sha256, :status, :scan_status, :uploaded_by_user_id, :submitted_at, NULL,
                1, 1, 0, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'application_id' => $applicationId,
            'requirement_id' => $requirementId,
            'object_key' => $objectKey,
            'display_filename' => 'certificate-' . $suffix . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => hash('sha256', 'payload-' . $suffix),
            'status' => 'uploaded',
            'scan_status' => 'pending',
            'uploaded_by_user_id' => $userId,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
            [PHP_BINARY, $root . '/vendor/bin/phinx', ...$args],
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
