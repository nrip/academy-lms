<?php

declare(strict_types=1);

use Academy\Domain\Courses\LessonKind;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Application\Dashboard\LearnerProgressNarrative;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Learning\LearnerPlayerOutlineView $outline */
/** @var ?string $flash */
/** @var ?string $error */
/** @var array{open: int, answered: int, closed: int, recent: list<\Academy\Application\Learning\LearningQuestionThreadItemView>}|null $questionSummary */

$base = '/learning/enrolments/' . $outline->enrolment->enrolmentId;
$continue = $outline->continueTarget();
$pct = $outline->progressPercent();
$certificateReady = $pct >= 100;
$questionSummary = $questionSummary ?? null;
$narrative = LearnerProgressNarrative::forOutline(
    $outline->completedCount,
    $outline->totalCount,
    $pct,
    $continue['chapterIndex'] ?? null,
    $outline->chapterTotal(),
    $continue['title'] ?? null,
    0,
);
$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-learner-outline">
    <p class="mb-2">
        <a href="/dashboard"><?= $e->html('← My learning') ?></a>
        · <a href="<?= $e->attr($base . '/certificates') ?>"><?= $e->html('Certificates') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html($outline->courseTitle) ?></h1>
    <p class="text-muted mb-3"><?= $e->html('Edition: ' . $outline->versionTitle) ?></p>

    <?php if ($certificateReady): ?>
        <div class="acad-celebrate alert alert-success mb-4" role="status">
            <div class="fw-semibold mb-1"><?= $e->html('Well done — you finished this course') ?></div>
            <p class="mb-2"><?= $e->html('Review your lessons anytime, or open your certificates.') ?></p>
            <a class="btn btn-sm btn-success" href="<?= $e->attr($base . '/certificates') ?>">
                <?= $e->html('View certificates') ?>
            </a>
        </div>
    <?php endif; ?>

    <div class="acad-panel mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
            <div>
                <h2 class="h6 mb-1"><?= $e->html('Your progress') ?></h2>
                <p class="mb-0"><?= $e->html($narrative) ?></p>
            </div>
            <?php if ($continue !== null): ?>
                <a class="btn btn-primary flex-shrink-0" href="<?= $e->attr($base . '/items/' . (string) $continue['contentId']) ?>">
                    <?= $e->html('Continue learning') ?>
                </a>
            <?php endif; ?>
        </div>
        <?php if ($outline->totalCount > 0): ?>
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span><?= $e->html($outline->completedCount . ' of ' . $outline->totalCount . ' lessons') ?></span>
                <span><?= $e->html((string) $pct . '%') ?></span>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="<?= $e->attr((string) $pct) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $e->attr('Lesson progress') ?>">
                <div class="progress-bar" style="width: <?= $e->attr((string) $pct) ?>%"></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <?php if ($outline->accessMessage !== null): ?>
        <div class="alert alert-info"><?= $e->html($outline->accessMessage) ?></div>
    <?php endif; ?>

    <?php if ($questionSummary !== null && ($questionSummary['open'] + $questionSummary['answered'] + $questionSummary['closed']) > 0): ?>
        <section class="acad-panel mb-4" aria-labelledby="outline-qa-heading">
            <h2 id="outline-qa-heading" class="h5"><?= $e->html('Your questions') ?></h2>
            <p class="small text-muted mb-3">
                <?php
                $bits = [];
                if ($questionSummary['open'] > 0) {
                    $bits[] = $questionSummary['open'] === 1 ? '1 awaiting response' : $questionSummary['open'] . ' awaiting response';
                }
                if ($questionSummary['answered'] > 0) {
                    $bits[] = $questionSummary['answered'] === 1 ? '1 answered' : $questionSummary['answered'] . ' answered';
                }
                echo $e->html($bits !== [] ? implode(' · ', $bits) : 'Recent questions on this course.');
                ?>
            </p>
            <ul class="list-unstyled mb-0">
                <?php foreach ($questionSummary['recent'] as $thread): ?>
                    <li class="acad-next-step__item mb-2">
                        <div>
                            <span class="badge text-bg-light border me-1"><?= $e->html($thread->statusLabel) ?></span>
                            <span><?= $e->html(mb_strlen($thread->body) > 80 ? mb_substr($thread->body, 0, 80) . '…' : $thread->body) ?></span>
                            <div class="small text-muted">
                                <?= $e->html($thread->askedAt->setTimezone($india)->format('j M Y') . ' IST') ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="small text-muted mb-0 mt-2"><?= $e->html('Open a lesson to ask a new question or read faculty responses.') ?></p>
        </section>
    <?php endif; ?>

    <?php if ($outline->modules === []): ?>
        <p class="text-muted"><?= $e->html('No curriculum is available for this course edition yet.') ?></p>
    <?php endif; ?>

    <?php
    $chapterNumber = 0;
    foreach ($outline->modules as $moduleView):
        ++$chapterNumber;
        $chapterDone = 0;
        $chapterTotalItems = count($moduleView->items);
        foreach ($moduleView->items as $itemView) {
            if ($itemView->completed) {
                ++$chapterDone;
            }
        }
        ?>
        <section class="mb-4 acad-outline-chapter<?= $moduleView->unlocked ? '' : ' acad-outline-chapter--locked' ?>">
            <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
                <div>
                    <p class="small text-muted mb-1"><?= $e->html('Chapter ' . (string) $chapterNumber) ?></p>
                    <h2 class="h5 mb-0">
                        <?= $e->html($moduleView->module->title) ?>
                        <?php if (!$moduleView->unlocked): ?>
                            <span class="badge text-bg-secondary"><?= $e->html('Locked') ?></span>
                        <?php elseif ($chapterTotalItems > 0 && $chapterDone === $chapterTotalItems): ?>
                            <span class="badge text-bg-success"><?= $e->html('Complete') ?></span>
                        <?php endif; ?>
                    </h2>
                </div>
                <?php if ($chapterTotalItems > 0 && $moduleView->unlocked): ?>
                    <span class="small text-muted"><?= $e->html($chapterDone . '/' . $chapterTotalItems) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$moduleView->unlocked): ?>
                <p class="small text-muted mb-2"><?= $e->html('Finish the previous chapter to unlock these lessons.') ?></p>
            <?php endif; ?>
            <?php if ($moduleView->module->description !== ''): ?>
                <p class="text-muted small"><?= $e->html($moduleView->module->description) ?></p>
            <?php endif; ?>
            <ul class="list-group">
                <?php foreach ($moduleView->items as $itemView): ?>
                    <?php
                    $kind = LessonKind::labelForItem($itemView->item);
                    $isQuiz = str_contains(strtolower($kind), 'quiz') || str_contains(strtolower($kind), 'assessment');
                    $statusLabel = match ($itemView->completionStatus) {
                        ContentProgressCompletionStatus::COMPLETED => 'Completed',
                        ContentProgressCompletionStatus::IN_PROGRESS => 'In progress',
                        default => 'Not started',
                    };
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($itemView->accessible): ?>
                                <a href="<?= $e->attr($base . '/items/' . (string) $itemView->item->contentId) ?>">
                                    <?= $e->html($itemView->item->title) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><?= $e->html($itemView->item->title) ?></span>
                                <span class="badge text-bg-light border ms-1"><?= $e->html('Unavailable') ?></span>
                            <?php endif; ?>
                            <div class="small text-muted"><?= $e->html($isQuiz ? 'Quiz' : $kind) ?></div>
                        </div>
                        <span class="badge text-bg-<?= $e->attr($itemView->completed ? 'success' : 'secondary') ?>">
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
