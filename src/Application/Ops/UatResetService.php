<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;
use RuntimeException;

/**
 * Safe UAT-only reset: removes UAT/demo seeded rows, preserves schema/migrations.
 * Requires --confirm at the CLI layer and EnvironmentCapability::allowsUatSeedAndReset().
 */
final class UatResetService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnvironmentCapability $capability,
    ) {
    }

    /**
     * @return array{deleted_users: int, deleted_applications: int, deleted_notifications: int, summary: list<string>}
     */
    public function reset(bool $confirmed): array
    {
        if (!$this->capability->allowsUatSeedAndReset()) {
            throw new RuntimeException(
                'uat:reset refused for APP_ENV=' . $this->capability->name() . ' (allowed: local|testing|ci|uat).',
            );
        }
        if (!$confirmed) {
            throw new RuntimeException('uat:reset requires explicit --confirm flag.');
        }

        $pdo = $this->connections->connection();
        $summary = [];
        $domain = '%' . UatSeedService::EMAIL_DOMAIN;
        $marker = UatSeedService::MARKER_PREFIX . '%';

        $userIds = $pdo->prepare('SELECT user_id FROM users WHERE email LIKE :domain');
        $userIds->execute(['domain' => $domain]);
        $ids = array_map('intval', $userIds->fetchAll(PDO::FETCH_COLUMN));

        $deletedNotifications = 0;
        $deletedApplications = 0;

        $pdo->beginTransaction();
        try {
            // Notification samples tied to UAT outbox keys.
            $delNotif = $pdo->exec(
                "DELETE nd FROM notification_deliveries nd
                 INNER JOIN outbox_messages om ON om.outbox_message_id = nd.outbox_message_id
                 WHERE om.idempotency_key LIKE 'uat.seed.%'",
            );
            $deletedNotifications = is_int($delNotif) ? $delNotif : 0;
            $pdo->exec("DELETE FROM outbox_messages WHERE idempotency_key LIKE 'uat.seed.%'");

            if ($ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));

                // Phase 1 learning children must be removed before enrolments (RESTRICT FKs).
                try {
                    $pdo->prepare(
                        "DELETE ce FROM certificate_events ce
                         INNER JOIN certificates c ON c.certificate_id = ce.certificate_id
                         INNER JOIN enrolments e ON e.enrolment_id = c.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                    $pdo->prepare(
                        "DELETE c FROM certificates c
                         INNER JOIN enrolments e ON e.enrolment_id = c.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                } catch (\Throwable) {
                }

                try {
                    $pdo->prepare(
                        "DELETE ar FROM assessment_responses ar
                         INNER JOIN assessment_attempts aa ON aa.attempt_id = ar.attempt_id
                         INNER JOIN enrolments e ON e.enrolment_id = aa.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                    $pdo->prepare(
                        "DELETE aaq FROM assessment_attempt_questions aaq
                         INNER JOIN assessment_attempts aa ON aa.attempt_id = aaq.attempt_id
                         INNER JOIN enrolments e ON e.enrolment_id = aa.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                    $pdo->prepare(
                        "DELETE aah FROM assessment_attempt_status_history aah
                         INNER JOIN assessment_attempts aa ON aa.attempt_id = aah.attempt_id
                         INNER JOIN enrolments e ON e.enrolment_id = aa.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                    $pdo->prepare(
                        "DELETE aa FROM assessment_attempts aa
                         INNER JOIN enrolments e ON e.enrolment_id = aa.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                } catch (\Throwable) {
                }

                try {
                    $pdo->prepare(
                        "DELETE cp FROM content_progress cp
                         INNER JOIN enrolments e ON e.enrolment_id = cp.enrolment_id
                         INNER JOIN applications a ON a.application_id = e.application_id
                         WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                    )->execute([...$ids, $marker]);
                } catch (\Throwable) {
                }

                $pdo->prepare(
                    "DELETE e FROM enrolments e
                     INNER JOIN applications a ON a.application_id = e.application_id
                     WHERE a.user_id IN ($in) OR a.application_number LIKE ?",
                )->execute([...$ids, $marker]);

                $pdo->prepare(
                    "DELETE FROM payments WHERE user_id IN ($in) OR public_reference LIKE ?",
                )->execute([...$ids, $marker]);

                // Document / review children first where present.
                foreach ([
                    'DELETE ds FROM document_submissions ds INNER JOIN applications a ON a.application_id = ds.application_id WHERE a.user_id IN (' . $in . ')',
                    'DELETE ara FROM application_review_assignments ara INNER JOIN applications a ON a.application_id = ara.application_id WHERE a.user_id IN (' . $in . ')',
                ] as $sql) {
                    try {
                        $pdo->prepare($sql)->execute($ids);
                    } catch (\Throwable) {
                        // Table may not exist in partial schemas; ignore.
                    }
                }

                $delApps = $pdo->prepare(
                    "DELETE FROM applications WHERE user_id IN ($in) OR application_number LIKE ?",
                );
                $delApps->execute([...$ids, $marker]);
                $deletedApplications = $delApps->rowCount();

                try {
                    $pdo->prepare(
                        "DELETE FROM course_admin_scope_assignments WHERE admin_user_id IN ($in)",
                    )->execute($ids);
                } catch (\Throwable) {
                }

                try {
                    $pdo->prepare(
                        "DELETE FROM reviewer_scope_assignments WHERE reviewer_user_id IN ($in)",
                    )->execute($ids);
                } catch (\Throwable) {
                }

                try {
                    $pdo->prepare("DELETE FROM learner_profiles WHERE user_id IN ($in)")->execute($ids);
                } catch (\Throwable) {
                }

                $pdo->prepare("DELETE FROM user_roles WHERE user_id IN ($in)")->execute($ids);
                $pdo->prepare("DELETE FROM users WHERE user_id IN ($in)")->execute($ids);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $summary[] = 'removed UAT persona users and related demo rows';
        $summary[] = 'schema and phinxlog preserved';
        $summary[] = 'demo catalogue (WP02-DEMO-* / PHASE1-DEMO-*) retained — re-run phinx seed if needed';

        return [
            'deleted_users' => count($ids),
            'deleted_applications' => $deletedApplications,
            'deleted_notifications' => $deletedNotifications,
            'summary' => $summary,
        ];
    }
}
