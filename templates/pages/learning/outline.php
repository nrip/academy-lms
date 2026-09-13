<?php

declare(strict_types=1);

use Academy\Domain\Courses\LessonKind;
use Academy\Domain\Learning\ContentProgressCompletionStatus;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Learning\LearnerPlayerOutlineView $outline */
/** @var ?string $flash */
/** @var ?string $error */

$base = '/learning/enrolments/' . $outline->enrolment->enrolmentId;

ob_start();
?>
<div class="acad-learner-outline">
    <p class="mb-2">
        <a href="/dashboard"><?= $e->html('← Dashboard') ?></a>
        · <a href="<?= $e->attr($base . '/certificates') ?>"><?= $e->html('Certificates') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html($outline->courseTitle) ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($outline->versionTitle) ?>
        · <?= $e->html($outline->enrolment->publicReference) ?>
    </p>

    <?php $pct = $outline->progressPercent(); ?>
    <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1">
            <span><?= $e->html('Progress') ?></span>
            <span><?= $e->html((string) $outline->completedCount . ' / ' . (string) $outline->totalCount . ' complete') ?></span>
        </div>
        <div class="progress" role="progressbar" aria-valuenow="<?= $e->attr((string) $pct) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $e->attr('Lesson progress') ?>">
            <div class="progress-bar" style="width: <?= $e->attr((string) $pct) ?>%"></div>
        </div>
    </div>

    <?php $continue = $outline->continueTarget(); ?>
    <?php if ($continue !== null): ?>
        <p class="mb-4">
            <a class="btn btn-primary" href="<?= $e->attr($base . '/items/' . (string) $continue['contentId']) ?>">
                <?= $e->html('Continue: ' . $continue['title']) ?>
            </a>
        </p>
    <?php endif; ?>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <?php if ($outline->accessMessage !== null): ?>
        <div class="alert alert-info"><?= $e->html($outline->accessMessage) ?></div>
    <?php endif; ?>

    <?php if ($outline->modules === []): ?>
        <p class="text-muted"><?= $e->html('No curriculum is available for this course version yet.') ?></p>
    <?php endif; ?>

    <?php foreach ($outline->modules as $moduleView): ?>
        <section class="mb-4">
            <p class="small text-muted mb-1"><?= $e->html('Chapter') ?></p>
            <h2 class="h5 mb-2">
                <?= $e->html($moduleView->module->title) ?>
                <?php if (!$moduleView->unlocked): ?>
                    <span class="badge text-bg-secondary"><?= $e->html('Locked') ?></span>
                <?php endif; ?>
            </h2>
            <?php if ($moduleView->module->description !== ''): ?>
                <p class="text-muted small"><?= $e->html($moduleView->module->description) ?></p>
            <?php endif; ?>
            <ul class="list-group">
                <?php foreach ($moduleView->items as $itemView): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($itemView->accessible): ?>
                                <a href="<?= $e->attr($base . '/items/' . (string) $itemView->item->contentId) ?>">
                                    <?= $e->html($itemView->item->title) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><?= $e->html($itemView->item->title) ?></span>
                                <span class="badge text-bg-light border ms-1"><?= $e->html('Locked') ?></span>
                            <?php endif; ?>
                            <div class="small text-muted"><?= $e->html(LessonKind::labelForItem($itemView->item)) ?></div>
                        </div>
                        <span class="badge text-bg-<?= $e->attr($itemView->completed ? 'success' : 'secondary') ?>">
                            <?php
                            $statusLabel = match ($itemView->completionStatus) {
                                ContentProgressCompletionStatus::COMPLETED => 'Completed',
                                ContentProgressCompletionStatus::IN_PROGRESS => 'In progress',
                                default => 'Not started',
                            };
                            ?>
                            <?= $e->html($statusLabel) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
