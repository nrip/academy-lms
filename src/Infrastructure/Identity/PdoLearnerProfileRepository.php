<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearnerProfileRepository implements LearnerProfileRepository
{
    private const COLUMNS = 'learner_profile_id, user_id, first_name, middle_name, last_name,
        preferred_display_name, certificate_name, certificate_name_confirmed, date_of_birth, gender,
        nationality, address_line_1, address_line_2, city, state, postal_code, country, alternate_mobile,
        profession, speciality, current_designation, organization_name, years_of_experience,
        medical_council_name, medical_council_registration_number, medical_council_registration_state,
        registration_valid_from, registration_valid_until, row_version, created_at, updated_at';

    /** @var list<string> */
    private const PERSONAL_COLUMNS = [
        'first_name',
        'middle_name',
        'last_name',
        'preferred_display_name',
        'certificate_name',
        'certificate_name_confirmed',
        'date_of_birth',
        'gender',
        'nationality',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'alternate_mobile',
    ];

    /** @var list<string> */
    private const PROFESSIONAL_COLUMNS = [
        'profession',
        'speciality',
        'current_designation',
        'organization_name',
        'years_of_experience',
        'medical_council_name',
        'medical_council_registration_number',
        'medical_council_registration_state',
        'registration_valid_from',
        'registration_valid_until',
    ];

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insertStub(int $userId, DateTimeImmutable $now): int
    {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO learner_profiles (user_id, row_version, created_at, updated_at)
             VALUES (:user_id, 1, :created_at, :updated_at)',
        );
        $stmt->execute([
            'user_id' => $userId,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findByUserId(int $userId): ?LearnerProfile
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM learner_profiles WHERE user_id = :user_id',
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findById(int $profileId): ?LearnerProfile
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM learner_profiles WHERE learner_profile_id = :id',
        );
        $stmt->execute(['id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function updatePersonal(int $profileId, int $expectedVersion, array $fields, DateTimeImmutable $now): int
    {
        return $this->updateSection($profileId, $expectedVersion, $fields, self::PERSONAL_COLUMNS, $now);
    }

    public function updateProfessional(int $profileId, int $expectedVersion, array $fields, DateTimeImmutable $now): int
    {
        return $this->updateSection($profileId, $expectedVersion, $fields, self::PROFESSIONAL_COLUMNS, $now);
    }

    /**
     * @param array<string, scalar|null> $fields
     * @param list<string> $allowedColumns
     */
    private function updateSection(
        int $profileId,
        int $expectedVersion,
        array $fields,
        array $allowedColumns,
        DateTimeImmutable $now,
    ): int {
        $pdo = $this->connections->connection();
        $assignments = [];
        $params = [
            'id' => $profileId,
            'version' => $expectedVersion,
            'updated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }
            $assignments[] = $column . ' = :' . $column;
            $value = $fields[$column];
            if ($column === 'certificate_name_confirmed') {
                $params[$column] = $value ? 1 : 0;
            } else {
                $params[$column] = $value;
            }
        }

        $setClause = $assignments === [] ? '' : implode(', ', $assignments) . ', ';

        $stmt = $pdo->prepare(
            'UPDATE learner_profiles
             SET ' . $setClause . 'row_version = row_version + 1, updated_at = :updated_at
             WHERE learner_profile_id = :id AND row_version = :version',
        );
        $stmt->execute($params);

        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('This profile was updated elsewhere. Refresh and try again.');
        }

        return $expectedVersion + 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): LearnerProfile
    {
        $utc = new DateTimeZone('UTC');

        return new LearnerProfile(
            learnerProfileId: (int) $row['learner_profile_id'],
            userId: (int) $row['user_id'],
            firstName: $this->nullableString($row['first_name']),
            middleName: $this->nullableString($row['middle_name']),
            lastName: $this->nullableString($row['last_name']),
            preferredDisplayName: $this->nullableString($row['preferred_display_name']),
            certificateName: $this->nullableString($row['certificate_name']),
            certificateNameConfirmed: (int) $row['certificate_name_confirmed'] === 1,
            dateOfBirth: $this->nullableString($row['date_of_birth']),
            gender: $this->nullableString($row['gender']),
            nationality: $this->nullableString($row['nationality']),
            addressLine1: $this->nullableString($row['address_line_1']),
            addressLine2: $this->nullableString($row['address_line_2']),
            city: $this->nullableString($row['city']),
            state: $this->nullableString($row['state']),
            postalCode: $this->nullableString($row['postal_code']),
            country: $this->nullableString($row['country']),
            alternateMobile: $this->nullableString($row['alternate_mobile']),
            profession: $this->nullableString($row['profession']),
            speciality: $this->nullableString($row['speciality']),
            currentDesignation: $this->nullableString($row['current_designation']),
            organizationName: $this->nullableString($row['organization_name']),
            yearsOfExperience: $row['years_of_experience'] === null ? null : (int) $row['years_of_experience'],
            medicalCouncilName: $this->nullableString($row['medical_council_name']),
            medicalCouncilRegistrationNumber: $this->nullableString($row['medical_council_registration_number']),
            medicalCouncilRegistrationState: $this->nullableString($row['medical_council_registration_state']),
            registrationValidFrom: $this->nullableString($row['registration_valid_from']),
            registrationValidUntil: $this->nullableString($row['registration_valid_until']),
            rowVersion: (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
