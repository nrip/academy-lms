<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Course cover images live on the course identity, not the edition.
 * A published CourseVersion stays immutable when the cover is replaced.
 */
final class CourseCoverImages extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE courses
    ADD COLUMN cover_object_key VARCHAR(255) NULL AFTER current_published_version_id,
    ADD COLUMN cover_filename VARCHAR(255) NULL AFTER cover_object_key,
    ADD COLUMN cover_mime VARCHAR(128) NULL AFTER cover_filename,
    ADD COLUMN cover_bytes BIGINT UNSIGNED NULL AFTER cover_mime
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE courses
    DROP COLUMN cover_bytes,
    DROP COLUMN cover_mime,
    DROP COLUMN cover_filename,
    DROP COLUMN cover_object_key
SQL);
    }
}
