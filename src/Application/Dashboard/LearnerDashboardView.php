<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

use Academy\Domain\Notifications\InAppNotification;

final class LearnerDashboardView
{
    /**
     * @param list<LearnerDashboardCard> $cards
     * @param list<array{label: string, href: string, severity: string}> $requiredActions
     * @param list<LearnerStudyCard> $studyCards
     * @param list<LearnerUpcomingSession> $upcomingSessions
     * @param list<InAppNotification> $recentUpdates
     * @param list<array{courseTitle: string, certificateCount: int, href: string}> $certificateSummaries
     */
    public function __construct(
        public readonly array $cards,
        public readonly array $requiredActions,
        public readonly int $totalApplications,
        public readonly array $studyCards = [],
        public readonly array $upcomingSessions = [],
        public readonly int $unreadUpdates = 0,
        public readonly array $recentUpdates = [],
        public readonly bool $showProfileWelcome = false,
        public readonly array $certificateSummaries = [],
        public readonly int $totalActiveCertificates = 0,
    ) {
    }
}
