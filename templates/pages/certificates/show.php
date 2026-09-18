<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Certificates\Certificate $certificate */
/** @var string $verifyUrl */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

$issued = $certificate->issuedAt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j F Y');
$isActive = $certificate->status === 'active';

ob_start();
?>
<div class="acad-certificate-show">
    <p class="mb-3 d-print-none">
        <a href="/learning/enrolments/<?= $e->attr((string) $certificate->enrolmentId) ?>/certificates">
            <?= $e->html('← Certificates') ?>
        </a>
    </p>

    <?php if ($isActive): ?>
        <div class="acad-celebrate alert alert-success d-print-none mb-4" role="status">
            <div class="fw-semibold"><?= $e->html('Congratulations — you earned this') ?></div>
            <p class="mb-0"><?= $e->html('Awarded on ' . $issued . '. Download a PDF copy or share the public verification link.') ?></p>
        </div>
    <?php else: ?>
        <div class="alert alert-warning d-print-none mb-4" role="status">
            <div class="fw-semibold"><?= $e->html('This certificate has been revoked') ?></div>
            <p class="mb-0"><?= $e->html('Public verification will show the revoked status without your contact details.') ?></p>
        </div>
    <?php endif; ?>

    <div class="acad-certificate-card mb-4 text-center">
        <p class="acad-certificate-card__issuer mb-2"><?= $e->html($branding->certificateIssuerName) ?></p>
        <p class="acad-certificate-card__eyebrow mb-1"><?= $e->html('Certificate of Completion') ?></p>
        <h1 class="acad-certificate-card__title h3 mb-4"><?= $e->html($certificate->certificateLabel) ?></h1>
        <p class="mb-1"><?= $e->html('This is to certify that') ?></p>
        <p class="acad-certificate-card__name mb-3"><?= $e->html($certificate->learnerNameSnapshot) ?></p>
        <p class="mb-1"><?= $e->html('has successfully completed') ?></p>
        <p class="h5 mb-1"><?= $e->html($certificate->courseTitleSnapshot) ?></p>
        <p class="text-muted mb-4"><?= $e->html($certificate->versionTitleSnapshot) ?></p>
        <p class="acad-certificate-card__meta small mb-0">
            <?= $e->html('Issued ' . $issued) ?>
            · <?= $e->html('No. ' . $certificate->certificateNumber) ?>
            · <?= $e->html(ucfirst($certificate->status)) ?>
        </p>
    </div>

    <div class="acad-certificate-actions d-flex flex-wrap gap-2 d-print-none">
        <a class="btn btn-primary" href="/certificates/<?= $e->attr((string) $certificate->certificateId) ?>/pdf">
            <?= $e->html('Download PDF') ?>
        </a>
        <button class="btn btn-outline-secondary" type="button" data-acad-print>
            <?= $e->html('Print certificate') ?>
        </button>
        <a class="btn btn-outline-primary" href="<?= $e->attr($verifyUrl) ?>" target="_blank" rel="noopener">
            <?= $e->html('Open verification page') ?>
        </a>
        <button class="btn btn-outline-secondary" type="button" data-acad-copy-link data-copy-url="<?= $e->attr($verifyUrl) ?>">
            <?= $e->html('Copy verification link') ?>
        </button>
    </div>
    <p class="small text-muted mt-3 d-print-none mb-0" data-acad-copy-status hidden><?= $e->html('Verification link copied.') ?></p>
</div>
<style>
@media print {
    .acad-shell__header, .acad-shell__nav, .d-print-none { display: none !important; }
    .acad-certificate-card { box-shadow: none !important; }
}
</style>
<script>
(() => {
    const copyBtn = document.querySelector('[data-acad-copy-link]');
    const status = document.querySelector('[data-acad-copy-status]');
    const printBtn = document.querySelector('[data-acad-print]');
    if (printBtn) {
        printBtn.addEventListener('click', () => window.print());
    }
    if (!copyBtn || !status) {
        return;
    }
    copyBtn.addEventListener('click', async () => {
        const url = copyBtn.getAttribute('data-copy-url') || '';
        const absolute = new URL(url, window.location.origin).toString();
        try {
            await navigator.clipboard.writeText(absolute);
            status.hidden = false;
        } catch (err) {
            status.textContent = 'Copy the verification page URL from your browser address bar.';
            status.hidden = false;
        }
    });
})();
</script>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
