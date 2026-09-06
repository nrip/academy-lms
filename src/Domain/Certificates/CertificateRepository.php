<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

use DateTimeImmutable;

interface CertificateRepository
{
    public function findById(int $certificateId): ?Certificate;

    public function findByNumber(string $certificateNumber): ?Certificate;

    public function findCurrentByEnrolmentAndType(int $enrolmentId, string $certificateType): ?Certificate;

    /**
     * @return list<Certificate>
     */
    public function listByEnrolmentId(int $enrolmentId): array;

    /**
     * @param array{
     *   enrolment_id: int,
     *   course_version_id: int,
     *   certificate_type: string,
     *   certificate_number: string,
     *   verification_hash: string,
     *   learner_name_snapshot: string,
     *   course_title_snapshot: string,
     *   version_title_snapshot: string,
     *   certificate_label: string,
     *   status: string,
     *   issued_at: DateTimeImmutable
     * } $data
     */
    public function insertCurrent(array $data): int;
}
