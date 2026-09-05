<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\Module;
use Academy\Domain\Courses\ModuleReleaseRule;

/**
 * Module/content release for Phase 1 player (immediate vs sequential modules;
 * mandatory predecessors must be completed before later items).
 */
final class ModuleReleasePolicy
{
    /**
     * @param list<Module> $modulesOrdered
     * @param list<ContentItem> $itemsOrdered version order (module sequence, content sequence)
     * @param array<int, ContentProgress|null> $progressByContentId
     */
    public function isModuleUnlocked(
        Module $module,
        array $modulesOrdered,
        array $itemsOrdered,
        array $progressByContentId,
    ): bool {
        if ($module->releaseRule === ModuleReleaseRule::IMMEDIATE) {
            return true;
        }

        $priorModules = [];
        foreach ($modulesOrdered as $candidate) {
            if ($candidate->moduleId === $module->moduleId) {
                break;
            }
            $priorModules[] = $candidate;
        }

        foreach ($priorModules as $prior) {
            if (!$this->isModuleComplete($prior, $itemsOrdered, $progressByContentId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Module> $modulesOrdered
     * @param list<ContentItem> $itemsOrdered
     * @param array<int, ContentProgress|null> $progressByContentId
     */
    public function isContentAccessible(
        ContentItem $target,
        array $modulesOrdered,
        array $itemsOrdered,
        array $progressByContentId,
    ): bool {
        $module = null;
        foreach ($modulesOrdered as $candidate) {
            if ($candidate->moduleId === $target->moduleId) {
                $module = $candidate;
                break;
            }
        }
        if ($module === null) {
            return false;
        }

        if (!$this->isModuleUnlocked($module, $modulesOrdered, $itemsOrdered, $progressByContentId)) {
            return false;
        }

        foreach ($itemsOrdered as $item) {
            if ($item->contentId === $target->contentId) {
                return true;
            }
            if ($item->moduleId !== $target->moduleId) {
                continue;
            }
            if ($item->mandatoryFlag && !$this->isCompleted($item->contentId, $progressByContentId)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param list<ContentItem> $itemsOrdered
     * @param array<int, ContentProgress|null> $progressByContentId
     */
    public function isModuleComplete(
        Module $module,
        array $itemsOrdered,
        array $progressByContentId,
    ): bool {
        $mandatory = array_values(array_filter(
            $itemsOrdered,
            static fn (ContentItem $item): bool => $item->moduleId === $module->moduleId && $item->mandatoryFlag,
        ));
        if ($mandatory === []) {
            return true;
        }

        foreach ($mandatory as $item) {
            if (!$this->isCompleted($item->contentId, $progressByContentId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, ContentProgress|null> $progressByContentId
     */
    private function isCompleted(int $contentId, array $progressByContentId): bool
    {
        $progress = $progressByContentId[$contentId] ?? null;

        return $progress !== null && $progress->isCompleted();
    }
}
