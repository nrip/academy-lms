<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var list<array{course: \Academy\Domain\Courses\Course, version: \Academy\Domain\Courses\CourseVersion, nextBatchName: ?string}> $courses */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-home">
    <section class="acad-home-hero mb-5">
        <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
        <h1 class="acad-home-hero__title"><?= $e->html('Learn from experts') ?></h1>
        <p class="acad-home-hero__lead">
            <?= $e->html('Structured programmes in obesity, metabolic health, and related clinical practice.') ?>
        </p>
        <a class="btn btn-primary btn-lg" href="/courses"><?= $e->html('Browse courses') ?></a>
    </section>

    <section class="mb-5" aria-labelledby="featured-heading">
        <div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
            <h2 id="featured-heading" class="h4 mb-0"><?= $e->html('Featured courses') ?></h2>
            <a href="/courses"><?= $e->html('All courses') ?></a>
        </div>
        <?php if ($courses === []): ?>
            <p class="text-muted"><?= $e->html('Courses will appear here once they are published.') ?></p>
        <?php else: ?>
            <div class="row g-3 g-lg-4">
                <?php foreach ($courses as $entry): ?>
                    <div class="col-md-6">
                        <?php
                        $course = $entry['course'];
                        $version = $entry['version'];
                        $nextBatchName = $entry['nextBatchName'];
                        require __DIR__ . '/courses/_card.php';
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="mb-5" aria-labelledby="why-heading">
        <h2 id="why-heading" class="h4 mb-3"><?= $e->html('Why this academy') ?></h2>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="acad-panel h-100 mb-0">
                    <h3 class="h6"><?= $e->html('Certificate programmes') ?></h3>
                    <p class="mb-0 text-muted"><?= $e->html('Structured courses with a clear path from application to completion.') ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="acad-panel h-100 mb-0">
                    <h3 class="h6"><?= $e->html('Verifiable certificates') ?></h3>
                    <p class="mb-0 text-muted"><?= $e->html('A third party can confirm a certificate with its number. Contact details are not shown.') ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="acad-panel h-100 mb-0">
                    <h3 class="h6"><?= $e->html('For clinicians') ?></h3>
                    <p class="mb-0 text-muted"><?= $e->html('Built for doctors, nurses, and allied professionals.') ?></p>
                </div>
            </div>
        </div>
    </section>

    <section class="acad-panel" aria-labelledby="certificates-heading">
        <h2 id="certificates-heading" class="h4"><?= $e->html('Certificates') ?></h2>
        <p class="mb-0">
            <?= $e->html('Programmes can award a certificate of participation or a certificate of completion. Each certificate has a number that can be verified.') ?>
        </p>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layouts/base.php';
