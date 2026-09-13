<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var list<array{course: \Academy\Domain\Courses\Course, version: \Academy\Domain\Courses\CourseVersion, nextBatchName: ?string}> $courses */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-course-catalogue">
    <header class="acad-catalogue-intro mb-4">
        <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
        <h1 class="acad-catalogue-intro__title"><?= $e->html('Courses') ?></h1>
        <p class="acad-catalogue-intro__lead text-muted mb-0">
            <?= $e->html('Browse programmes and apply to an open intake.') ?>
        </p>
    </header>

    <?php if ($courses === []): ?>
        <p class="text-muted"><?= $e->html('No courses are published yet. Please check back soon.') ?></p>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <?php foreach ($courses as $entry): ?>
            <div class="col-md-6">
                <?php
                $course = $entry['course'];
                $version = $entry['version'];
                $nextBatchName = $entry['nextBatchName'];
                require __DIR__ . '/_card.php';
                ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
