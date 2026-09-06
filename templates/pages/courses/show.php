<?php

declare(strict_types=1);

use Academy\Domain\Courses\BatchAvailability;
use Academy\Domain\Courses\FeeDisplay;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
    /** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var list<\Academy\Domain\Courses\EligibilityRule> $eligibilityRules */
/** @var list<\Academy\Domain\Courses\CourseDocumentRequirement> $documentRequirements */
/** @var list<array{batch: \Academy\Domain\Courses\Batch, availability: BatchAvailability}> $batches */
/** @var \Academy\Domain\Security\AuthContext|null $auth */
/** @var string $csrf */

$feeLabel = FeeDisplay::formatted(
    FeeDisplay::inclusiveAmount($version->standardFee, $version->gstRate),
        $version->currency,
);
$coursePath = '/courses/' . $course->slug;
$loginApplyHref = '/login?return_to=' . rawurlencode($coursePath);
$hasSelectableBatch = false;
foreach ($batches as $entry) {
    if ($entry['availability']->selectable) {
        $hasSelectableBatch = true;
        break;
    }
}

ob_start();
?>
<div class="acad-course-detail">
    <p class="acad-eyebrow mb-2"><a href="/courses"><?= $e->html('Courses') ?></a></p>

    <div class="acad-course-hero mb-4">
        <div class="acad-course-hero__main">
            <h1 class="acad-course-hero__title"><?= $e->html($version->title) ?></h1>
            <p class="acad-course-hero__meta text-muted mb-3">
                <?= $e->html($course->courseCode) ?>
                &middot; <?= $e->html($version->deliveryType) ?>
                &middot; <?= $e->html($version->durationText) ?>
            </p>
            <p class="acad-course-hero__fee mb-0">
                <span class="acad-course-hero__fee-amount"><?= $e->html($feeLabel) ?></span>
                <span class="text-muted small"><?= $e->html('GST inclusive') ?></span>
            </p>
            <p class="text-muted small mb-0 mt-1"><?= $e->html('Certificate: ' . $version->certificateType) ?></p>
        </div>
        <div class="acad-course-hero__cta">
            <?php if ($hasSelectableBatch): ?>
                <?php if ($auth !== null && $auth->authenticated): ?>
                    <a class="btn btn-primary btn-lg" href="#batches"><?= $e->html('Choose a batch to apply') ?></a>
                <?php else: ?>
                    <a class="btn btn-primary btn-lg" href="<?= $e->attr($loginApplyHref) ?>">
                        <?= $e->html('Sign in to apply') ?>
                    </a>
                    <p class="small text-muted mb-0 mt-2">
                        <a href="#batches"><?= $e->html('Or review batches first') ?></a>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn btn-outline-secondary btn-lg" href="#batches"><?= $e->html('View batch availability') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="acad-panel mb-4">
        <p><?= $e->html($version->description) ?></p>
        <h2 class="h6 mt-3"><?= $e->html('Learning objectives') ?></h2>
        <p><?= $e->html($version->learningObjectives) ?></p>
        <h2 class="h6 mt-3"><?= $e->html('Who should attend') ?></h2>
        <p><?= $e->html($version->intendedAudience) ?></p>
        <h2 class="h6 mt-3"><?= $e->html('Syllabus summary') ?></h2>
        <p class="mb-0"><?= $e->html($version->syllabusSummary) ?></p>
    </div>

    <?php if ($eligibilityRules !== []): ?>
        <div class="acad-panel mb-4">
            <h2 class="h6"><?= $e->html('Eligibility') ?></h2>
            <ul class="mb-0">
                <?php foreach ($eligibilityRules as $rule): ?>
                    <li><?= $e->html($rule->displayLabel) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($documentRequirements !== []): ?>
        <div class="acad-panel mb-4">
            <h2 class="h6"><?= $e->html('Documents required at application') ?></h2>
            <ul class="mb-0">
                <?php foreach ($documentRequirements as $requirement): ?>
                    <li>
                        <?= $e->html($requirement->documentName) ?>
                        <?php if (!$requirement->mandatory): ?>
                            <span class="text-muted small"><?= $e->html('(optional)') ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="acad-panel" id="batches">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h5 mb-0"><?= $e->html('Batches — apply here') ?></h2>
            <a class="small" href="/courses/<?= $e->attr($course->slug) ?>/batches"><?= $e->html('View all batches') ?></a>
        </div>
        <?php if ($batches === []): ?>
            <p class="text-muted mb-0"><?= $e->html('No batches are scheduled yet.') ?></p>
        <?php endif; ?>
        <ul class="list-group list-group-flush acad-batch-list">
            <?php foreach ($batches as $entry): ?>
                <?php $batch = $entry['batch']; $availability = $entry['availability']; ?>
                <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2 px-0">
                    <div>
                        <span class="fw-semibold"><?= $e->html($batch->name) ?></span>
                        <span class="text-muted small d-block">
                            <?= $e->html($batch->startsAt->format('d M Y')) ?> &ndash; <?= $e->html($batch->endsAt->format('d M Y')) ?>
                        </span>
                    </div>
                    <?php if ($availability->selectable): ?>
                        <?php if ($auth !== null && $auth->authenticated): ?>
                            <form method="post" action="/applications">
                                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                <input type="hidden" name="batch_id" value="<?= $e->attr($batch->batchId) ?>">
                                <button class="btn btn-primary btn-sm" type="submit"><?= $e->html('Apply') ?></button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-outline-primary btn-sm" href="<?= $e->attr($loginApplyHref) ?>">
                                <?= $e->html('Sign in to apply') ?>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><?= $e->html($availability->label()) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
