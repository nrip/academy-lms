<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Assessments\QuestionBank $bank */
/** @var list<array{question: \Academy\Domain\Assessments\Question, options: list<\Academy\Domain\Assessments\QuestionOption>}> $questions */
/** @var ?string $error */
/** @var ?string $flash */
/** @var array<string, mixed>|null $posted */

$posted = $posted ?? null;
$base = '/admin/courses/' . $course->courseId . '/question-bank';

$postedStem = is_array($posted) ? (string) ($posted['stem'] ?? '') : '';
$postedMarks = is_array($posted) ? (string) ($posted['marks'] ?? '1.00') : '1.00';
$postedExplanation = is_array($posted) ? (string) ($posted['explanation'] ?? '') : '';
$postedStatus = is_array($posted) ? (string) ($posted['status'] ?? 'active') : 'active';
$postedCorrect = is_array($posted) ? (string) ($posted['correct_option'] ?? '0') : '0';
$postedOptions = [];
if (is_array($posted) && isset($posted['options']) && is_array($posted['options'])) {
    foreach ($posted['options'] as $opt) {
        if (is_array($opt)) {
            $postedOptions[] = (string) ($opt['option_text'] ?? '');
        } elseif (is_string($opt)) {
            $postedOptions[] = $opt;
        }
    }
}
while (count($postedOptions) < 4) {
    $postedOptions[] = '';
}

