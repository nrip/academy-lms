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
                1,
                'Application submitted — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "We have received your application {{application_number}} for {{course_title}} ({{batch_name}}).\n"
                . "Status: {{status_label}}.\n\n"
                . "View progress: {{dashboard_link}}\n",
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
                1,
                'Application admitted — {{application_number}}',
                "Hello {{learner_display_name}},\n\n"
                . "Application {{application_number}} for {{course_title}} ({{batch_name}}) is admitted.\n"
                . "Status: {{status_label}}.\n\n"
                . "{{dashboard_link}}\n",
            ),
            TransactionalNotificationEventTypes::ENROLMENT_CREATED => $this->def(
                'enrolment_created',
                1,
                'Enrolment confirmed — {{course_title}}',
                "Hello {{learner_display_name}},\n\n"
                . "You are enrolled in {{course_title}} ({{batch_name}}).\n"
                . "Status: {{status_label}}. Course access follows your enrolment status — payment alone is not enough.\n\n"
                . "{{dashboard_link}}\n",
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
