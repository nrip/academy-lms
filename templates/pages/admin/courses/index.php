<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Courses\CourseAdminHomeView $view */
/** @var ?string $flash */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-admin-courses">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <p class="acad-eyebrow mb-1"><?= $e->html($branding->name) ?></p>
            <h1 class="h3 mb-0"><?= $e->html('Courses') ?></h1>
        </div>
        <a class="btn btn-primary" href="/admin/courses/new"><?= $e->html('New course') ?></a>
    </div>
    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg">
            <a class="acad-stat-card" href="/admin/courses">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->totalCourses) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Courses') ?></span>
            </a>
        </div>
        <div class="col-6 col-lg">
            <a class="acad-stat-card" href="/admin/courses?published=1">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->publishedCourses) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Published courses') ?></span>
            </a>
        </div>
        <div class="col-6 col-lg">
            <a class="acad-stat-card" href="/admin/courses">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->activeBatches) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Active batches') ?></span>
            </a>
        </div>
        <div class="col-6 col-lg">
            <a class="acad-stat-card" href="/admin/courses">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->learnersEnrolled) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Learners enrolled') ?></span>
            </a>
        </div>
        <div class="col-6 col-lg">
            <a class="acad-stat-card" href="/faculty">
                <span class="acad-stat-card__value"><?= $e->html((string) $view->pendingQuestions) ?></span>
                <span class="acad-stat-card__label"><?= $e->html('Pending Q&A') ?></span>
            </a>
        </div>
    </div>

    <?php if ($view->certificatesIssued > 0): ?>
        <p class="small text-muted mb-4">
            <?= $e->html((string) $view->certificatesIssued . ' certificate(s) issued across your courses.') ?>
        </p>
    <?php endif; ?>

    <?php if ($view->courses === []): ?>
        <div class="acad-empty">
            <p class="fw-semibold mb-1"><?= $e->html('No courses yet') ?></p>
            <p class="text-muted mb-3"><?= $e->html('Create your first course, then add chapters, eligibility, and publish an edition.') ?></p>
            <a class="btn btn-primary" href="/admin/courses/new"><?= $e->html('New course') ?></a>
        </div>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th><?= $e->html('Course') ?></th>
                    <th><?= $e->html('Edition') ?></th>
                    <th><?= $e->html('Next batch') ?></th>
                    <th><?= $e->html('Learners') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($view->courses as $course): ?>
                    <tr>
                        <td>
                            <div><?= $e->html($course->title) ?></div>
                            <div class="small text-muted"><?= $e->html($course->code) ?></div>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= $e->attr($course->published ? 'success' : 'secondary') ?>">
                                <?= $e->html($course->published ? 'Published' : 'Draft') ?>
                            </span>
                        </td>
                        <td><?= $e->html($course->nextBatchName ?? '—') ?></td>
                        <td><?= $e->html((string) $course->learnerCount) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>">
                                <?= $e->html('Open') ?>
                            </a>
                            <a class="ms-2" href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/analytics">
                                <?= $e->html('Insights') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <section class="acad-panel" aria-labelledby="activity-heading">
            <h2 id="activity-heading" class="h5"><?= $e->html('Recent activity') ?></h2>
            <?php if ($view->recentActivity === []): ?>
                <p class="text-muted mb-0"><?= $e->html('Enrolments, publications, certificates, and Q&A responses will appear here.') ?></p>
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
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
