<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Notifications;

use Academy\Application\Notifications\NotificationTemplateRenderer;
use Academy\Application\Notifications\TransactionalNotificationTemplateRegistry;
use Academy\Domain\Audit\NotificationAuditPayload;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\NotificationDeliveryIdempotency;
use Academy\Domain\Notifications\NotificationFailureCategory;
use Academy\Domain\Notifications\NotificationRetryPolicy;
use Academy\Domain\Notifications\NotificationTemplateDefinition;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use PHPUnit\Framework\TestCase;

final class TransactionalNotificationUnitTest extends TestCase
{
    public function testTemplateVariableAllowListRejectsUnknown(): void
    {
        $template = new NotificationTemplateDefinition(
            't',
            'email',
            1,
            'Hi {{learner_display_name}}',
            'Body {{secret}}',
            ['learner_display_name'],
        );
        $renderer = new NotificationTemplateRenderer();
        $this->expectException(DomainRuleException::class);
        $renderer->render($template, ['learner_display_name' => 'Ada', 'secret' => 'nope']);
    }

    public function testTemplatePlaceholderInjectionBlockedByEscapingBraces(): void
    {
        $registry = new TransactionalNotificationTemplateRegistry();
        $template = $registry->forEventType(TransactionalNotificationEventTypes::APPLICATION_SUBMITTED);
        $renderer = new NotificationTemplateRenderer();
        $rendered = $renderer->render($template, [
            'learner_display_name' => 'Ada {{course_title}}',
            'application_number' => 'APP-1',
            'course_title' => 'Course',
            'batch_name' => 'Batch',
            'status_label' => 'Under review',
            'safe_reason' => '',
            'dashboard_link' => 'http://localhost/dashboard',
        ]);
        self::assertStringContainsString('Ada ((course_title))', $rendered['body']);
        self::assertStringNotContainsString('{{course_title}}', $rendered['body']);
    }

    public function testRegistryCoversModeAEvents(): void
    {
        $registry = new TransactionalNotificationTemplateRegistry();
        foreach (TransactionalNotificationEventTypes::all() as $eventType) {
            $template = $registry->forEventType($eventType);
            self::assertTrue($template->active);
            self::assertSame('email', $template->channel);
        }
    }

    public function testRetryPolicyDeadLetterAndBackoff(): void
    {
        $policy = new NotificationRetryPolicy(3, 30, 120);
        self::assertTrue($policy->shouldDeadLetter(1, NotificationFailureCategory::INVALID_RECIPIENT));
        self::assertFalse($policy->shouldDeadLetter(1, NotificationFailureCategory::TIMEOUT));
        self::assertTrue($policy->shouldDeadLetter(3, NotificationFailureCategory::TIMEOUT));
        self::assertSame(30, $policy->backoffSeconds(1));
        self::assertSame(60, $policy->backoffSeconds(2));
        self::assertSame(120, $policy->backoffSeconds(4));
    }

    public function testDeliveryIdempotencyKey(): void
    {
        self::assertSame(
            '9:email:application_submitted',
            NotificationDeliveryIdempotency::key(9, 'email', 'application_submitted'),
        );
    }

    public function testAuditPayloadAllowList(): void
    {
        new NotificationAuditPayload(
            'notification.delivered',
            'notification_delivery',
            '1',
            next: [
                'delivery_id' => 1,
                'channel' => 'email',
                'template_key' => 'application_submitted',
                'template_version' => 1,
                'status' => 'delivered',
                'attempt_count' => 1,
            ],
        );
        $this->expectException(\InvalidArgumentException::class);
        new NotificationAuditPayload(
            'notification.delivered',
            'notification_delivery',
            '1',
            next: ['body' => 'secret'],
        );
    }
}
