<?php

declare(strict_types=1);

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
    <h1 class="h3 mb-3"><?= $e->html($detail->item->title) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if ($detail->progress->isCompleted()): ?>
        <div class="alert alert-success"><?= $e->html('Completed') ?></div>
    <?php endif; ?>

    <?php if ($detail->item->contentType === 'mcq_assessment'): ?>
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
    <?php elseif ($detail->item->bodyText !== null && $detail->item->bodyText !== ''): ?>
        <div class="acad-lesson-body mb-4">
            <?= nl2br($e->html($detail->item->bodyText)) ?>
        </div>
    <?php elseif ($detail->item->contentType === 'pdf'): ?>
        <div class="alert alert-secondary mb-4">
            <?= $e->html('PDF content is available for mark-complete in this demo. File download arrives with media infrastructure.') ?>
        </div>
    <?php else: ?>
        <p class="text-muted"><?= $e->html('No lesson body has been authored for this item.') ?></p>
    <?php endif; ?>

    <?php if ($detail->canMarkComplete): ?>
        <form method="post" action="<?= $e->attr($base . '/items/' . (string) $detail->item->contentId . '/complete') ?>" class="mb-4">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button type="submit" class="btn btn-primary"><?= $e->html('Mark complete') ?></button>
        </form>
    <?php elseif ($detail->markCompleteBlockedReason !== null && !$detail->progress->isCompleted()): ?>
        <p class="text-muted"><?= $e->html($detail->markCompleteBlockedReason) ?></p>
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
