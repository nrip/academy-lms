<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $idSuffix */
/** @var string $selectedKind */
/** @var ?\Academy\Domain\Courses\ContentItem $lesson */
/** @var bool $lockedKind */
/** @var array{pdf?: string, audio?: string, video?: string} $uploadLimits */

$lesson = $lesson ?? null;
$uploadLimits = $uploadLimits ?? [];
$body = $lesson?->bodyText ?? '';
$videoUrl = $lesson?->videoUrl ?? '';
$podcastUrl = $lesson?->delivery->podcastUrl ?? '';
$joinUrl = $lesson?->delivery->liveJoinUrl ?? '';
$recordingUrl = $lesson?->delivery->liveRecordingUrl ?? '';
$india = new \DateTimeZone('Asia/Kolkata');
$starts = $lesson?->delivery->liveStartsAt?->setTimezone($india)->format('Y-m-d\TH:i') ?? '';
$ends = $lesson?->delivery->liveEndsAt?->setTimezone($india)->format('Y-m-d\TH:i') ?? '';
$fileLabel = $lesson?->delivery->originalFilename;
?>
<div>
    <?php if ($lockedKind): ?>
        <input type="hidden" name="lesson_kind" value="<?= $e->attr($selectedKind) ?>" data-acad-lesson-kind>
    <?php endif; ?>

    <div class="col-12" data-acad-lesson-panel="text">
        <label class="form-label" for="body_<?= $e->attr($idSuffix) ?>"><?= $e->html('Lesson text') ?></label>
        <textarea class="form-control form-control-sm" id="body_<?= $e->attr($idSuffix) ?>" name="lesson_body_text" rows="5"
                  data-acad-required="1"><?= $e->html($body) ?></textarea>
    </div>

    <div class="col-12" data-acad-lesson-panel="rich_text">
        <label class="form-label" for="rich_<?= $e->attr($idSuffix) ?>"><?= $e->html('Lesson text') ?></label>
        <div class="d-flex flex-wrap gap-1 mb-2">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-insert="strong"><?= $e->html('Bold') ?></button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-insert="em"><?= $e->html('Italic') ?></button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-insert="h2"><?= $e->html('Heading') ?></button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-insert="ul"><?= $e->html('List') ?></button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-insert="a"><?= $e->html('Link') ?></button>
        </div>
        <textarea class="form-control form-control-sm" id="rich_<?= $e->attr($idSuffix) ?>" name="lesson_body_rich" rows="6"
                  data-acad-rich data-acad-required="1"><?= $e->html($body) ?></textarea>
        <div class="form-text"><?= $e->html('Bold, italic, headings, lists, and HTTPS links are kept. Other formatting is removed.') ?></div>
    </div>

    <div class="col-12" data-acad-lesson-panel="video_embed video_link">
        <label class="form-label" for="vurl_<?= $e->attr($idSuffix) ?>"><?= $e->html('Video URL') ?></label>
        <input class="form-control form-control-sm" id="vurl_<?= $e->attr($idSuffix) ?>" name="video_url"
               value="<?= $e->attr($videoUrl) ?>" placeholder="https://" data-acad-required="1">
        <div class="form-text" data-acad-lesson-panel="video_embed"><?= $e->html('YouTube or Vimeo. The video plays on the lesson page.') ?></div>
        <div class="form-text" data-acad-lesson-panel="video_link"><?= $e->html('Any HTTPS address. Learners open it in a new tab.') ?></div>
    </div>

    <div class="col-md-8" data-acad-lesson-panel="pdf video_upload audio_upload">
        <label class="form-label" for="file_<?= $e->attr($idSuffix) ?>"><?= $e->html('File') ?></label>
        <input class="form-control form-control-sm" id="file_<?= $e->attr($idSuffix) ?>" type="file" name="lesson_file"
               data-acad-required="<?= $lesson === null ? '1' : '0' ?>">
        <?php if ($fileLabel !== null && $fileLabel !== ''): ?>
            <div class="form-text"><?= $e->html('Current file: ' . $fileLabel . '. Leave empty to keep it.') ?></div>
        <?php elseif ($lesson !== null): ?>
            <div class="form-text"><?= $e->html('Leave empty to keep the current file.') ?></div>
        <?php else: ?>
            <div class="form-text"><?= $e->html('Use a file the browser can play already. PDF up to ' . ($uploadLimits['pdf'] ?? '') . ' MB, audio up to ' . ($uploadLimits['audio'] ?? '') . ' MB, or video up to ' . ($uploadLimits['video'] ?? '') . ' MB.') ?></div>
        <?php endif; ?>
    </div>

    <div class="col-12" data-acad-lesson-panel="podcast">
        <label class="form-label" for="pod_<?= $e->attr($idSuffix) ?>"><?= $e->html('Podcast URL') ?></label>
        <input class="form-control form-control-sm" id="pod_<?= $e->attr($idSuffix) ?>" name="podcast_url"
               value="<?= $e->attr($podcastUrl) ?>" placeholder="https://" data-acad-required="1">
        <div class="form-text"><?= $e->html('A direct MP3, M4A, or WAV link plays on the page. Other HTTPS links open as Listen.') ?></div>
    </div>

    <div class="col-12" data-acad-lesson-panel="live_session">
        <label class="form-label" for="join_<?= $e->attr($idSuffix) ?>"><?= $e->html('Join link') ?></label>
        <input class="form-control form-control-sm" id="join_<?= $e->attr($idSuffix) ?>" name="live_join_url"
               value="<?= $e->attr($joinUrl) ?>" placeholder="https://meet.google.com/…" data-acad-required="1">
        <div class="form-text"><?= $e->html('Google Meet, Zoom, Teams, or any other HTTPS join link.') ?></div>
    </div>
    <div class="col-md-4" data-acad-lesson-panel="live_session">
        <label class="form-label" for="start_<?= $e->attr($idSuffix) ?>"><?= $e->html('Starts (India time)') ?></label>
        <input class="form-control form-control-sm" id="start_<?= $e->attr($idSuffix) ?>" name="live_starts_at"
               type="datetime-local" value="<?= $e->attr($starts) ?>" data-acad-required="1">
    </div>
    <div class="col-md-4" data-acad-lesson-panel="live_session">
        <label class="form-label" for="end_<?= $e->attr($idSuffix) ?>"><?= $e->html('Ends (India time, optional)') ?></label>
        <input class="form-control form-control-sm" id="end_<?= $e->attr($idSuffix) ?>" name="live_ends_at"
               type="datetime-local" value="<?= $e->attr($ends) ?>">
    </div>
    <div class="col-12" data-acad-lesson-panel="live_session">
        <label class="form-label" for="rec_<?= $e->attr($idSuffix) ?>"><?= $e->html('Recording link (optional)') ?></label>
        <input class="form-control form-control-sm" id="rec_<?= $e->attr($idSuffix) ?>" name="live_recording_url"
               value="<?= $e->attr($recordingUrl) ?>" placeholder="https://">
    </div>

    <div class="col-12" data-acad-lesson-panel="quiz">
        <p class="small text-muted mb-0"><?= $e->html('Add the quiz, then open it to add questions. Learners complete it by passing.') ?></p>
    </div>
</div>
