<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var ?string $nextBatchName */

use Academy\Domain\Courses\FeeDisplay;

$nextBatchName = $nextBatchName ?? null;
$initial = mb_strtoupper(mb_substr($version->title, 0, 1));
?>
<article class="acad-course-card h-100">
    <div class="acad-course-card__media" aria-hidden="true">
        <?php if ($course->hasCover()): ?>
            <img src="<?= $e->attr('/courses/' . $course->slug . '/cover') ?>" alt="">
        <?php else: ?>
            <span class="acad-course-card__mark"><?= $e->html($initial) ?></span>
        <?php endif; ?>
    </div>
    <div class="acad-course-card__body">
        <p class="acad-course-card__meta mb-2">
            <?= $e->html($version->durationText) ?>
            <?php if ($version->certificateType !== ''): ?>
                &middot; <?= $e->html($version->certificateType) ?>
            <?php endif; ?>
        </p>
        <h2 class="acad-course-card__title h5"><?= $e->html($version->title) ?></h2>
        <p class="acad-course-card__audience"><?= $e->html($version->intendedAudience) ?></p>
        <?php if ($nextBatchName !== null): ?>
            <p class="acad-course-card__intake small mb-2"><?= $e->html('Next intake: ' . $nextBatchName) ?></p>
        <?php endif; ?>
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
        </div>
    </div>
</article>
