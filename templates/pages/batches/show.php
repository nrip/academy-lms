<?php

declare(strict_types=1);

use Academy\Domain\Courses\FeeDisplay;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var \Academy\Domain\Courses\Batch $batch */
/** @var \Academy\Domain\Courses\BatchAvailability $availability */
/** @var \Academy\Domain\Security\AuthContext|null $auth */
/** @var string $csrf */

ob_start();
?>
<div class="acad-batch-detail">
    <p class="acad-eyebrow mb-2">
        <a href="/courses/<?= $e->attr($course->slug) ?>"><?= $e->html($version->title) ?></a>
    </p>
    <h1 class="h3 mb-4"><?= $e->html($batch->name) ?></h1>

    <div class="card mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4"><?= $e->html('Dates') ?></dt>
                <dd class="col-sm-8"><?= $e->html($batch->startsAt->format('d M Y')) ?> &ndash; <?= $e->html($batch->endsAt->format('d M Y')) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Applications window') ?></dt>
                <dd class="col-sm-8"><?= $e->html($batch->applicationsOpenAt->format('d M Y')) ?> &ndash; <?= $e->html($batch->applicationsCloseAt->format('d M Y')) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Delivery') ?></dt>
                <dd class="col-sm-8"><?= $e->html($batch->deliveryMode) ?> &middot; <?= $e->html($batch->venueOrOnlineDetails) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Fee') ?></dt>
                <dd class="col-sm-8">
                    <?= $e->html(FeeDisplay::formatted(
                        FeeDisplay::inclusiveAmount(FeeDisplay::effectiveBaseFee($batch, $version), $version->gstRate),
                        $batch->currency,
                    )) ?>
                    <span class="text-muted small"><?= $e->html('(GST inclusive)') ?></span>
                </dd>
            </dl>
        </div>
    </div>

    <?php if ($availability->selectable): ?>
        <?php if ($auth !== null && $auth->authenticated): ?>
            <form method="post" action="/applications">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <input type="hidden" name="batch_id" value="<?= $e->attr($batch->batchId) ?>">
                <button class="btn btn-primary" type="submit"><?= $e->html('Apply') ?></button>
            </form>
        <?php else: ?>
            <a class="btn btn-outline-primary" href="/login"><?= $e->html('Log in to apply') ?></a>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-secondary" role="status"><?= $e->html($availability->label()) ?></div>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
