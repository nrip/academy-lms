<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Certificates;

use Academy\Domain\Certificates\Certificate;
use Academy\Domain\Certificates\CertificateRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoCertificateRepository implements CertificateRepository
{
    private const COLUMNS = 'certificate_id, enrolment_id, course_version_id, certificate_type,
        certificate_number, verification_hash, learner_name_snapshot, course_title_snapshot,
        version_title_snapshot, certificate_label, status, current_marker, issued_at,
        created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $certificateId): ?Certificate
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM certificates WHERE certificate_id = :id');
        $stmt->execute(['id' => $certificateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByNumber(string $certificateNumber): ?Certificate
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM certificates WHERE certificate_number = :number');
        $stmt->execute(['number' => $certificateNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findCurrentByEnrolmentAndType(int $enrolmentId, string $certificateType): ?Certificate
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM certificates
             WHERE enrolment_id = :enrolment_id AND certificate_type = :certificate_type
               AND current_marker = 1 LIMIT 1',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'certificate_type' => $certificateType,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByEnrolmentId(int $enrolmentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM certificates
             WHERE enrolment_id = :enrolment_id ORDER BY issued_at DESC',
        );
        $stmt->execute(['enrolment_id' => $enrolmentId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function insertCurrent(array $data): int
    {
        $pdo = $this->connections->connection();
        $utc = new DateTimeZone('UTC');
        $stamp = $data['issued_at']->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO certificates (
                enrolment_id, course_version_id, certificate_type, certificate_number,
                verification_hash, learner_name_snapshot, course_title_snapshot,
                version_title_snapshot, certificate_label, status, current_marker,
                issued_at, created_at, updated_at
             ) VALUES (
                :enrolment_id, :course_version_id, :certificate_type, :certificate_number,
                :verification_hash, :learner_name_snapshot, :course_title_snapshot,
                :version_title_snapshot, :certificate_label, :status, 1,
                :issued_at, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'enrolment_id' => $data['enrolment_id'],
            'course_version_id' => $data['course_version_id'],
            'certificate_type' => $data['certificate_type'],
            'certificate_number' => $data['certificate_number'],
            'verification_hash' => $data['verification_hash'],
            'learner_name_snapshot' => $data['learner_name_snapshot'],
            'course_title_snapshot' => $data['course_title_snapshot'],
            'version_title_snapshot' => $data['version_title_snapshot'],
            'certificate_label' => $data['certificate_label'],
            'status' => $data['status'],
            'issued_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Certificate
    {
        $utc = new DateTimeZone('UTC');

        return new Certificate(
            certificateId: (int) $row['certificate_id'],
            enrolmentId: (int) $row['enrolment_id'],
            courseVersionId: (int) $row['course_version_id'],
            certificateType: (string) $row['certificate_type'],
            certificateNumber: (string) $row['certificate_number'],
            verificationHash: (string) $row['verification_hash'],
            learnerNameSnapshot: (string) $row['learner_name_snapshot'],
            courseTitleSnapshot: (string) $row['course_title_snapshot'],
            versionTitleSnapshot: (string) $row['version_title_snapshot'],
            certificateLabel: (string) $row['certificate_label'],
            status: (string) $row['status'],
            currentMarker: $row['current_marker'] === null ? null : (int) $row['current_marker'],
            issuedAt: new DateTimeImmutable((string) $row['issued_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
