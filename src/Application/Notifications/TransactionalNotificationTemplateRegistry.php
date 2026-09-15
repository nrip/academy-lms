<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\NotificationChannel;
use Academy\Domain\Notifications\NotificationTemplateDefinition;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;

/**
 * Code-owned Mode A transactional templates (WP-07). Not DB-editable (SA-04 deferred).
 */
final class TransactionalNotificationTemplateRegistry
{
    private const COMMON_VARS = [
        'learner_display_name',
        'application_number',
        'course_title',
        'batch_name',
        'status_label',
        'safe_reason',
        'dashboard_link',
    ];

    /** @var array<string, NotificationTemplateDefinition> */
    private array $byEventType;

    public function __construct()
    {
        $this->byEventType = [
            TransactionalNotificationEventTypes::APPLICATION_SUBMITTED => $this->def(
                'application_submitted',
                2,
                'Your application has been received',
                "Hello {{learner_display_name}},\n\n"
                . "We have received your application for {{course_title}}.\n"
                . "Application reference: {{application_number}}.\n\n"
                . 'What happens next: we review your documents. You can follow progress from your dashboard. '
                . "You do not need to do anything else unless we ask for a correction.\n",
            ),
            TransactionalNotificationEventTypes::APPLICATION_CORRECTION_REQUESTED => $this->def(
                'application_correction_requested',
                1,
                'Corrections required — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "Corrections are required for application {{application_number}} ({{course_title}}).\n"
                . "{{safe_reason}}\n\n"
                . "Please update your documents: {{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::APPLICATION_CORRECTIONS_RESUBMITTED => $this->def(
                'application_corrections_resubmitted',
                1,
                'Corrections received — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "We have received your corrected documents for {{application_number}} ({{course_title}}).\n"
                . "Status: {{status_label}}.\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::APPLICATION_APPROVED => $this->def(
                'application_approved_payment_pending',
                1,
                'Payment required — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "Your application {{application_number}} for {{course_title}} ({{batch_name}}) is approved.\n"
                . "Status: {{status_label}}. Please complete payment from your dashboard.\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::APPLICATION_REJECTED => $this->def(
                'application_rejected',
                1,
                'Application decision — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "Your application {{application_number}} for {{course_title}} was not approved.\n"
                . "Status: {{status_label}}.\n{{safe_reason}}\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::PAYMENT_FAILED => $this->def(
                'payment_failed',
                1,
                'Payment unsuccessful — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "A payment attempt for application {{application_number}} ({{course_title}}) did not complete.\n"
                . "Status: {{status_label}}. You may retry from your dashboard when eligible.\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::PAYMENT_RECONCILIATION_REQUIRED => $this->def(
                'payment_reconciliation_required',
                1,
                'Payment under verification — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "Your payment for application {{application_number}} ({{course_title}}) is under verification.\n"
                . "Status: {{status_label}}. Enrolment is not confirmed until verification completes.\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::PAYMENT_SUCCESSFUL => $this->def(
                'payment_successful',
                1,
                'Payment received — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "We have recorded a successful payment for application {{application_number}} ({{course_title}}).\n"
                . "Status: {{status_label}}.\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::APPLICATION_ADMITTED => $this->def(
                'application_admitted',
                2,
                'Congratulations! You have been admitted',
                "Hello {{learner_display_name}},\n\n"
                . "You have been admitted to {{course_title}}.\n"
                . "Application reference: {{application_number}}.\n\n"
                . "You can start learning now.\n",
                ['learning_link'],
            ),
            TransactionalNotificationEventTypes::ENROLMENT_CREATED => $this->def(
                'enrolment_created',
                2,
                'Your course is ready',
                "Hello {{learner_display_name}},\n\n"
                . "You are enrolled in {{course_title}} ({{batch_name}}).\n"
                . "Open the course when you are ready to start.\n",
                ['learning_link'],
            ),
            TransactionalNotificationEventTypes::CERTIFICATE_ISSUED => $this->def(
                'certificate_issued',
                2,
                'Your certificate is ready',
                "Hello {{learner_display_name}},\n\n"
                . "Congratulations. Your certificate for {{course_title}} is ready.\n",
                ['certificate_link'],
            ),
            TransactionalNotificationEventTypes::QUESTION_ASKED => $this->def(
                'learning_question_asked',
                1,
                'A learner asked a question',
                "Hello {{learner_display_name}},\n\n"
                . "A learner asked a question about {{lesson_title}} in {{course_title}}.\n"
                . "Chapter: {{chapter_title}}.\n\n"
                . "Open the question to post a response.\n",
                ['chapter_title', 'lesson_title', 'question_link'],
            ),
            TransactionalNotificationEventTypes::QUESTION_RESPONDED => $this->def(
                'learning_question_responded',
                1,
                'Your question has a response',
                "Hello {{learner_display_name}},\n\n"
                . "Your question about {{lesson_title}} in {{course_title}} has a response.\n"
                . "Chapter: {{chapter_title}}.\n\n"
                . "Open the lesson to read it.\n",
                ['chapter_title', 'lesson_title', 'lesson_link'],
            ),
        ];
    }

    public function forEventType(string $eventType): NotificationTemplateDefinition
    {
        if (!isset($this->byEventType[$eventType])) {
            throw new DomainRuleException('No transactional template for event type.');
        }
        $template = $this->byEventType[$eventType];
        if (!$template->active) {
            throw new DomainRuleException('Transactional template is inactive.');
        }

        return $template;
    }

    public function channel(): string
    {
        return NotificationChannel::EMAIL;
    }

    /**
     * @param list<string> $extra
     */
    private function def(string $key, int $version, string $subject, string $body, array $extra = []): NotificationTemplateDefinition
    {
        return new NotificationTemplateDefinition(
            key: $key,
            channel: NotificationChannel::EMAIL,
            version: $version,
            subject: $subject,
            body: $body,
            allowedVariables: array_values(array_unique(array_merge(self::COMMON_VARS, $extra))),
            active: true,
        );
    }
}
