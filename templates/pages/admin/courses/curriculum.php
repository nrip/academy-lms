<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var list<array{module: \Academy\Domain\Courses\Module, content_items: list<\Academy\Domain\Courses\ContentItem>}> $modules */
/** @var bool $editable */
/** @var ?string $error */
/** @var ?string $flash */

$base = '/admin/courses/' . $course->courseId . '/versions/' . $version->versionId;

ob_start();
?>
<div class="acad-admin-curriculum">
    <p class="mb-2">
        <a href="<?= $e->attr($base) ?>"><?= $e->html('← Version overview') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html('Curriculum') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($course->masterTitle) ?>
        · <?= $e->html('Version ' . (string) $version->versionNumber) ?>
        · <?= $e->html($version->isLocked() ? 'Locked (read-only)' : 'Draft (editable)') ?>
    </p>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if (!$editable): ?>
        <div class="alert alert-warning">
            <?= $e->html('This CourseVersion is locked. Curriculum cannot be changed. Create Version N+1 to edit.') ?>
        </div>
    <?php endif; ?>

    <section class="mb-4">
        <h2 class="h5"><?= $e->html('Outline') ?></h2>
        <?php if ($modules === []): ?>
            <p class="text-muted"><?= $e->html('No modules yet. Add Module 1 below to start the curriculum.') ?></p>
        <?php else: ?>
            <ol class="list-group list-group-numbered mb-0">
                <?php foreach ($modules as $node): ?>
                    <?php $module = $node['module']; ?>
                    <li class="list-group-item">
                        <div class="fw-semibold"><?= $e->html($module->title) ?></div>
                        <?php if ($module->description !== ''): ?>
                            <div class="small text-muted"><?= $e->html($module->description) ?></div>
                        <?php endif; ?>
                        <?php if ($node['content_items'] === []): ?>
                            <div class="small text-muted mt-1"><?= $e->html('No content items yet.') ?></div>
                        <?php else: ?>
                            <ul class="mt-2 mb-0">
                                <?php foreach ($node['content_items'] as $item): ?>
                                    <li>
                                        <?= $e->html($item->title) ?>
                                        <span class="text-muted small">(<?= $e->html($item->contentType) ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($editable): ?>
        <section class="mb-5 border-top pt-4">
            <h2 class="h5"><?= $e->html('Add module') ?></h2>
            <form method="post" action="<?= $e->attr($base . '/modules') ?>" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <div class="col-md-6">
                    <label class="form-label" for="module_title"><?= $e->html('Title') ?></label>
                    <input class="form-control" id="module_title" name="title" required maxlength="255"
                           placeholder="e.g. Introduction to Metabolic Health">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="release_rule"><?= $e->html('Release rule') ?></label>
                    <select class="form-select" id="release_rule" name="release_rule">
                        <option value="immediate" selected><?= $e->html('Immediate') ?></option>
                        <option value="sequential"><?= $e->html('Sequential') ?></option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="1" id="module_mandatory" name="mandatory_flag" checked>
                        <label class="form-check-label" for="module_mandatory"><?= $e->html('Mandatory') ?></label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="module_description"><?= $e->html('Description') ?></label>
                    <textarea class="form-control" id="module_description" name="description" rows="2"></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><?= $e->html('Create module') ?></button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php foreach ($modules as $node): ?>
        <?php
        $module = $node['module'];
        $modulePath = $base . '/modules/' . $module->moduleId;
        ?>
        <section class="mb-5 border rounded p-3">
            <h2 class="h5 mb-3">
                <?= $e->html('Module ' . (string) $module->sequence . ': ' . $module->title) ?>
            </h2>

            <?php if ($editable): ?>
                <form method="post" action="<?= $e->attr($modulePath) ?>" class="row g-2 mb-3">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <div class="col-md-5">
                        <label class="form-label" for="mod_title_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Title') ?></label>
                        <input class="form-control" id="mod_title_<?= $e->attr((string) $module->moduleId) ?>"
                               name="title" required value="<?= $e->attr($module->title) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="mod_release_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Release') ?></label>
                        <select class="form-select" id="mod_release_<?= $e->attr((string) $module->moduleId) ?>" name="release_rule">
                            <option value="immediate" <?= $module->releaseRule === 'immediate' ? 'selected' : '' ?>><?= $e->html('Immediate') ?></option>
                            <option value="sequential" <?= $module->releaseRule === 'sequential' ? 'selected' : '' ?>><?= $e->html('Sequential') ?></option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="mod_mand_<?= $e->attr((string) $module->moduleId) ?>" name="mandatory_flag"
                                <?= $module->mandatoryFlag ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mod_mand_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Mandatory') ?></label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="mod_desc_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Description') ?></label>
                        <textarea class="form-control" id="mod_desc_<?= $e->attr((string) $module->moduleId) ?>"
                                  name="description" rows="2"><?= $e->html($module->description) ?></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm" type="submit"><?= $e->html('Save module') ?></button>
                    </div>
                </form>
                <form method="post" action="<?= $e->attr($modulePath . '/delete') ?>" class="mb-4"
                      onsubmit="return confirm('Delete this module? It must have no content items.');">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <button class="btn btn-outline-danger btn-sm" type="submit"><?= $e->html('Delete module') ?></button>
                </form>
            <?php endif; ?>

            <h3 class="h6"><?= $e->html('Content items') ?></h3>
            <?php if ($node['content_items'] === []): ?>
                <p class="text-muted small"><?= $e->html('No lessons in this module yet.') ?></p>
            <?php else: ?>
                <?php foreach ($node['content_items'] as $item): ?>
                    <div class="border-start border-3 ps-3 mb-3">
                        <div class="fw-semibold"><?= $e->html($item->title) ?>
                            <span class="badge text-bg-light"><?= $e->html($item->contentType) ?></span>
                        </div>
                        <?php if ($item->contentType === 'text_lesson' && $item->bodyText !== null): ?>
                            <div class="small mt-1 text-break"><?= $e->html($item->bodyText) ?></div>
                        <?php endif; ?>
                        <?php if ($item->contentType === 'pdf' && $item->objectKey !== null): ?>
                            <div class="small text-muted mt-1"><?= $e->html('Object key: ' . $item->objectKey) ?></div>
                        <?php endif; ?>
                        <?php if ($item->contentType === 'mcq_assessment'): ?>
                            <div class="mt-1">
                                <a class="btn btn-outline-secondary btn-sm"
                                   href="/admin/content-items/<?= $e->attr((string) $item->contentId) ?>/assessment">
                                    <?= $e->html('Configure assessment') ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($editable): ?>
                            <form method="post"
                                  action="<?= $e->attr($modulePath . '/content/' . $item->contentId) ?>"
                                  class="row g-2 mt-2">
                                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                <input type="hidden" name="content_type" value="<?= $e->attr($item->contentType) ?>">
                                <div class="col-md-6">
                                    <label class="form-label"><?= $e->html('Title') ?></label>
                                    <input class="form-control form-control-sm" name="title" required
                                           value="<?= $e->attr($item->title) ?>">
                                </div>
                                <?php if ($item->contentType === 'text_lesson'): ?>
                                    <div class="col-12">
                                        <label class="form-label"><?= $e->html('Lesson body') ?></label>
                                        <textarea class="form-control form-control-sm" name="body_text" rows="4" required><?= $e->html((string) $item->bodyText) ?></textarea>
                                    </div>
                                <?php elseif ($item->contentType === 'pdf'): ?>
                                    <div class="col-md-6">
                                        <label class="form-label"><?= $e->html('Object key') ?></label>
                                        <input class="form-control form-control-sm" name="object_key" required
                                               value="<?= $e->attr((string) $item->objectKey) ?>">
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="completion_rule" value="assessment_passed">
                                    <div class="col-12 small text-muted">
                                        <?= $e->html('MCQ assessment settings are managed on the assessment configuration screen.') ?>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><?= $e->html('Save content') ?></button>
                                </div>
                            </form>
                            <form method="post"
                                  action="<?= $e->attr($modulePath . '/content/' . $item->contentId . '/delete') ?>"
                                  class="mt-1"
                                  onsubmit="return confirm('Delete this content item?');">
                                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                <button class="btn btn-outline-danger btn-sm" type="submit"><?= $e->html('Delete content') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($editable): ?>
                <form method="post" action="<?= $e->attr($modulePath . '/content') ?>" class="row g-2 mt-3 bg-light p-3 rounded">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <div class="col-12"><strong><?= $e->html('Add content item') ?></strong></div>
                    <div class="col-md-3">
                        <label class="form-label" for="ctype_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Type') ?></label>
                        <select class="form-select form-select-sm" id="ctype_<?= $e->attr((string) $module->moduleId) ?>" name="content_type">
                            <option value="text_lesson" selected><?= $e->html('Text lesson') ?></option>
                            <option value="pdf"><?= $e->html('PDF') ?></option>
                            <option value="mcq_assessment"><?= $e->html('MCQ assessment') ?></option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="ctitle_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Title') ?></label>
                        <input class="form-control form-control-sm" id="ctitle_<?= $e->attr((string) $module->moduleId) ?>"
                               name="title" required maxlength="255"
                               placeholder="e.g. Understanding Obesity">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="cbody_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Lesson body (text lessons)') ?></label>
                        <textarea class="form-control form-control-sm" id="cbody_<?= $e->attr((string) $module->moduleId) ?>"
                                  name="body_text" rows="3"
                                  placeholder="Learner-facing lesson text…"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ckey_<?= $e->attr((string) $module->moduleId) ?>"><?= $e->html('Object key (PDF only)') ?></label>
                        <input class="form-control form-control-sm" id="ckey_<?= $e->attr((string) $module->moduleId) ?>"
                               name="object_key" placeholder="s3-object-key-or-demo-ref">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm" type="submit"><?= $e->html('Add content') ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
