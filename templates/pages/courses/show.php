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

ob_start();
?>
<div class="acad-course-detail">
    <p class="acad-eyebrow mb-2"><a href="/courses"><?= $e->html('Courses') ?></a></p>
    <h1 class="h3 mb-1"><?= $e->html($version->title) ?></h1>
    <p class="text-muted mb-4"><?= $e->html($course->courseCode) ?> &middot; <?= $e->html($version->deliveryType) ?> &middot; <?= $e->html($version->durationText) ?></p>

    <div class="card mb-4">
        <div class="card-body">
            <p><?= $e->html($version->description) ?></p>
            <h2 class="h6 mt-3"><?= $e->html('Learning objectives') ?></h2>
            <p><?= $e->html($version->learningObjectives) ?></p>
            <h2 class="h6 mt-3"><?= $e->html('Who should attend') ?></h2>
            <p><?= $e->html($version->intendedAudience) ?></p>
            <h2 class="h6 mt-3"><?= $e->html('Syllabus summary') ?></h2>
            <p class="mb-0"><?= $e->html($version->syllabusSummary) ?></p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6"><?= $e->html('Fee') ?></h2>
            <p class="mb-0">
                <?= $e->html(FeeDisplay::formatted(FeeDisplay::inclusiveAmount($version->standardFee, $version->gstRate), $version->currency)) ?>
                <span class="text-muted small"><?= $e->html('(GST inclusive, standard fee — a batch may override this)') ?></span>
            </p>
            <p class="text-muted small mb-0"><?= $e->html('Certificate: ' . $version->certificateType) ?></p>
        </div>
    </div>

    <?php if ($eligibilityRules !== []): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6"><?= $e->html('Eligibility') ?></h2>
                <ul class="mb-0">
                    <?php foreach ($eligibilityRules as $rule): ?>
                        <li><?= $e->html($rule->displayLabel) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($documentRequirements !== []): ?>
        <div class="card mb-4">
            <div class="card-body">
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
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0"><?= $e->html('Batches') ?></h2>
                <a class="small" href="/courses/<?= $e->attr($course->slug) ?>/batches"><?= $e->html('View all batches') ?></a>
            </div>
            <?php if ($batches === []): ?>
                <p class="text-muted mb-0"><?= $e->html('No batches are scheduled yet.') ?></p>
            <?php endif; ?>
            <ul class="list-group">
                <?php foreach ($batches as $entry): ?>
                    <?php $batch = $entry['batch']; $availability = $entry['availability']; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
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
                                <a class="btn btn-outline-primary btn-sm" href="/login"><?= $e->html('Log in to apply') ?></a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= $e->html($availability->label()) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
