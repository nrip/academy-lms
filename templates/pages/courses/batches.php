<?php

declare(strict_types=1);

use Academy\Domain\Courses\BatchAvailability;
use Academy\Domain\Courses\FeeDisplay;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var list<array{batch: \Academy\Domain\Courses\Batch, availability: BatchAvailability}> $batches */
/** @var \Academy\Domain\Security\AuthContext|null $auth */
/** @var string $csrf */

ob_start();
?>
<div class="acad-batch-list">
    <p class="acad-eyebrow mb-2">
        <a href="/courses/<?= $e->attr($course->slug) ?>"><?= $e->html($version->title) ?></a>
    </p>
    <h1 class="h3 mb-4"><?= $e->html('Available batches') ?></h1>

    <?php if ($batches === []): ?>
        <p class="text-muted"><?= $e->html('No batches are scheduled yet.') ?></p>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($batches as $entry): ?>
            <?php $batch = $entry['batch']; $availability = $entry['availability']; ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h6 card-title"><?= $e->html($batch->name) ?></h2>
                        <p class="card-text text-muted small mb-1">
                            <?= $e->html($batch->startsAt->format('d M Y')) ?> &ndash; <?= $e->html($batch->endsAt->format('d M Y')) ?>
                        </p>
                        <p class="card-text text-muted small mb-2"><?= $e->html($batch->deliveryMode) ?> &middot; <?= $e->html($batch->venueOrOnlineDetails) ?></p>
                        <p class="card-text fw-semibold">
                            <?= $e->html(FeeDisplay::formatted(
                                FeeDisplay::inclusiveAmount(FeeDisplay::effectiveBaseFee($batch, $version), $version->gstRate),
                                $batch->currency,
                            )) ?>
                        </p>
                        <div class="mt-auto d-flex justify-content-between align-items-center gap-2">
                            <a class="small" href="/batches/<?= $e->attr($batch->batchId) ?>"><?= $e->html('Details') ?></a>
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
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
