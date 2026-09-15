<?php

declare(strict_types=1);

use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\LessonKind;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Learning\LearnerPlayerItemDetailView $detail */
/** @var ?string $error */

$base = '/learning/enrolments/' . $detail->enrolment->enrolmentId;

ob_start();
?>
<div class="acad-learner-item">
    <p class="mb-2">
        <a href="<?= $e->attr($base) ?>"><?= $e->html('← Course outline') ?></a>
    </p>
    <p class="text-muted small mb-1"><?= $e->html($detail->courseTitle . ' · ' . $detail->module->title) ?></p>
    <h1 class="h3 mb-1"><?= $e->html($detail->item->title) ?></h1>
    <p class="mb-3"><span class="badge text-bg-light border"><?= $e->html(LessonKind::labelForItem($detail->item)) ?></span></p>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if ($detail->progress->isCompleted()): ?>
        <div class="alert alert-success"><?= $e->html('Completed') ?></div>
    <?php endif; ?>

    <div class="acad-lesson-stage">
    <?php
    $type = $detail->item->contentType;
    $delivery = $detail->item->delivery;
    $india = new \DateTimeZone('Asia/Kolkata');
    $formatWhen = static function (?\DateTimeImmutable $at) use ($india, $e): string {
        if ($at === null) {
            return '';
        }

        return $e->html($at->setTimezone($india)->format('j M Y, g:i a') . ' IST');
    };
    ?>
    <?php if ($type === ContentItemType::MCQ_ASSESSMENT): ?>
        <?php if ($detail->assessment === null): ?>
            <div class="alert alert-warning">
                <?= $e->html('Assessment configuration is not available for this item.') ?>
            </div>
        <?php else: ?>
            <p class="mb-3">
                <?= $e->html($detail->assessment->title) ?>
                · <?= $e->html((string) $detail->assessment->questionsPerAttempt . ' questions') ?>
                · <?= $e->html('Pass ' . $detail->assessment->passThresholdPercent . '%') ?>
                · <?= $e->html('Attempts used ' . (string) $detail->assessmentAttemptsUsed . ' / ' . (string) $detail->assessment->maxAttempts) ?>
            </p>
            <?php if ($detail->inProgressAttempt !== null): ?>
                <a class="btn btn-primary"
                   href="/learning/attempts/<?= $e->attr((string) $detail->inProgressAttempt->attemptId) ?>">
                    <?= $e->html('Continue attempt') ?>
                </a>
            <?php elseif ($detail->assessmentAttemptsUsed < $detail->assessment->maxAttempts): ?>
                <form method="post"
                      action="<?= $e->attr($base . '/assessments/' . (string) $detail->assessment->assessmentId . '/attempts') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <button class="btn btn-primary" type="submit"><?= $e->html('Start attempt') ?></button>
                </form>
            <?php else: ?>
                <p class="text-muted"><?= $e->html('No attempts remaining.') ?></p>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::RICH_TEXT): ?>
        <?php if ($detail->richTextHtml !== null && $detail->richTextHtml !== ''): ?>
            <div class="acad-lesson-body"><?= $detail->richTextHtml ?></div>
        <?php else: ?>
            <p class="text-muted mb-0"><?= $e->html('This lesson has no text yet.') ?></p>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::TEXT_LESSON): ?>
        <?php if ($detail->item->bodyText !== null && $detail->item->bodyText !== ''): ?>
            <div class="acad-lesson-body"><?= nl2br($e->html($detail->item->bodyText)) ?></div>
        <?php else: ?>
            <p class="text-muted mb-0"><?= $e->html('This lesson has no text yet.') ?></p>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::VIDEO): ?>
        <?php if ($detail->mediaPath !== null): ?>
            <video class="acad-lesson-stage__media" controls preload="metadata"
                   src="<?= $e->attr($detail->mediaPath) ?>">
                <?= $e->html('Your browser cannot play this video.') ?>
            </video>
        <?php elseif ($detail->videoEmbedUrl !== null): ?>
            <div class="ratio ratio-16x9">
                <iframe
                    src="<?= $e->attr($detail->videoEmbedUrl) ?>"
                    title="<?= $e->attr($detail->item->title) ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    referrerpolicy="strict-origin-when-cross-origin"
                    loading="lazy"></iframe>
            </div>
        <?php elseif ($detail->videoWatchUrl !== null): ?>
            <a class="btn btn-primary"
               href="<?= $e->attr($detail->videoWatchUrl) ?>"
               target="_blank"
               rel="noopener noreferrer">
                <?= $e->html('Watch Video') ?>
            </a>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <?= $e->html('This video lesson is not available to play right now.') ?>
            </div>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::AUDIO): ?>
        <?php if ($detail->mediaPath !== null): ?>
            <audio class="w-100" controls preload="metadata" src="<?= $e->attr($detail->mediaPath) ?>">
                <?= $e->html('Your browser cannot play this audio.') ?>
            </audio>
        <?php else: ?>
            <div class="alert alert-warning mb-0"><?= $e->html('This audio lesson is not available to play right now.') ?></div>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::PODCAST): ?>
        <?php if ($delivery->podcastUrl !== null && $detail->podcastDirect): ?>
            <audio class="w-100 mb-3" controls preload="none" src="<?= $e->attr($delivery->podcastUrl) ?>">
                <?= $e->html('Your browser cannot play this audio.') ?>
            </audio>
            <a href="<?= $e->attr($delivery->podcastUrl) ?>" target="_blank" rel="noopener noreferrer">
                <?= $e->html('Listen') ?>
            </a>
        <?php elseif ($delivery->podcastUrl !== null): ?>
            <a class="btn btn-primary" href="<?= $e->attr($delivery->podcastUrl) ?>" target="_blank" rel="noopener noreferrer">
                <?= $e->html('Listen') ?>
            </a>
        <?php else: ?>
            <div class="alert alert-warning mb-0"><?= $e->html('This podcast is not available right now.') ?></div>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::PDF): ?>
        <?php if ($detail->mediaPath !== null): ?>
            <?php $pdfFilePath = $detail->mediaPath . '/file'; ?>
            <div class="acad-pdf" data-acad-pdf-viewer data-acad-pdf-src="<?= $e->attr($pdfFilePath) ?>">
                <div class="acad-pdf__toolbar">
                    <span class="acad-pdf__name"><?= $e->html($delivery->originalFilename ?? 'Lesson PDF') ?></span>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-pdf-prev disabled><?= $e->html('Previous') ?></button>
                    <span class="acad-pdf__status" data-acad-pdf-status><?= $e->html('Loading PDF…') ?></span>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-acad-pdf-next disabled><?= $e->html('Next') ?></button>
                    <a class="btn btn-outline-primary btn-sm" href="<?= $e->attr($detail->mediaPath) ?>" target="_blank" rel="noopener noreferrer">
                        <?= $e->html('Download') ?>
                    </a>
                </div>
                <div class="acad-pdf__stage">
                    <canvas data-acad-pdf-canvas></canvas>
                </div>
                <p class="acad-pdf__error" data-acad-pdf-error hidden><?= $e->html('This PDF could not be opened in the lesson. Use Download.') ?></p>
            </div>
            <script type="module" src="/assets/js/acad/pdf-viewer.js"></script>
        <?php else: ?>
            <div class="alert alert-warning mb-0"><?= $e->html('This PDF is not available right now.') ?></div>
        <?php endif; ?>
    <?php elseif ($type === ContentItemType::LIVE_SESSION): ?>
        <div class="acad-live-card">
            <p class="acad-eyebrow mb-2"><?= $e->html($detail->liveProviderLabel ?? 'Live class') ?></p>
            <?php if ($delivery->liveStartsAt !== null): ?>
                <p class="mb-1"><strong><?= $e->html('Starts') ?></strong> <?= $formatWhen($delivery->liveStartsAt) ?></p>
            <?php endif; ?>
            <?php if ($delivery->liveEndsAt !== null): ?>
                <p class="mb-3"><strong><?= $e->html('Ends') ?></strong> <?= $formatWhen($delivery->liveEndsAt) ?></p>
            <?php endif; ?>
            <?php if ($delivery->liveJoinUrl !== null): ?>
                <a class="btn btn-primary" href="<?= $e->attr($delivery->liveJoinUrl) ?>" target="_blank" rel="noopener noreferrer">
                    <?= $e->html('Join') ?>
                </a>
            <?php endif; ?>
            <?php if ($delivery->liveRecordingUrl !== null): ?>
                <a class="btn btn-outline-secondary ms-2" href="<?= $e->attr($delivery->liveRecordingUrl) ?>" target="_blank" rel="noopener noreferrer">
                    <?= $e->html('Recording') ?>
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="text-muted mb-0"><?= $e->html('This lesson is not available right now.') ?></p>
    <?php endif; ?>
    </div>

    <?php if ($detail->canMarkComplete): ?>
        <form method="post" action="<?= $e->attr($base . '/items/' . (string) $detail->item->contentId . '/complete') ?>" class="mb-4">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button type="submit" class="btn btn-primary">
                <?= $e->html($type === ContentItemType::LIVE_SESSION ? 'I attended' : 'Mark complete') ?>
            </button>
        </form>
    <?php elseif ($detail->markCompleteBlockedReason !== null && !$detail->progress->isCompleted()): ?>
        <p class="text-muted"><?= $e->html($detail->markCompleteBlockedReason) ?></p>
    <?php endif; ?>

    <?php
    /** @var list<\Academy\Application\Learning\LearningQuestionThreadItemView> $questions */
    $questions = $questions ?? [];
    /** @var bool $canAsk */
    $canAsk = $canAsk ?? false;
    /** @var ?string $flash */
    $flash = $flash ?? null;
    $indiaQa = new \DateTimeZone('Asia/Kolkata');
    ?>
    <?php if ($canAsk || $questions !== []): ?>
        <section class="acad-panel mt-4" aria-labelledby="qa-heading">
            <h2 id="qa-heading" class="h5"><?= $e->html('Questions') ?></h2>
            <?php if ($flash !== null): ?>
                <div class="alert alert-success"><?= $e->html($flash) ?></div>
            <?php endif; ?>
            <?php if ($questions === []): ?>
                <p class="text-muted"><?= $e->html('You have not asked a question on this lesson yet.') ?></p>
            <?php else: ?>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($questions as $thread): ?>
                        <li class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <span class="badge text-bg-light border"><?= $e->html($thread->statusLabel) ?></span>
                                <span class="small text-muted">
                                    <?= $e->html($thread->askedAt->setTimezone($indiaQa)->format('j M Y, g:i a') . ' IST') ?>
                                </span>
                            </div>
                            <p class="mb-3"><?= nl2br($e->html($thread->body), false) ?></p>
                            <?php foreach ($thread->responses as $response): ?>
                                <div class="border-start border-3 ps-3 mb-3">
                                    <div class="small text-muted mb-1">
                                        <?= $e->html('Response · ' . $response->responderName) ?>
                                        · <?= $e->html($response->respondedAt->setTimezone($indiaQa)->format('j M Y, g:i a') . ' IST') ?>
                                    </div>
                                    <p class="mb-0"><?= nl2br($e->html($response->body), false) ?></p>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($thread->canClose): ?>
                                <form method="post"
                                      action="<?= $e->attr($base . '/items/' . (string) $detail->item->contentId . '/questions/' . (string) $thread->questionId . '/close') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $e->html('Close question') ?></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canAsk): ?>
                <form method="post" action="<?= $e->attr($base . '/items/' . (string) $detail->item->contentId . '/questions') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <label class="form-label" for="qa-body"><?= $e->html('Ask a question') ?></label>
                    <textarea class="form-control mb-2" id="qa-body" name="body" rows="4" maxlength="2000" required></textarea>
                    <button type="submit" class="btn btn-outline-primary"><?= $e->html('Send question') ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="d-flex gap-2 mt-3">
        <?php if ($detail->previousContentId !== null): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= $e->attr($base . '/items/' . (string) $detail->previousContentId) ?>">
                <?= $e->html('Previous') ?>
            </a>
        <?php endif; ?>
        <?php if ($detail->nextContentId !== null): ?>
            <a class="btn btn-outline-primary btn-sm"
               href="<?= $e->attr($base . '/items/' . (string) $detail->nextContentId) ?>">
                <?= $e->html('Next') ?>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
