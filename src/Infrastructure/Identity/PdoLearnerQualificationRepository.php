<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\LearnerQualification;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearnerQualificationRepository implements LearnerQualificationRepository
{
    private const COLUMNS = 'learner_qualification_id, learner_profile_id, qualification_type,
        qualification_name, institution_name, university_or_board, country, completion_year,
        registration_or_certificate_number, display_order, row_version, created_at, updated_at';

    /** @var list<string> */
    private const MUTABLE_COLUMNS = [
        'qualification_type',
        'qualification_name',
        'institution_name',
        'university_or_board',
        'country',
        'completion_year',
        'registration_or_certificate_number',
    ];

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByProfileId(int $profileId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM learner_qualifications
             WHERE learner_profile_id = :profile_id
             ORDER BY display_order ASC, learner_qualification_id ASC',
        );
        $stmt->execute(['profile_id' => $profileId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $qualifications = [];
        foreach ($rows as $row) {
            $qualifications[] = $this->mapRow($row);
        }

        return $qualifications;
    }

    public function findById(int $qualificationId): ?LearnerQualification
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM learner_qualifications
             WHERE learner_qualification_id = :id',
        );
        $stmt->execute(['id' => $qualificationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function insert(int $profileId, array $fields, int $displayOrder, DateTimeImmutable $now): int
    {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO learner_qualifications (
                learner_profile_id, qualification_type, qualification_name, institution_name,
                university_or_board, country, completion_year, registration_or_certificate_number,
                display_order, row_version, created_at, updated_at
            ) VALUES (
                :learner_profile_id, :qualification_type, :qualification_name, :institution_name,
                :university_or_board, :country, :completion_year, :registration_or_certificate_number,
                :display_order, 1, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'learner_profile_id' => $profileId,
            'qualification_type' => $fields['qualification_type'] ?? null,
            'qualification_name' => $fields['qualification_name'] ?? null,
            'institution_name' => $fields['institution_name'] ?? null,
            'university_or_board' => $fields['university_or_board'] ?? null,
            'country' => $fields['country'] ?? null,
            'completion_year' => $fields['completion_year'] ?? null,
            'registration_or_certificate_number' => $fields['registration_or_certificate_number'] ?? null,
            'display_order' => $displayOrder,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateWithVersion(int $qualificationId, int $expectedVersion, array $fields, DateTimeImmutable $now): int
    {
        $pdo = $this->connections->connection();
        $assignments = [];
        $params = [
            'id' => $qualificationId,
            'version' => $expectedVersion,
            'updated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ];

        foreach (self::MUTABLE_COLUMNS as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }
            $assignments[] = $column . ' = :' . $column;
            $params[$column] = $fields[$column];
        }

        $setClause = $assignments === [] ? '' : implode(', ', $assignments) . ', ';

        $stmt = $pdo->prepare(
            'UPDATE learner_qualifications
             SET ' . $setClause . 'row_version = row_version + 1, updated_at = :updated_at
             WHERE learner_qualification_id = :id AND row_version = :version',
        );
        $stmt->execute($params);

        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('This qualification was updated elsewhere. Refresh and try again.');
        }

        return $expectedVersion + 1;
    }

    public function deleteWithVersion(int $qualificationId, int $expectedVersion): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM learner_qualifications
             WHERE learner_qualification_id = :id AND row_version = :version',
        );
        $stmt->execute([
            'id' => $qualificationId,
            'version' => $expectedVersion,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('This qualification was updated elsewhere. Refresh and try again.');
        }
    }

    public function nextDisplayOrder(int $profileId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(display_order), 0) + 1
             FROM learner_qualifications
             WHERE learner_profile_id = :profile_id',
        );
        $stmt->execute(['profile_id' => $profileId]);

        return (int) $stmt->fetchColumn();
    }

    public function countByProfileId(int $profileId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM learner_qualifications WHERE learner_profile_id = :profile_id',
        );
        $stmt->execute(['profile_id' => $profileId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): LearnerQualification
    {
        $utc = new DateTimeZone('UTC');

        return new LearnerQualification(
            learnerQualificationId: (int) $row['learner_qualification_id'],
            learnerProfileId: (int) $row['learner_profile_id'],
            qualificationType: (string) $row['qualification_type'],
            qualificationName: (string) $row['qualification_name'],
            institutionName: (string) $row['institution_name'],
            universityOrBoard: $row['university_or_board'] === null ? null : (string) $row['university_or_board'],
            country: $row['country'] === null ? null : (string) $row['country'],
            completionYear: (int) $row['completion_year'],
            registrationOrCertificateNumber: $row['registration_or_certificate_number'] === null
                ? null
                : (string) $row['registration_or_certificate_number'],
            displayOrder: (int) $row['display_order'],
            rowVersion: (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
