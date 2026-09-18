<?php

declare(strict_types=1);

/**
 * Guided course-creation chrome over existing admin routes.
 *
 * @var \Academy\Infrastructure\View\Escaper $e
 * @var string $versionBase absolute path prefix /admin/courses/{id}/versions/{vid}
 * @var int $currentStep 1..5
 */

$steps = [
    1 => ['label' => 'Course details', 'href' => $versionBase],
    2 => ['label' => 'Chapters & lessons', 'href' => $versionBase . '/curriculum'],
    3 => ['label' => 'Eligibility', 'href' => $versionBase . '/admission'],
    4 => ['label' => 'Pricing', 'href' => $versionBase . '#pricing'],
    5 => ['label' => 'Review & publish', 'href' => $versionBase . '#publish'],
];
?>
<nav class="acad-author-steps mb-3" aria-label="Course setup">
    <?php foreach ($steps as $number => $step): ?>
        <?php
        $classes = 'acad-author-steps__item';
        if ($number === $currentStep) {
            $classes .= ' acad-author-steps__item--current';
        } elseif ($number < $currentStep) {
            $classes .= ' acad-author-steps__item--done';
        }
        ?>
        <?php if ($number === $currentStep): ?>
            <span class="<?= $e->attr($classes) ?>">
                <?= $e->html((string) $number . '. ' . $step['label']) ?>
            </span>
        <?php else: ?>
            <a class="<?= $e->attr($classes) ?>" href="<?= $e->attr($step['href']) ?>">
                <?= $e->html((string) $number . '. ' . $step['label']) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
