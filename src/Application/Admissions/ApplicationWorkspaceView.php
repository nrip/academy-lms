<?php

declare(strict_types=1);

namespace Academy\Application\Admissions;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Identity\ProfileCompleteness;

final class ApplicationWorkspaceView
{
    /**
     * @param list<CourseDocumentRequirement> $requirements
     * @param array<int, DocumentSubmission> $currentDocumentsByRequirementId
     * @param list<string> $blockers
     */
    public function __construct(
        public readonly Application $application,
        public readonly array $requirements,
        public readonly array $currentDocumentsByRequirementId,
        public readonly ProfileCompleteness $profileCompleteness,
        public readonly array $blockers,
        public readonly string $requiredDeclarationVersion,
        public readonly bool $declarationAccepted,
    ) {
    }

    public function canSubmit(): bool
    {
        return $this->blockers === [];
    }
}
