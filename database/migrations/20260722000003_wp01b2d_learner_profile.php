<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-01B-2d — Learner profile personal/professional columns + qualifications table.
 */
final class Wp01b2dLearnerProfile extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE learner_profiles
    ADD COLUMN first_name VARCHAR(100) NULL AFTER user_id,
    ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name,
    ADD COLUMN preferred_display_name VARCHAR(200) NULL AFTER last_name,
    ADD COLUMN certificate_name VARCHAR(200) NULL AFTER preferred_display_name,
    ADD COLUMN certificate_name_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER certificate_name,
    ADD COLUMN date_of_birth DATE NULL AFTER certificate_name_confirmed,
    ADD COLUMN gender VARCHAR(32) NULL AFTER date_of_birth,
    ADD COLUMN nationality VARCHAR(100) NULL AFTER gender,
    ADD COLUMN address_line_1 VARCHAR(255) NULL AFTER nationality,
    ADD COLUMN address_line_2 VARCHAR(255) NULL AFTER address_line_1,
    ADD COLUMN city VARCHAR(100) NULL AFTER address_line_2,
    ADD COLUMN state VARCHAR(100) NULL AFTER city,
    ADD COLUMN postal_code VARCHAR(32) NULL AFTER state,
    ADD COLUMN country VARCHAR(100) NULL AFTER postal_code,
    ADD COLUMN alternate_mobile VARCHAR(20) NULL AFTER country,
    ADD COLUMN profession VARCHAR(120) NULL AFTER alternate_mobile,
    ADD COLUMN speciality VARCHAR(120) NULL AFTER profession,
    ADD COLUMN current_designation VARCHAR(120) NULL AFTER speciality,
    ADD COLUMN organization_name VARCHAR(200) NULL AFTER current_designation,
    ADD COLUMN years_of_experience SMALLINT UNSIGNED NULL AFTER organization_name,
    ADD COLUMN medical_council_name VARCHAR(200) NULL AFTER years_of_experience,
    ADD COLUMN medical_council_registration_number VARCHAR(100) NULL AFTER medical_council_name,
    ADD COLUMN medical_council_registration_state VARCHAR(100) NULL AFTER medical_council_registration_number,
    ADD COLUMN registration_valid_from DATE NULL AFTER medical_council_registration_state,
    ADD COLUMN registration_valid_until DATE NULL AFTER registration_valid_from
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE learner_qualifications (
    learner_qualification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    learner_profile_id BIGINT UNSIGNED NOT NULL,
    qualification_type VARCHAR(80) NOT NULL,
    qualification_name VARCHAR(200) NOT NULL,
    institution_name VARCHAR(200) NOT NULL,
    university_or_board VARCHAR(200) NULL,
    country VARCHAR(100) NULL,
    completion_year SMALLINT UNSIGNED NOT NULL,
    registration_or_certificate_number VARCHAR(100) NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 1,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (learner_qualification_id),
    KEY idx_learner_qualifications_profile (learner_profile_id),
    KEY idx_learner_qualifications_profile_order (learner_profile_id, display_order),
    CONSTRAINT fk_learner_qualifications_profile
        FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles (learner_profile_id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS learner_qualifications');

        $this->execute(<<<'SQL'
ALTER TABLE learner_profiles
    DROP COLUMN registration_valid_until,
    DROP COLUMN registration_valid_from,
    DROP COLUMN medical_council_registration_state,
    DROP COLUMN medical_council_registration_number,
    DROP COLUMN medical_council_name,
    DROP COLUMN years_of_experience,
    DROP COLUMN organization_name,
    DROP COLUMN current_designation,
    DROP COLUMN speciality,
    DROP COLUMN profession,
    DROP COLUMN alternate_mobile,
    DROP COLUMN country,
    DROP COLUMN postal_code,
    DROP COLUMN state,
    DROP COLUMN city,
    DROP COLUMN address_line_2,
    DROP COLUMN address_line_1,
    DROP COLUMN nationality,
    DROP COLUMN gender,
    DROP COLUMN date_of_birth,
    DROP COLUMN certificate_name_confirmed,
    DROP COLUMN certificate_name,
    DROP COLUMN preferred_display_name,
    DROP COLUMN last_name,
    DROP COLUMN middle_name,
    DROP COLUMN first_name
SQL);
    }
}
