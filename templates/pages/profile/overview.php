<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var \Academy\Domain\Identity\LearnerProfile $profile */
/** @var \Academy\Domain\Identity\ProfileCompleteness $completeness */
/** @var int $qualificationsCount */

$sectionLabels = [
    'core_personal' => 'Core personal details',
    'contact_address' => 'Contact & address',
    'professional' => 'Professional details',
    'medical_registration' => 'Medical registration',
    'qualifications' => 'Qualifications',
];

ob_start();
?>
<div class="acad-profile-overview">
    <p class="acad-eyebrow mb-2">My account</p>
    <h1 class="h3 mb-4"><?= $e->html('Profile completeness') ?></h1>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold"><?= $e->html('Overall completeness') ?></span>
                <span class="fw-semibold"><?= $e->html($completeness->percentage) ?>%</span>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="<?= $e->attr($completeness->percentage) ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: <?= $e->attr($completeness->percentage) ?>%"></div>
            </div>
        </div>
    </div>

    <ul class="list-group mb-4">
        <?php foreach ($sectionLabels as $key => $label): ?>
            <?php $complete = in_array($key, $completeness->completedSections, true); ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><?= $e->html($label) ?></span>
                <?php if ($complete): ?>
                    <span class="badge bg-success acad-pill--success"><?= $e->html('Complete') ?></span>
                <?php else: ?>
                    <span class="badge bg-secondary"><?= $e->html('Incomplete') ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="/profile/personal"><?= $e->html('Edit personal details') ?></a>
        <a class="btn btn-outline-primary" href="/profile/professional"><?= $e->html('Edit professional details') ?></a>
        <a class="btn btn-outline-primary" href="/profile/qualifications">
            <?= $e->html('Qualifications') ?> (<?= $e->html($qualificationsCount) ?>)
        </a>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
