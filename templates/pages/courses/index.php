<?php

declare(strict_types=1);

use Academy\Domain\Courses\FeeDisplay;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var list<array{course: \Academy\Domain\Courses\Course, version: \Academy\Domain\Courses\CourseVersion}> $courses */
    /** @var \Academy\Domain\Security\AuthContext|null $auth */

ob_start();
?>
<div class="acad-course-catalogue">
    <header class="acad-catalogue-intro mb-4">
        <p class="acad-eyebrow mb-2"><?= $e->html('Continuing medical education') ?></p>
        <h1 class="acad-catalogue-intro__title"><?= $e->html('Courses') ?></h1>
        <p class="acad-catalogue-intro__lead text-muted mb-0">
            <?= $e->html('Browse accredited programmes and apply to an open batch.') ?>
        </p>
    </header>

    <?php if ($courses === []): ?>
        <p class="text-muted"><?= $e->html('No courses are published yet. Please check back soon.') ?></p>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <?php foreach ($courses as $entry): ?>
            <?php $course = $entry['course']; $version = $entry['version']; ?>
            <div class="col-md-6">
                <article class="acad-course-card h-100">
                    <div class="acad-course-card__body">
                        <p class="acad-course-card__meta mb-2">
                            <?= $e->html($course->courseCode) ?>
                            &middot; <?= $e->html($version->deliveryType) ?>
                            &middot; <?= $e->html($version->durationText) ?>
                        </p>
                        <h2 class="acad-course-card__title h5"><?= $e->html($version->title) ?></h2>
                        <p class="acad-course-card__audience"><?= $e->html($version->intendedAudience) ?></p>
                        <p class="acad-course-card__fee mb-3">
                            <span class="fw-semibold">
                                <?= $e->html(FeeDisplay::formatted(
                                    FeeDisplay::inclusiveAmount($version->standardFee, $version->gstRate),
                                    $version->currency,
                                )) ?>
                            </span>
                            <span class="text-muted small"><?= $e->html('GST inclusive') ?></span>
                        </p>
                        <div class="acad-course-card__actions mt-auto">
                            <a class="btn btn-primary" href="/courses/<?= $e->attr($course->slug) ?>">
                                <?= $e->html('View course') ?>
                            </a>
                            <span class="acad-course-card__hint text-muted small">
                                <?= $e->html('Apply on the course page') ?>
                            </span>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
