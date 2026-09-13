<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L10 — External/embedded video ContentItems (no hosting/transcoding).
 */
final class WpL10VideoContentItems extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD COLUMN video_url VARCHAR(2048) NULL AFTER object_key,
    ADD COLUMN video_delivery_mode VARCHAR(32) NULL AFTER video_url,
    ADD COLUMN video_provider VARCHAR(32) NULL AFTER video_delivery_mode
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP CHECK chk_content_items_type
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_type
        CHECK (content_type IN ('text_lesson', 'pdf', 'mcq_assessment', 'video'))
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_video_delivery_mode
        CHECK (video_delivery_mode IS NULL OR video_delivery_mode IN ('embedded', 'external_link'))
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_video_provider
        CHECK (video_provider IS NULL OR video_provider IN ('youtube', 'youtube_nocookie', 'vimeo', 'external'))
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_video_fields
        CHECK (
            (content_type = 'video' AND video_url IS NOT NULL AND video_delivery_mode IS NOT NULL AND video_provider IS NOT NULL)
            OR (content_type <> 'video' AND video_url IS NULL AND video_delivery_mode IS NULL AND video_provider IS NULL)
        )
SQL);
    }

    public function down(): void
    {
        // The content-item immutability trigger rejects DELETE while the parent
        // CourseVersion is locked. Unlock only versions that still own a video
        // lesson so this rollback can remove the rows it introduced.
        $this->execute(<<<'SQL'
UPDATE course_versions cv
INNER JOIN modules m ON m.course_version_id = cv.version_id
INNER JOIN content_items ci ON ci.module_id = m.module_id
SET cv.locked_at = NULL
WHERE ci.content_type = 'video'
SQL);
        $this->execute(<<<'SQL'
DELETE cp FROM content_progress cp
INNER JOIN content_items ci ON ci.content_id = cp.content_id
WHERE ci.content_type = 'video'
SQL);
        $this->execute(<<<'SQL'
DELETE FROM content_items WHERE content_type = 'video'
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP CHECK chk_content_items_video_fields
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP CHECK chk_content_items_video_provider
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP CHECK chk_content_items_video_delivery_mode
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP CHECK chk_content_items_type
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_type
        CHECK (content_type IN ('text_lesson', 'pdf', 'mcq_assessment'))
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP COLUMN video_provider,
    DROP COLUMN video_delivery_mode,
    DROP COLUMN video_url
SQL);
    }
}
