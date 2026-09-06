<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Certificates\Certificate $certificate */
/** @var string $verifyUrl */

$issued = $certificate->issuedAt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y');

ob_start();
?>
<div class="acad-certificate-show">
    <p class="mb-3 d-print-none">
        <a href="/learning/enrolments/<?= $e->attr((string) $certificate->enrolmentId) ?>/certificates">
            <?= $e->html('← Certificates') ?>
        </a>
    </p>

    <div class="acad-certificate-card border p-4 p-md-5 mb-4 text-center">
        <p class="text-uppercase text-muted small mb-2"><?= $e->html('Academy LMS') ?></p>
        <h1 class="h3 mb-1"><?= $e->html($certificate->certificateLabel) ?></h1>
        <p class="text-muted mb-4"><?= $e->html('Certificate of Completion') ?></p>
        <p class="mb-1"><?= $e->html('This is to certify that') ?></p>
        <p class="display-6 fs-3 mb-3"><?= $e->html($certificate->learnerNameSnapshot) ?></p>
        <p class="mb-1"><?= $e->html('has successfully completed') ?></p>
        <p class="h5 mb-1"><?= $e->html($certificate->courseTitleSnapshot) ?></p>
        <p class="text-muted mb-4"><?= $e->html($certificate->versionTitleSnapshot) ?></p>
        <p class="small mb-0">
            <?= $e->html('Issued ' . $issued) ?>
            · <?= $e->html('No. ' . $certificate->certificateNumber) ?>
            · <?= $e->html(ucfirst($certificate->status)) ?>
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2 d-print-none">
        <a class="btn btn-primary" href="/certificates/<?= $e->attr((string) $certificate->certificateId) ?>/pdf">
            <?= $e->html('Download PDF') ?>
        </a>
        <button class="btn btn-outline-secondary" type="button" data-acad-print>
            <?= $e->html('Print') ?>
        </button>
        <a class="btn btn-outline-primary" href="<?= $e->attr($verifyUrl) ?>" target="_blank" rel="noopener">
            <?= $e->html('Public verification link') ?>
        </a>
    </div>
</div>
<style>
@media print {
    .acad-shell__header, .acad-shell__nav, .d-print-none { display: none !important; }
    .acad-certificate-card { border: 2px solid #333 !important; }
}
</style>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
