<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

/**
 * Learner-facing status presentation (label, explanation, next action, severity).
 */
final class LearnerStatusView
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $explanation,
        public readonly string $nextActionCode,
        public readonly ?string $nextActionLabel,
        public readonly string $severity,
    ) {
    }
}
