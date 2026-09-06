<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var \Academy\Domain\Certificates\Certificate $certificate */
/** @var string $academyName */
/** @var string $issued */
/** @var string $verifyUrl */

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $e->html($certificate->certificateLabel) ?></title>
    <style>
        @page { margin: 36pt; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
        }
        .acad-certificate-card {
            border: 2pt solid #333;
            padding: 48pt 36pt;
            text-align: center;
        }
        .acad-certificate-card .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 10pt;
            color: #6c757d;
            margin: 0 0 12pt;
        }
        .acad-certificate-card h1 {
            font-size: 20pt;
            font-weight: 700;
            margin: 0 0 6pt;
        }
        .acad-certificate-card .subtitle {
            font-size: 12pt;
            color: #6c757d;
            margin: 0 0 28pt;
        }
        .acad-certificate-card .lead {
            font-size: 12pt;
            margin: 0 0 8pt;
        }
        .acad-certificate-card .learner {
            font-size: 26pt;
            font-weight: 700;
            margin: 0 0 18pt;
        }
        .acad-certificate-card .course {
            font-size: 14pt;
            font-weight: 700;
            margin: 0 0 6pt;
        }
        .acad-certificate-card .version {
            font-size: 11pt;
            color: #6c757d;
            margin: 0 0 28pt;
        }
        .acad-certificate-card .meta {
            font-size: 10pt;
            margin: 0 0 18pt;
        }
        .acad-certificate-card .verify {
            font-size: 9pt;
            color: #333;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
<div class="acad-certificate-card">
    <p class="eyebrow"><?= $e->html($academyName) ?></p>
    <h1><?= $e->html($certificate->certificateLabel) ?></h1>
    <p class="subtitle"><?= $e->html('Certificate of Completion') ?></p>
    <p class="lead"><?= $e->html('This is to certify that') ?></p>
    <p class="learner"><?= $e->html($certificate->learnerNameSnapshot) ?></p>
    <p class="lead"><?= $e->html('has successfully completed') ?></p>
    <p class="course"><?= $e->html($certificate->courseTitleSnapshot) ?></p>
    <p class="version"><?= $e->html($certificate->versionTitleSnapshot) ?></p>
    <p class="meta">
        <?= $e->html('Issued ' . $issued) ?>
        · <?= $e->html('No. ' . $certificate->certificateNumber) ?>
        · <?= $e->html(ucfirst($certificate->status)) ?>
    </p>
    <p class="verify"><?= $e->html('Verify: ' . $verifyUrl) ?></p>
</div>
</body>
</html>
