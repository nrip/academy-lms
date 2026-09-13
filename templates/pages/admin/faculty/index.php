<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Courses\FacultyHomeView $view */

$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-faculty-home">
    <p class="acad-eyebrow mb-1"><?= $e->html('Faculty') ?></p>
    <h1 class="h3 mb-4"><?= $e->html('Your teaching') ?></h1>

    <div class="row g-3 mb-4">
        <div class="col-sm-6">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) count($view->courses)) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Assigned courses') ?></span>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->learnersEnrolled) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Learners enrolled') ?></span>
            </div>
        </div>
    </div>

    <section class="mb-4" aria-labelledby="assigned-heading">
        <h2 id="assigned-heading" class="h5"><?= $e->html('Assigned courses') ?></h2>
        <?php if ($view->courses === []): ?>
            <p class="text-muted"><?= $e->html('No courses are assigned to you yet.') ?></p>
        <?php else: ?>
            <div class="list-group">
                <?php foreach ($view->courses as $course): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                       href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>">
                        <span>
                            <span class="fw-semibold"><?= $e->html($course->title) ?></span>
                            <span class="small text-muted d-block">
                                <?= $e->html($course->published ? 'Published' : 'Draft') ?>
                                <?php if ($course->nextBatchName !== null): ?>
                                    <?= $e->html(' · ' . $course->nextBatchName) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="badge text-bg-light border"><?= $e->html((string) $course->learnerCount . ' learners') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="acad-panel mb-4" aria-labelledby="live-heading">
        <h2 id="live-heading" class="h5"><?= $e->html('Upcoming live sessions') ?></h2>
        <?php if ($view->upcomingSessions === []): ?>
            <p class="text-muted mb-0"><?= $e->html('No upcoming live sessions in your courses.') ?></p>
        <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($view->upcomingSessions as $session): ?>
                    <li class="acad-next-step__item">
                        <div>
                            <div><?= $e->html($session->lessonTitle) ?></div>
                            <div class="small text-muted">
                                <?= $e->html($session->courseTitle . ' · ' . $session->chapterTitle) ?>
                                · <?= $e->html($session->startsAt->setTimezone($india)->format('j M Y, g:i a') . ' IST') ?>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?= $e->attr($session->href) ?>"><?= $e->html('Open chapters') ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="acad-panel" aria-labelledby="activity-heading">
        <h2 id="activity-heading" class="h5"><?= $e->html('Recent activity') ?></h2>
        <?php if ($view->recentActivity === []): ?>
            <p class="text-muted mb-0"><?= $e->html('Admissions and lesson updates in your courses will appear here.') ?></p>
        <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($view->recentActivity as $item): ?>
                    <li class="mb-2">
                        <a href="<?= $e->attr($item->href) ?>"><?= $e->html($item->label) ?></a>
                        <div class="small text-muted"><?= $e->html($item->at->setTimezone($india)->format('j M Y, g:i a') . ' IST') ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
