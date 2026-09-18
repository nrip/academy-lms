<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only academy console for assigned courses.
 * No payment amounts, payer identity, or document data.
 */
final class CourseOperationsQueryService
{
    /** Operating cohorts. These are existing batch statuses, not a new state. */
    private const ACTIVE_BATCH_STATUSES = [
        BatchStatus::OPEN_FOR_APPLICATIONS,
        BatchStatus::OPEN_FOR_ENROLMENT,
        BatchStatus::FULL,
        BatchStatus::IN_PROGRESS,
    ];

    /**
     * People who still hold a place. Cancelled, withdrawn, refunded, and expired
     * enrolments are not counted. Applications without an enrolment are not counted.
     */
    private const ENROLLED_STATUSES = [
        EnrolmentLifecycleStatus::SCHEDULED,
        EnrolmentLifecycleStatus::ACTIVE,
        EnrolmentLifecycleStatus::SUSPENDED,
    ];

    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseAdminQueryService $adminCourses,
        private readonly CourseVersionRepository $courseVersions,
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function adminHome(AuthContext $auth, ?string $publishedFilter = null): CourseAdminHomeView
    {
        $versionIds = $this->versionIdsInScope($auth);
        $rows = $this->courseRows($auth, $versionIds);
        $published = 0;
        foreach ($rows as $row) {
            if ($row->published) {
                ++$published;
            }
        }
        if ($publishedFilter === 'published') {
            $rows = array_values(array_filter($rows, static fn (CourseAdminCourseRow $row): bool => $row->published));
        } elseif ($publishedFilter === 'draft') {
            $rows = array_values(array_filter($rows, static fn (CourseAdminCourseRow $row): bool => !$row->published));
        }

        return new CourseAdminHomeView(
            totalCourses: count($this->adminCourses->listAssignedCourses($auth)),
            publishedCourses: $published,
            activeBatches: $this->countActiveBatches($versionIds),
            learnersEnrolled: $this->countLearners($versionIds),
            courses: $rows,
            pendingQuestions: $this->countOpenQuestions($versionIds),
            certificatesIssued: $this->countCertificates($versionIds),
            recentActivity: $this->operationalTimeline($versionIds),
        );
    }

    public function facultyHome(AuthContext $auth): FacultyHomeView
    {
        $versionIds = $this->versionIdsInScope($auth);

        return new FacultyHomeView(
            courses: $this->courseRows($auth, $versionIds),
            upcomingSessions: $this->upcomingSessions($versionIds),
            learnersEnrolled: $this->countLearners($versionIds),
            recentActivity: $this->operationalTimeline($versionIds),
        );
    }