ob_start();
?>
<div class="acad-admin-question-bank">
    <p class="mb-2">
        <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>"><?= $e->html('← Course') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html('Question bank') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($course->masterTitle) ?>
        · <?= $e->html($bank->title) ?>
        · <?= $e->html((string) count($questions) . ' question(s)') ?>
    </p>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <p class="small text-muted mb-4">
        <?= $e->html('Authoring only. Correct answers are visible to Course Admins here and must never appear on learner-facing screens.') ?>
    </p>

    <section class="mb-5">
        <h2 class="h5"><?= $e->html('Questions') ?></h2>
        <?php if ($questions === []): ?>
            <p class="text-muted"><?= $e->html('No questions yet. Create an MCQ below.') ?></p>
        <?php else: ?>
            <ol class="list-group list-group-numbered mb-0">
                <?php foreach ($questions as $node): ?>
                    <?php $q = $node['question']; ?>
                    <li class="list-group-item">
                        <div class="fw-semibold"><?= $e->html($q->stem) ?></div>
                        <div class="small text-muted">
                            <?= $e->html($q->questionType) ?>
                            · <?= $e->html($q->marks . ' marks') ?>
                            · <?= $e->html('v' . (string) $q->version) ?>
                            · <?= $e->html($q->status) ?>
                        </div>
                        <ul class="mt-2 mb-0">
                            <?php foreach ($node['options'] as $option): ?>
                                <li>
                                    <?= $e->html($option->optionText) ?>
                                    <?php if ($option->isCorrect): ?>
                                        <span class="badge text-bg-success"><?= $e->html('Correct') ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="mb-5 border-top pt-4">
        <h2 class="h5"><?= $e->html('Add MCQ question') ?></h2>
        <form method="post" action="<?= $e->attr($base . '/questions') ?>" class="row g-3">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <input type="hidden" name="question_type" value="mcq_single">
            <div class="col-12">
                <label class="form-label" for="stem"><?= $e->html('Stem') ?></label>
                <textarea class="form-control" id="stem" name="stem" rows="3" required><?= $e->html($postedStem) ?></textarea>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="marks"><?= $e->html('Marks') ?></label>
                <input class="form-control" id="marks" name="marks" required value="<?= $e->attr($postedMarks) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status"><?= $e->html('Status') ?></label>
                <select class="form-select" id="status" name="status">
                    <option value="active" <?= $postedStatus === 'active' ? 'selected' : '' ?>><?= $e->html('Active') ?></option>
                    <option value="inactive" <?= $postedStatus === 'inactive' ? 'selected' : '' ?>><?= $e->html('Inactive') ?></option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="explanation"><?= $e->html('Explanation (optional)') ?></label>
                <textarea class="form-control" id="explanation" name="explanation" rows="2"><?= $e->html($postedExplanation) ?></textarea>
            </div>
            <div class="col-12">
                <div class="fw-semibold mb-2"><?= $e->html('Answer options') ?></div>
                <p class="small text-muted"><?= $e->html('Enter at least two options and select the correct one.') ?></p>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="input-group mb-2">
                        <div class="input-group-text">
                            <input class="form-check-input mt-0" type="radio" name="correct_option"
                                   value="<?= $e->attr((string) $i) ?>"
                                   <?= $postedCorrect === (string) $i ? 'checked' : ($i === 0 && $postedCorrect === '0' ? 'checked' : '') ?>
                                   aria-label="<?= $e->attr('Mark option ' . (string) ($i + 1) . ' correct') ?>">
                        </div>
                        <input class="form-control" name="options[<?= $e->attr((string) $i) ?>][option_text]"
                               placeholder="<?= $e->attr('Option ' . (string) ($i + 1)) ?>"
                               value="<?= $e->attr($postedOptions[$i] ?? '') ?>"
                            <?= $i < 2 ? 'required' : '' ?>>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit"><?= $e->html('Create question') ?></button>
            </div>
        </form>
    </section>

    <?php foreach ($questions as $node): ?>
        <?php
        $q = $node['question'];
        $editPath = $base . '/questions/' . $q->questionId;
        $optionTexts = [];
        $correctIdx = 0;
        foreach ($node['options'] as $idx => $option) {
            $optionTexts[] = $option->optionText;
            if ($option->isCorrect) {
                $correctIdx = $idx;
            }
        }
        while (count($optionTexts) < 4) {
            $optionTexts[] = '';
        }
        ?>
        <section class="mb-4 border rounded p-3">
            <h2 class="h6"><?= $e->html('Edit question #' . (string) $q->questionId) ?></h2>
            <form method="post" action="<?= $e->attr($editPath) ?>" class="row g-2">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <input type="hidden" name="question_type" value="mcq_single">
                <div class="col-12">
                    <label class="form-label"><?= $e->html('Stem') ?></label>
                    <textarea class="form-control form-control-sm" name="stem" rows="3" required><?= $e->html($q->stem) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= $e->html('Marks') ?></label>
                    <input class="form-control form-control-sm" name="marks" required value="<?= $e->attr($q->marks) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= $e->html('Status') ?></label>
                    <select class="form-select form-select-sm" name="status">
                        <option value="active" <?= $q->status === 'active' ? 'selected' : '' ?>><?= $e->html('Active') ?></option>
                        <option value="inactive" <?= $q->status === 'inactive' ? 'selected' : '' ?>><?= $e->html('Inactive') ?></option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label"><?= $e->html('Explanation') ?></label>
                    <textarea class="form-control form-control-sm" name="explanation" rows="2"><?= $e->html((string) $q->explanation) ?></textarea>
                </div>
                <div class="col-12">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="input-group mb-2">
                            <div class="input-group-text">
                                <input class="form-check-input mt-0" type="radio" name="correct_option"
                                       value="<?= $e->attr((string) $i) ?>"
                                       <?= $correctIdx === $i ? 'checked' : '' ?>>
                            </div>
                            <input class="form-control form-control-sm"
                                   name="options[<?= $e->attr((string) $i) ?>][option_text]"
                                   value="<?= $e->attr($optionTexts[$i] ?? '') ?>"
                                <?= $i < 2 ? 'required' : '' ?>>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm" type="submit"><?= $e->html('Save question') ?></button>
                </div>
            </form>
            <form method="post" action="<?= $e->attr($editPath . '/delete') ?>" class="mt-2"
                  onsubmit="return confirm('Delete this question and its options?');">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"><?= $e->html('Delete question') ?></button>
            </form>
        </section>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
