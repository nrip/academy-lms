<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var ?\Academy\Application\Certificates\PublicCertificateView $view */
/** @var string $certificateNumber */

ob_start();
?>
<div class="acad-certificate-verify">
    <h1 class="h3 mb-3"><?= $e->html('Certificate verification') ?></h1>

    <?php if ($view === null): ?>
        <div class="alert alert-warning">
            <?= $e->html('No certificate was found for number "' . $certificateNumber . '".') ?>
        </div>
    <?php else: ?>
        <div class="alert alert-<?= $e->attr($view->isValid ? 'success' : 'danger') ?>">
            <?= $e->html($view->isValid ? 'Valid certificate' : 'Revoked certificate') ?>
        </div>
        <dl class="row">
            <dt class="col-sm-3"><?= $e->html('Learner') ?></dt>
            <dd class="col-sm-9"><?= $e->html($view->learnerName) ?></dd>
            <dt class="col-sm-3"><?= $e->html('Course') ?></dt>
            <dd class="col-sm-9"><?= $e->html($view->courseTitle) ?></dd>
            <dt class="col-sm-3"><?= $e->html('Type') ?></dt>
            <dd class="col-sm-9"><?= $e->html($view->certificateLabel) ?></dd>
            <dt class="col-sm-3"><?= $e->html('Status') ?></dt>
            <dd class="col-sm-9"><?= $e->html(ucfirst($view->status)) ?></dd>
            <dt class="col-sm-3"><?= $e->html('Issue date') ?></dt>
            <dd class="col-sm-9"><?= $e->html($view->issuedAt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y')) ?></dd>
            <dt class="col-sm-3"><?= $e->html('Certificate number') ?></dt>
            <dd class="col-sm-9"><code><?= $e->html($view->certificateNumber) ?></code></dd>
        </dl>
        <p class="small text-muted mb-0">
            <?= $e->html('Public verification shows only limited fields. Contact details are never displayed.') ?>
        </p>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