    public function courseAnalytics(AuthContext $auth, int $courseId): CourseAnalyticsView
    {
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $versionIds = [];
        foreach ($this->courseVersions->listByCourseId($courseId) as $version) {
            if ($this->access->versionInScope($auth, $courseId, $version->versionId, $at)) {
                $versionIds[] = $version->versionId;
            }
        }

        $learners = $this->countLearners($versionIds);
        $active = $this->countLearnersByStatus($versionIds, [EnrolmentLifecycleStatus::ACTIVE]);
        $certs = $this->countCertificates($versionIds);
        $completionRate = $learners > 0 ? round(($certs / $learners) * 100, 1) : 0.0;
        $assessment = $this->assessmentStats($versionIds);
        $buckets = $this->progressBuckets($versionIds);
        $lessonsCompleted = $this->countCompletedLessons($versionIds);
        $activeWeek = $this->countLearnersActiveSince($versionIds, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-7 days'));

        return new CourseAnalyticsView(
            courseId: $courseId,
            courseTitle: $course->masterTitle,
            learnersEnrolled: $learners,
            activeLearners: $active,
            certificatesIssued: $certs,
            completionRatePercent: $completionRate,
            lessonsCompletedTotal: $lessonsCompleted,
            assessmentAttemptsSubmitted: $assessment['submitted'],
            assessmentAttemptsPassed: $assessment['passed'],
            averageScorePercent: $assessment['avg_score'],
            openQuestions: $this->countOpenQuestions($versionIds),
            learnersActiveLast7Days: $activeWeek,
            progressBuckets: $buckets,
            recentActivity: $this->operationalTimeline($versionIds),
        );
    }

    /**
     * @return array{chapters: int, lessons: int}
     */
    public function outlineCounts(AuthContext $auth, int $courseId, int $versionId): array
    {
        $at = $this->access->nowUtc();
        $this->access->requireCourseInScope($auth, $courseId, $at);
        if (!$this->access->versionInScope($auth, $courseId, $versionId, $at)) {
            return ['chapters' => 0, 'lessons' => 0];
        }

        $pdo = $this->connections->connection();
        $chapters = $pdo->prepare('SELECT COUNT(*) FROM modules WHERE course_version_id = :version_id');
        $chapters->execute(['version_id' => $versionId]);
        $lessons = $pdo->prepare(
            'SELECT COUNT(*) FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             WHERE m.course_version_id = :version_id',
        );
        $lessons->execute(['version_id' => $versionId]);

        return [
            'chapters' => (int) $chapters->fetchColumn(),
            'lessons' => (int) $lessons->fetchColumn(),
        ];
    }

    public function publishReadiness(AuthContext $auth, int $courseId, int $versionId): CoursePublishReadinessChecklist
    {
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        if (!$this->access->versionInScope($auth, $courseId, $versionId, $at)) {
            return new CoursePublishReadinessChecklist([]);
        }

        $version = $this->courseVersions->findById($versionId);
        if ($version === null || $version->courseId !== $courseId) {
            return new CoursePublishReadinessChecklist([]);
        }

        $counts = $this->outlineCounts($auth, $courseId, $versionId);
        $base = '/admin/courses/' . $courseId . '/versions/' . $versionId;
        $infoComplete = trim($version->title) !== ''
            && trim($version->description) !== ''
            && trim($version->learningObjectives) !== ''
            && trim($version->intendedAudience) !== '';
        $feeSet = trim($version->standardFee) !== '';
        $eligibilityCount = $this->countEligibilityRules($versionId);
        $batchCount = $this->countBatchesForVersion($versionId);

        $items = [
            [
                'key' => 'info',
                'label' => 'Course information complete',
                'done' => $infoComplete,
                'required' => true,
                'href' => $base,
                'help' => 'Title, description, objectives, and audience.',
            ],
            [
                'key' => 'cover',
                'label' => 'Cover image added',
                'done' => $course->hasCover(),
                'required' => false,
                'href' => '/admin/courses/' . $courseId,
                'help' => 'Recommended for the public catalogue.',
            ],
            [
                'key' => 'chapters',
                'label' => 'Chapters created',
                'done' => $counts['chapters'] > 0,
                'required' => true,
                'href' => $base . '/curriculum',
                'help' => $counts['chapters'] === 0 ? 'Create your first chapter.' : $counts['chapters'] . ' chapter(s).',
            ],
            [
                'key' => 'lessons',
                'label' => 'Lessons added',
                'done' => $counts['lessons'] > 0,
                'required' => true,
                'href' => $base . '/curriculum',
                'help' => $counts['lessons'] === 0 ? 'Add your first lesson.' : $counts['lessons'] . ' lesson(s).',
            ],
            [
                'key' => 'eligibility',
                'label' => 'Eligibility configured',
                'done' => $eligibilityCount > 0,
                'required' => false,
                'href' => $base . '/admission',
                'help' => 'Profession categories or notes for applicants.',
            ],
            [
                'key' => 'fee',
                'label' => 'Pricing recorded',
                'done' => $feeSet,
                'required' => true,
                'href' => $base . '#pricing',
                'help' => 'Standard fee and GST on the edition form.',
            ],
            [
                'key' => 'batch',
                'label' => 'Batch available',
                'done' => $batchCount > 0,
                'required' => false,
                'href' => $version->isPublished() || $version->isLocked()
                    ? $base . '/batches/new'
                    : $base . '#publish',
                'help' => 'Create a batch after publishing so learners can apply.',
            ],
        ];

        return new CoursePublishReadinessChecklist($items);
    }

    private function countEligibilityRules(int $versionId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM eligibility_rules WHERE course_version_id = :version_id');
        $stmt->execute(['version_id' => $versionId]);

        return (int) $stmt->fetchColumn();
    }

    private function countBatchesForVersion(int $versionId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE course_version_id = :version_id');
        $stmt->execute(['version_id' => $versionId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     */
    private function countOpenQuestions(array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM learning_questions WHERE status = 'open' AND " . $inSql,
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     */
    private function countCertificates(array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM certificates c
             INNER JOIN enrolments e ON e.enrolment_id = c.enrolment_id
             WHERE c.status = 'active' AND " . $inSql,
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     * @param list<string> $statuses
     */
    private function countLearnersByStatus(array $versionIds, array $statuses): int
    {
        if ($versionIds === [] || $statuses === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        [$statusSql, $statusParams] = $this->statusInClause('e.lifecycle_status', $statuses);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT e.user_id) FROM enrolments e WHERE ' . $inSql . ' AND ' . $statusSql,
        );
        $stmt->execute($params + $statusParams);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     * @return array{submitted: int, passed: int, avg_score: ?float}
     */
    private function assessmentStats(array $versionIds): array
    {
        if ($versionIds === []) {
            return ['submitted' => 0, 'passed' => 0, 'avg_score' => null];
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS submitted,
                    COALESCE(SUM(CASE WHEN aa.passed_flag = 1 THEN 1 ELSE 0 END), 0) AS passed,
                    AVG(aa.score_percent) AS avg_score
             FROM assessment_attempts aa
             INNER JOIN enrolments e ON e.enrolment_id = aa.enrolment_id
             WHERE aa.status = 'submitted' AND " . $inSql,
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $avg = $row['avg_score'] ?? null;

        return [
            'submitted' => (int) ($row['submitted'] ?? 0),
            'passed' => (int) ($row['passed'] ?? 0),
            'avg_score' => $avg !== null ? round((float) $avg, 1) : null,
        ];
    }

    /**
     * @param list<int> $versionIds
     */
    private function countCompletedLessons(array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM content_progress cp
             INNER JOIN enrolments e ON e.enrolment_id = cp.enrolment_id
             WHERE cp.completion_status = 'completed' AND " . $inSql,
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     */
    private function countLearnersActiveSince(array $versionIds, DateTimeImmutable $since): int
    {
        if ($versionIds === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        $params['since'] = $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT e.user_id) FROM content_progress cp
             INNER JOIN enrolments e ON e.enrolment_id = cp.enrolment_id
             WHERE cp.last_accessed_at IS NOT NULL AND cp.last_accessed_at >= :since AND ' . $inSql,
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     * @return list<array{label: string, count: int}>
     */
    private function progressBuckets(array $versionIds): array
    {
        $buckets = [
            ['label' => 'Not started', 'count' => 0],
            ['label' => '1–25%', 'count' => 0],
            ['label' => '26–50%', 'count' => 0],
            ['label' => '51–75%', 'count' => 0],
            ['label' => '76–99%', 'count' => 0],
            ['label' => 'Complete', 'count' => 0],
        ];
        if ($versionIds === []) {
            return $buckets;
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT e.enrolment_id,
                    COALESCE(SUM(CASE WHEN cp.completion_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                    (
                        SELECT COUNT(*) FROM content_items ci
                        INNER JOIN modules m ON m.module_id = ci.module_id
                        WHERE m.course_version_id = e.course_version_id
                    ) AS total
             FROM enrolments e
             LEFT JOIN content_progress cp ON cp.enrolment_id = e.enrolment_id
             WHERE " . $inSql . ' AND e.lifecycle_status IN (
                \'scheduled\', \'active\', \'suspended\'
             )
             GROUP BY e.enrolment_id, e.course_version_id',
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total = (int) $row['total'];
            $completed = (int) $row['completed'];
            if ($total <= 0 || $completed <= 0) {
                ++$buckets[0]['count'];
                continue;
            }
            $pct = (int) floor(($completed / $total) * 100);
            if ($pct >= 100) {
                ++$buckets[5]['count'];
            } elseif ($pct >= 76) {
                ++$buckets[4]['count'];
            } elseif ($pct >= 51) {
                ++$buckets[3]['count'];
            } elseif ($pct >= 26) {
                ++$buckets[2]['count'];
            } else {
                ++$buckets[1]['count'];
            }
        }

        return $buckets;
    }

    /**
     * Operational timeline from existing domain tables + selected audit actions.
     *
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function operationalTimeline(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        $items = array_merge(
            $this->recentAdmissions($versionIds),
            $this->recentLessons($versionIds),
            $this->recentCertificates($versionIds),
            $this->recentQuestionResponses($versionIds),
            $this->recentPublishes($versionIds),
        );
        usort($items, static fn (FacultyActivityItem $left, FacultyActivityItem $right): int => $right->at <=> $left->at);

        return array_slice($items, 0, 12);
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function recentCertificates(array $versionIds): array
    {
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT c.issued_at, e.course_id, co.master_title, c.certificate_label
             FROM certificates c
             INNER JOIN enrolments e ON e.enrolment_id = c.enrolment_id
             INNER JOIN courses co ON co.course_id = e.course_id
             WHERE c.status = 'active' AND " . $inSql . '
             ORDER BY c.issued_at DESC, c.certificate_id DESC
             LIMIT 8',
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new FacultyActivityItem(
                label: 'Certificate issued: ' . (string) $row['certificate_label'] . ' · ' . (string) $row['master_title'],
                at: new DateTimeImmutable((string) $row['issued_at'], new DateTimeZone('UTC')),
                href: '/admin/courses/' . (int) $row['course_id'] . '/analytics',
            );
        }

        return $items;
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function recentQuestionResponses(array $versionIds): array
    {
        [$inSql, $params] = $this->intInClause('q.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT r.responded_at, q.course_id, c.master_title, ci.title AS lesson_title, q.question_id
             FROM learning_question_responses r
             INNER JOIN learning_questions q ON q.question_id = r.question_id
             INNER JOIN courses c ON c.course_id = q.course_id
             INNER JOIN content_items ci ON ci.content_id = q.content_id
             WHERE ' . $inSql . '
             ORDER BY r.responded_at DESC, r.response_id DESC
             LIMIT 8',
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new FacultyActivityItem(
                label: 'Question response · ' . (string) $row['lesson_title'] . ' · ' . (string) $row['master_title'],
                at: new DateTimeImmutable((string) $row['responded_at'], new DateTimeZone('UTC')),
                href: '/faculty/questions/' . (int) $row['question_id'],
            );
        }

        return $items;
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function recentPublishes(array $versionIds): array
    {
        [$inSql, $params] = $this->intInClause('cv.version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "SELECT cv.published_at, cv.course_id, c.master_title, cv.version_number
             FROM course_versions cv
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE cv.published_at IS NOT NULL AND " . $inSql . '
             ORDER BY cv.published_at DESC, cv.version_id DESC
             LIMIT 8',
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new FacultyActivityItem(
                label: 'Edition ' . (int) $row['version_number'] . ' published · ' . (string) $row['master_title'],
                at: new DateTimeImmutable((string) $row['published_at'], new DateTimeZone('UTC')),
                href: '/admin/courses/' . (int) $row['course_id'],
            );
        }

        return $items;
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function recentActivity(array $versionIds): array
    {
        return $this->operationalTimeline($versionIds);
    }

    /**
     * @return list<int>
     */
    private function versionIdsInScope(AuthContext $auth): array
    {
        $this->access->requirePermission($auth, 'course.view_assigned');
        $at = $this->access->nowUtc();
        $ids = [];
        foreach ($this->adminCourses->listAssignedCourses($auth) as $course) {
            foreach ($this->courseVersions->listByCourseId($course->courseId) as $version) {
                if ($this->access->versionInScope($auth, $course->courseId, $version->versionId, $at)) {
                    $ids[] = $version->versionId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $versionIds
     * @return list<CourseAdminCourseRow>
     */
    private function courseRows(AuthContext $auth, array $versionIds): array
    {
        $learnerCounts = $this->countsByCourse($versionIds, 'learners');
        $nextBatches = $this->nextBatchNames($versionIds);
        $rows = [];
        foreach ($this->adminCourses->listAssignedCourses($auth) as $course) {
            $rows[] = new CourseAdminCourseRow(
                courseId: $course->courseId,
                title: $course->masterTitle,
                code: $course->courseCode,
                published: $course->currentPublishedVersionId !== null,
                nextBatchName: $nextBatches[$course->courseId] ?? null,
                learnerCount: $learnerCounts[$course->courseId] ?? 0,
            );
        }

        return $rows;
    }

    /**
     * @param list<int> $versionIds
     */
    private function countActiveBatches(array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('b.course_version_id', $versionIds);
        [$statusSql, $statusParams] = $this->statusInClause('b.status', self::ACTIVE_BATCH_STATUSES);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM batches b WHERE ' . $inSql . ' AND ' . $statusSql);
        $stmt->execute($params + $statusParams);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     */
    private function countLearners(array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        [$statusSql, $statusParams] = $this->statusInClause('e.lifecycle_status', self::ENROLLED_STATUSES);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT e.user_id) FROM enrolments e WHERE ' . $inSql . ' AND ' . $statusSql,
        );
        $stmt->execute($params + $statusParams);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $versionIds
     * @return array<int, int>
     */
    private function countsByCourse(array $versionIds, string $kind): array
    {
        if ($versionIds === [] || $kind !== 'learners') {
            return [];
        }
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        [$statusSql, $statusParams] = $this->statusInClause('e.lifecycle_status', self::ENROLLED_STATUSES);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT e.course_id, COUNT(DISTINCT e.user_id) AS learner_count
             FROM enrolments e
             WHERE ' . $inSql . ' AND ' . $statusSql . '
             GROUP BY e.course_id',
        );
        $stmt->execute($params + $statusParams);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['course_id']] = (int) $row['learner_count'];
        }

        return $counts;
    }

    /**
     * @param list<int> $versionIds
     * @return array<int, string>
     */
    private function nextBatchNames(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        [$inSql, $params] = $this->intInClause('b.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT cv.course_id, b.name
             FROM batches b
             INNER JOIN course_versions cv ON cv.version_id = b.course_version_id
             WHERE ' . $inSql . "
               AND b.status NOT IN ('completed', 'cancelled', 'archived')
             ORDER BY b.starts_at ASC, b.batch_id ASC",
        );
        $stmt->execute($params);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $courseId = (int) $row['course_id'];
            if (!isset($names[$courseId])) {
                $names[$courseId] = (string) $row['name'];
            }
        }

        return $names;
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyUpcomingSession>
     */
    private function upcomingSessions(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        [$inSql, $params] = $this->intInClause('m.course_version_id', $versionIds);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $params['now'] = $now->format('Y-m-d H:i:s');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT c.course_id, c.master_title, cv.version_id, m.title AS chapter_title,
                    ci.title AS lesson_title, ci.live_starts_at
             FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE ci.content_type = \'live_session\'
               AND ci.live_starts_at IS NOT NULL
               AND ci.live_starts_at >= :now
               AND ' . $inSql . '
             ORDER BY ci.live_starts_at ASC, ci.content_id ASC
             LIMIT 8',
        );
        $stmt->execute($params);
        $sessions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sessions[] = new FacultyUpcomingSession(
                courseTitle: (string) $row['master_title'],
                chapterTitle: (string) $row['chapter_title'],
                lessonTitle: (string) $row['lesson_title'],
                startsAt: new DateTimeImmutable((string) $row['live_starts_at'], new DateTimeZone('UTC')),
                href: '/admin/courses/' . (int) $row['course_id'] . '/versions/' . (int) $row['version_id'] . '/curriculum',
            );
        }

        return $sessions;
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function recentAdmissions(array $versionIds): array
    {
        [$inSql, $params] = $this->intInClause('e.course_version_id', $versionIds);
        [$statusSql, $statusParams] = $this->statusInClause('e.lifecycle_status', self::ENROLLED_STATUSES);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT e.admitted_at, e.course_id, c.master_title, b.name AS batch_name
             FROM enrolments e
             INNER JOIN courses c ON c.course_id = e.course_id
             INNER JOIN batches b ON b.batch_id = e.batch_id
             WHERE ' . $inSql . ' AND ' . $statusSql . '
             ORDER BY e.admitted_at DESC, e.enrolment_id DESC
             LIMIT 8',
        );
        $stmt->execute($params + $statusParams);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new FacultyActivityItem(
                label: 'A learner was admitted to ' . (string) $row['master_title'] . ' (' . (string) $row['batch_name'] . ')',
                at: new DateTimeImmutable((string) $row['admitted_at'], new DateTimeZone('UTC')),
                href: '/admin/courses/' . (int) $row['course_id'],
            );
        }

        return $items;
    }

    /**
     * @param list<int> $versionIds
     * @return list<FacultyActivityItem>
     */
    private function recentLessons(array $versionIds): array
    {
        [$inSql, $params] = $this->intInClause('m.course_version_id', $versionIds);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ci.title AS lesson_title, ci.updated_at, m.title AS chapter_title,
                    c.course_id, c.master_title, cv.version_id
             FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE ' . $inSql . '
             ORDER BY ci.updated_at DESC, ci.content_id DESC
             LIMIT 8',
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new FacultyActivityItem(
                label: 'Lesson updated: ' . (string) $row['lesson_title'] . ' in ' . (string) $row['chapter_title'],
                at: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
                href: '/admin/courses/' . (int) $row['course_id'] . '/versions/' . (int) $row['version_id'] . '/curriculum',
            );
        }

        return $items;
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function intInClause(string $column, array $ids): array
    {
        $holders = [];
        $params = [];
        foreach (array_values(array_unique($ids)) as $index => $id) {
            $key = 'id' . $index . '_' . substr(md5($column), 0, 4);
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }

        return [$column . ' IN (' . implode(', ', $holders) . ')', $params];
    }

    /**
     * @param list<string> $statuses
     * @return array{0: string, 1: array<string, string>}
     */
    private function statusInClause(string $column, array $statuses): array
    {
        $holders = [];
        $params = [];
        foreach (array_values($statuses) as $index => $status) {
            $key = 'st' . $index . '_' . substr(md5($column), 0, 4);
            $holders[] = ':' . $key;
            $params[$key] = $status;
        }

        return [$column . ' IN (' . implode(', ', $holders) . ')', $params];
    }
}
