<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

final class LearnerDashboardView
{
    /**
     * @param list<LearnerDashboardCard> $cards
     * @param list<array{label: string, href: string, severity: string}> $requiredActions
     */
    public function __construct(
        public readonly array $cards,
        public readonly array $requiredActions,
        public readonly int $totalApplications,
    ) {
    }
}
