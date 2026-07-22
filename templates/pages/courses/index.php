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
    <p class="acad-eyebrow mb-2">Continuing medical education</p>
    <h1 class="h3 mb-4"><?= $e->html('Courses') ?></h1>

    <?php if ($courses === []): ?>
        <p class="text-muted"><?= $e->html('No courses are published yet. Please check back soon.') ?></p>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($courses as $entry): ?>
            <?php $course = $entry['course']; $version = $entry['version']; ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h5 card-title"><?= $e->html($version->title) ?></h2>
                        <p class="card-text text-muted small mb-2"><?= $e->html($course->courseCode) ?></p>
                        <p class="card-text flex-grow-1"><?= $e->html($version->intendedAudience) ?></p>
                        <p class="card-text fw-semibold">
                            <?= $e->html(FeeDisplay::formatted(
                                FeeDisplay::inclusiveAmount($version->standardFee, $version->gstRate),
                                $version->currency,
                            )) ?>
                            <span class="text-muted small"><?= $e->html('(GST inclusive)') ?></span>
                        </p>
                        <a class="btn btn-outline-primary mt-auto" href="/courses/<?= $e->attr($course->slug) ?>">
                            <?= $e->html('View details') ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
