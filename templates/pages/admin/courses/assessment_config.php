<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\ContentItem $contentItem */
/** @var \Academy\Domain\Courses\ContentItemContext $context */
/** @var ?\Academy\Domain\Assessments\Assessment $assessment */
/** @var list<\Academy\Domain\Assessments\Question> $linkedQuestions */
/** @var list<\Academy\Domain\Assessments\Question> $bankQuestions */
/** @var bool $editable */
/** @var ?string $error */
/** @var ?string $flash */
/** @var array<string, mixed>|null $posted */

$posted = $posted ?? null;
$curriculumPath = '/admin/courses/' . $course->courseId . '/versions/' . $context->courseVersionId . '/curriculum';
$actionPath = '/admin/content-items/' . $contentItem->contentId . '/assessment';

$val = static function (string $key, string $fallback) use ($posted, $assessment): string {
    if (is_array($posted) && array_key_exists($key, $posted)) {
        return (string) $posted[$key];
    }
    if ($assessment === null) {
        return $fallback;
    }

    return match ($key) {
        'title' => $assessment->title,
        'questions_per_attempt' => (string) $assessment->questionsPerAttempt,
        'pass_threshold_percent' => $assessment->passThresholdPercent,
        'max_attempts' => (string) $assessment->maxAttempts,
        default => $fallback,
    };
};

$selectedIds = [];
if (is_array($posted) && isset($posted['question_ids']) && is_array($posted['question_ids'])) {
    foreach ($posted['question_ids'] as $id) {
        $selectedIds[(int) $id] = true;
    }
} else {
    foreach ($linkedQuestions as $question) {
        $selectedIds[$question->questionId] = true;
    }
}

ob_start();
?>
<div class="acad-admin-assessment-config">
    <p class="mb-2">
        <a href="<?= $e->attr($curriculumPath) ?>"><?= $e->html('← Curriculum') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html('Assessment configuration') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($course->masterTitle) ?>
        · <?= $e->html($contentItem->title) ?>
        · <?= $e->html($editable ? 'Draft (editable)' : 'Locked (read-only)') ?>
    </p>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if (!$editable): ?>
        <div class="alert alert-warning">
            <?= $e->html('This CourseVersion is locked. Assessment configuration cannot be changed.') ?>
        </div>
    <?php endif; ?>

    <?php if ($assessment !== null): ?>
        <section class="mb-4">
            <h2 class="h5"><?= $e->html('Configured assessment') ?></h2>
            <dl class="row mb-0">
                <dt class="col-sm-3"><?= $e->html('Title') ?></dt>
                <dd class="col-sm-9"><?= $e->html($assessment->title) ?></dd>
                <dt class="col-sm-3"><?= $e->html('Questions / attempt') ?></dt>
                <dd class="col-sm-9"><?= $e->html((string) $assessment->questionsPerAttempt) ?></dd>
                <dt class="col-sm-3"><?= $e->html('Pass threshold') ?></dt>
                <dd class="col-sm-9"><?= $e->html($assessment->passThresholdPercent . '%') ?></dd>
                <dt class="col-sm-3"><?= $e->html('Max attempts') ?></dt>
                <dd class="col-sm-9"><?= $e->html((string) $assessment->maxAttempts) ?></dd>
                <dt class="col-sm-3"><?= $e->html('Linked questions') ?></dt>
                <dd class="col-sm-9"><?= $e->html((string) count($linkedQuestions)) ?></dd>
            </dl>
            <?php if ($linkedQuestions !== []): ?>
                <ol class="mt-3 mb-0">
                    <?php foreach ($linkedQuestions as $question): ?>
                        <li><?= $e->html($question->stem) ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($editable): ?>
        <section class="border-top pt-4">
            <h2 class="h5"><?= $e->html($assessment === null ? 'Add MCQ assessment' : 'Edit assessment') ?></h2>
            <form method="post" action="<?= $e->attr($actionPath) ?>" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <div class="col-12">
                    <label class="form-label" for="title"><?= $e->html('Assessment title') ?></label>
                    <input class="form-control" id="title" name="title" required maxlength="255"
                           value="<?= $e->attr($val('title', $contentItem->title)) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="questions_per_attempt"><?= $e->html('Number of questions') ?></label>
                    <input class="form-control" id="questions_per_attempt" name="questions_per_attempt" type="number"
                           min="1" required value="<?= $e->attr($val('questions_per_attempt', '5')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="pass_threshold_percent"><?= $e->html('Passing percentage') ?></label>
                    <input class="form-control" id="pass_threshold_percent" name="pass_threshold_percent" required
                           value="<?= $e->attr($val('pass_threshold_percent', '60.00')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="max_attempts"><?= $e->html('Maximum attempts') ?></label>
                    <input class="form-control" id="max_attempts" name="max_attempts" type="number" min="1" required
                           value="<?= $e->attr($val('max_attempts', '3')) ?>">
                </div>

                <div class="col-12">
                    <h3 class="h6"><?= $e->html('Select questions from bank') ?></h3>
                    <?php if ($bankQuestions === []): ?>
                        <div class="alert alert-secondary mb-0">
                            <?= $e->html('No active questions in the course bank. Add MCQs in the question bank first.') ?>
                            <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/question-bank">
                                <?= $e->html('Open question bank') ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($bankQuestions as $question): ?>
                                <label class="list-group-item">
                                    <input class="form-check-input me-2" type="checkbox" name="question_ids[]"
                                           value="<?= $e->attr((string) $question->questionId) ?>"
                                        <?= isset($selectedIds[$question->questionId]) ? 'checked' : '' ?>>
                                    <?= $e->html($question->stem) ?>
                                    <span class="text-muted small">(<?= $e->html($question->marks . ' marks') ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="small text-muted mt-2 mb-0">
                            <?= $e->html('Correct answers are not shown here — configure them in the question bank. Learner quiz runtime arrives later.') ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary" type="submit" <?= $bankQuestions === [] ? 'disabled' : '' ?>>
                        <?= $e->html('Save assessment configuration') ?>
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
