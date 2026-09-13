<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Dashboard\LearnerDashboardView $view */

$badge = static function (string $severity): string {
    return match ($severity) {
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'info' => 'info',
        default => 'secondary',
    };
};

$needsYou = array_values(array_filter(
    $view->requiredActions,
    static fn (array $action): bool => $action['label'] !== 'Continue learning',
));
$admissionCards = array_values(array_filter(
    $view->cards,
    static fn ($card): bool => $card->enrolmentId === null,
));
$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-dashboard">
    <p class="acad-eyebrow mb-2"><?= $e->html('Learner') ?></p>
    <h1 class="h3 mb-4"><?= $e->html('My learning') ?></h1>

    <?php if ($needsYou !== []): ?>
        <section class="acad-next-step mb-4" aria-labelledby="required-actions-heading">
            <h2 id="required-actions-heading" class="h5 mb-3"><?= $e->html('Your next step') ?></h2>
            <ul class="list-unstyled mb-0">
                <?php foreach ($needsYou as $action): ?>
                    <li class="acad-next-step__item">
                        <span class="acad-next-step__label"><?= $e->html($action['label']) ?></span>
                        <a class="btn btn-primary" href="<?= $e->attr($action['href']) ?>"><?= $e->html('Continue') ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($view->unreadUpdates > 0): ?>
        <section class="acad-panel mb-4" aria-labelledby="updates-heading">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                <h2 id="updates-heading" class="h5 mb-0"><?= $e->html('Updates') ?></h2>
                <a href="/notifications"><?= $e->html('View all') ?></a>
            </div>
            <p class="small text-muted mb-2">
                <?= $e->html($view->unreadUpdates === 1 ? '1 unread update.' : $view->unreadUpdates . ' unread updates.') ?>
            </p>
            <ul class="list-unstyled mb-0">
                <?php foreach ($view->recentUpdates as $update): ?>
                    <li class="mb-1"><a href="/notifications"><?= $e->html($update->title) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="mb-4" aria-labelledby="courses-heading">
        <h2 id="courses-heading" class="h5 mb-3"><?= $e->html('My courses') ?></h2>
        <?php if ($view->studyCards === []): ?>
            <p class="text-muted mb-0"><?= $e->html('Courses you are admitted to appear here.') ?></p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($view->studyCards as $study): ?>
                    <div class="col-md-6">
                        <article class="acad-study-card h-100">
                            <?php if ($study->coverPath !== null): ?>
                                <div class="acad-study-card__media">
                                    <img src="<?= $e->attr($study->coverPath) ?>" alt="">
                                </div>
                            <?php endif; ?>
                            <div class="acad-study-card__body">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <h3 class="h6 mb-0"><?= $e->html($study->courseTitle) ?></h3>
                                    <span class="badge text-bg-<?= $e->attr($badge($study->statusSeverity)) ?>">
                                        <?= $e->html($study->statusLabel) ?>
                                    </span>
                                </div>
                                <p class="small text-muted mb-2"><?= $e->html($study->batchName) ?></p>
                                <?php if ($study->statusExplanation !== ''): ?>
                                    <p class="small mb-2"><?= $e->html($study->statusExplanation) ?></p>
                                <?php endif; ?>
                                <?php if ($study->totalCount > 0): ?>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span><?= $e->html('Progress') ?></span>
                                        <span><?= $e->html((string) $study->completedCount . ' / ' . (string) $study->totalCount . ' complete') ?></span>
                                    </div>
                                    <div class="progress mb-3" role="progressbar" aria-valuenow="<?= $e->attr((string) $study->progressPercent) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $e->attr('Lesson progress') ?>">
                                        <div class="progress-bar" style="width: <?= $e->attr((string) $study->progressPercent) ?>%"></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($study->continueTitle !== null): ?>
                                    <p class="small mb-3">
                                        <?= $e->html('Current lesson: ' . $study->continueTitle) ?>
                                        <?php if ($study->continueChapterTitle !== null && $study->continueChapterTitle !== ''): ?>
                                            <span class="text-muted"><?= $e->html(' · Chapter: ' . $study->continueChapterTitle) ?></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($study->contentAccessible): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= $e->attr($study->continueHref) ?>">
                                            <?= $e->html('Continue learning') ?>
                                        </a>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= $e->attr('/learning/enrolments/' . $study->enrolmentId) ?>">
                                            <?= $e->html('View course') ?>
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $e->attr($study->certificatesHref) ?>">
                                        <?= $e->html($study->certificateCount === 1 ? '1 certificate' : $study->certificateCount . ' certificates') ?>
                                    </a>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($view->upcomingSessions !== []): ?>
        <section class="acad-panel mb-4" aria-labelledby="live-heading">
            <h2 id="live-heading" class="h5"><?= $e->html('Upcoming live sessions') ?></h2>
            <ul class="list-unstyled mb-0">
                <?php foreach ($view->upcomingSessions as $session): ?>
                    <li class="acad-next-step__item">
                        <div>
                            <div><?= $e->html($session->lessonTitle) ?></div>
                            <div class="small text-muted">
                                <?= $e->html($session->courseTitle) ?>
                                · <?= $e->html($session->startsAt->setTimezone($india)->format('j M Y, g:i a') . ' IST') ?>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?= $e->attr($session->href) ?>"><?= $e->html('Open lesson') ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($admissionCards !== []): ?>
        <section class="acad-panel mb-4" aria-labelledby="applications-heading">
            <h2 id="applications-heading" class="h5"><?= $e->html('Applications') ?></h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th><?= $e->html('Application') ?></th>
                        <th><?= $e->html('Course / batch') ?></th>
                        <th><?= $e->html('Status') ?></th>
                        <th><?= $e->html('Next step') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($admissionCards as $card): ?>
                        <tr>
                            <td>
                                <a href="/applications/<?= $e->attr((string) $card->applicationId) ?>">
                                    <?= $e->html($card->applicationNumber) ?>
                                </a>
                            </td>
                            <td>
                                <div><?= $e->html($card->courseTitle) ?></div>
                                <div class="small text-muted"><?= $e->html($card->batchName) ?></div>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= $e->attr($badge($card->applicationPresentation->severity)) ?>">
                                    <?= $e->html($card->applicationPresentation->label) ?>
                                </span>
                                <div class="small text-muted"><?= $e->html($card->applicationPresentation->explanation) ?></div>
                            </td>
                            <td>
                                <?php if ($card->primaryAction !== null): ?>
                                    <a href="<?= $e->attr($card->primaryAction['href']) ?>">
                                        <?= $e->html($card->primaryAction['label']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><?= $e->html('None') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($view->cards === [] && $view->studyCards === []): ?>
        <p class="text-muted mb-3"><?= $e->html('You have no applications yet.') ?></p>
        <a class="btn btn-outline-primary" href="/courses"><?= $e->html('Browse courses') ?></a>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
