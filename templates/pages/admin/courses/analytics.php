<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Courses\CourseAnalyticsView $view */

$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-course-analytics">
    <p class="mb-2"><a href="/admin/courses/<?= $e->attr((string) $view->courseId) ?>"><?= $e->html('← Course') ?></a></p>
    <p class="acad-eyebrow mb-1"><?= $e->html('Insights') ?></p>
    <h1 class="h3 mb-4"><?= $e->html($view->courseTitle) ?></h1>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->learnersEnrolled) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Learners enrolled') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->activeLearners) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Active learners') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->completionRatePercent) ?>%</span>
                <span class="acad-stat-card__label"><?= $e->html('Completion rate') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->certificatesIssued) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Certificates issued') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->learnersActiveLast7Days) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Active in last 7 days') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->openQuestions) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Open questions') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->lessonsCompletedTotal) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Lessons completed') ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="acad-stat-card acad-stat-card--static">
                <span class="acad-stat-card__value">
                    <?= $e->html($view->averageScorePercent !== null ? (string) $view->averageScorePercent . '%' : '—') ?>
                </span>
                <span class="acad-stat-card__label"><?= $e->html('Avg assessment score') ?></span>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <section class="acad-panel h-100" aria-labelledby="progress-heading">
                <h2 id="progress-heading" class="h5"><?= $e->html('Progress distribution') ?></h2>
                <?php if ($view->learnersEnrolled === 0): ?>
                    <p class="text-muted mb-0"><?= $e->html('No learners yet. Insights appear after the first admission.') ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($view->progressBuckets as $bucket): ?>
                            <li class="d-flex justify-content-between border-bottom py-2">
                                <span><?= $e->html($bucket['label']) ?></span>
                                <span class="fw-semibold"><?= $e->html((string) $bucket['count']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="acad-panel h-100" aria-labelledby="assessment-heading">
                <h2 id="assessment-heading" class="h5"><?= $e->html('Assessment performance') ?></h2>
                <?php if ($view->assessmentAttemptsSubmitted === 0): ?>
                    <p class="text-muted mb-0"><?= $e->html('No submitted assessment attempts yet.') ?></p>
                <?php else: ?>
                    <p class="mb-1">
                        <?= $e->html((string) $view->assessmentAttemptsSubmitted . ' submitted · ' . (string) $view->assessmentAttemptsPassed . ' passed') ?>
                    </p>
                    <p class="text-muted mb-0">
                        <?= $e->html(
                            $view->averageScorePercent !== null
                                ? 'Average score ' . (string) $view->averageScorePercent . '%'
                                : 'Average score not available',
                        ) ?>
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <section class="acad-panel" aria-labelledby="activity-heading">
        <h2 id="activity-heading" class="h5"><?= $e->html('Recent activity') ?></h2>
        <?php if ($view->recentActivity === []): ?>
            <p class="text-muted mb-0"><?= $e->html('Course activity will appear here as learners enrol and progress.') ?></p>
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
