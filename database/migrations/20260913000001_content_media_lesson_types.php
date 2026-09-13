<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Production content-media columns and checks.
 *
 * Additive only. Existing text, PDF, quiz, and video (embed / external link)
 * rows stay valid. down() deletes only types this migration introduces
 * (rich_text, podcast, audio, live_session) and video rows whose delivery
 * mode is upload. It does not delete embedded or external-link video rows.
 * Refuse a production down once upload or new-type rows must be kept —
 * the delete below is the revert path, matching WP-L10.
 */
final class ContentMediaLessonTypes extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD COLUMN original_filename VARCHAR(255) NULL AFTER video_provider,
    ADD COLUMN media_mime VARCHAR(128) NULL AFTER original_filename,
    ADD COLUMN media_bytes BIGINT UNSIGNED NULL AFTER media_mime,
    ADD COLUMN media_sha256 CHAR(64) NULL AFTER media_bytes,
    ADD COLUMN podcast_url VARCHAR(2048) NULL AFTER media_sha256,
    ADD COLUMN live_join_url VARCHAR(2048) NULL AFTER podcast_url,
    ADD COLUMN live_starts_at DATETIME NULL AFTER live_join_url,
    ADD COLUMN live_ends_at DATETIME NULL AFTER live_starts_at,
    ADD COLUMN live_provider VARCHAR(32) NULL AFTER live_ends_at,
    ADD COLUMN live_recording_url VARCHAR(2048) NULL AFTER live_provider,
    ADD COLUMN live_external_meeting_id VARCHAR(128) NULL AFTER live_recording_url
SQL);

        $this->dropCheck('chk_content_items_type');
        $this->dropCheck('chk_content_items_video_delivery_mode');
        $this->dropCheck('chk_content_items_video_fields');

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_type
        CHECK (content_type IN (
            'text_lesson', 'rich_text', 'pdf', 'mcq_assessment', 'video',
            'podcast', 'audio', 'live_session'
        ))
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_video_delivery_mode
        CHECK (video_delivery_mode IS NULL OR video_delivery_mode IN ('embedded', 'external_link', 'upload'))
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_video_fields
        CHECK (
            (
                content_type = 'video'
                AND (
                    (
                        video_delivery_mode IN ('embedded', 'external_link')
                        AND video_url IS NOT NULL
                        AND video_provider IS NOT NULL
                        AND object_key IS NULL
                    )
                    OR (
                        video_delivery_mode = 'upload'
                        AND object_key IS NOT NULL
                        AND video_url IS NULL
                        AND video_provider IS NULL
                        AND media_mime IS NOT NULL
                    )
                )
            )
            OR (
                content_type <> 'video'
                AND video_url IS NULL
                AND video_delivery_mode IS NULL
                AND video_provider IS NULL
            )
        )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_pdf
        CHECK (content_type <> 'pdf' OR object_key IS NOT NULL)
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_podcast
        CHECK (
            (content_type = 'podcast' AND podcast_url IS NOT NULL)
            OR (content_type <> 'podcast' AND podcast_url IS NULL)
        )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_audio
        CHECK (
            content_type <> 'audio'
            OR (
                object_key IS NOT NULL
                AND media_mime IS NOT NULL
                AND podcast_url IS NULL
                AND video_url IS NULL
            )
        )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_live_provider
        CHECK (live_provider IS NULL OR live_provider IN ('google_meet', 'zoom', 'teams', 'custom'))
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_live_session
        CHECK (
            (
                content_type = 'live_session'
                AND live_join_url IS NOT NULL
                AND live_starts_at IS NOT NULL
                AND live_provider IS NOT NULL
            )
            OR (
                content_type <> 'live_session'
                AND live_join_url IS NULL
                AND live_starts_at IS NULL
                AND live_ends_at IS NULL
                AND live_provider IS NULL
                AND live_recording_url IS NULL
                AND live_external_meeting_id IS NULL
            )
        )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    ADD CONSTRAINT chk_content_items_media_meta
        CHECK (
            content_type IN ('pdf', 'audio')
            OR (content_type = 'video' AND video_delivery_mode = 'upload')
            OR (
                original_filename IS NULL
                AND media_mime IS NULL
                AND media_bytes IS NULL
                AND media_sha256 IS NULL
            )
        )
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
DELETE FROM content_items
 WHERE content_type IN ('rich_text', 'podcast', 'audio', 'live_session')
    OR (content_type = 'video' AND video_delivery_mode = 'upload')
SQL);

        $this->dropCheck('chk_content_items_media_meta');
        $this->dropCheck('chk_content_items_live_session');
        $this->dropCheck('chk_content_items_live_provider');
        $this->dropCheck('chk_content_items_audio');
        $this->dropCheck('chk_content_items_podcast');
        $this->dropCheck('chk_content_items_pdf');
        $this->dropCheck('chk_content_items_video_fields');
        $this->dropCheck('chk_content_items_video_delivery_mode');
        $this->dropCheck('chk_content_items_type');

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
    ADD CONSTRAINT chk_content_items_video_fields
        CHECK (
            (content_type = 'video' AND video_url IS NOT NULL AND video_delivery_mode IS NOT NULL AND video_provider IS NOT NULL)
            OR (content_type <> 'video' AND video_url IS NULL AND video_delivery_mode IS NULL AND video_provider IS NULL)
        )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE content_items
    DROP COLUMN live_external_meeting_id,
    DROP COLUMN live_recording_url,
    DROP COLUMN live_provider,
    DROP COLUMN live_ends_at,
    DROP COLUMN live_starts_at,
    DROP COLUMN live_join_url,
    DROP COLUMN podcast_url,
    DROP COLUMN media_sha256,
    DROP COLUMN media_bytes,
    DROP COLUMN media_mime,
    DROP COLUMN original_filename
SQL);
    }

    private function dropCheck(string $name): void
    {
        $this->execute('ALTER TABLE content_items DROP CHECK ' . $name);
    }
}
