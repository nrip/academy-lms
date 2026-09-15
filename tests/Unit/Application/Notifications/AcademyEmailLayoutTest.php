<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Notifications;

use Academy\Application\Branding\AcademyBranding;
use Academy\Application\Notifications\AcademyEmailLayout;
use Academy\Application\Notifications\NotificationTemplateRenderer;
use Academy\Application\Notifications\TransactionalNotificationTemplateRegistry;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\LearnerInboxCopy;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use PHPUnit\Framework\TestCase;

final class AcademyEmailLayoutTest extends TestCase
{
    private AcademyEmailLayout $layout;

    protected function setUp(): void
    {
        $this->layout = new AcademyEmailLayout(
            new AcademyBranding(
                'Northwind Academy',
                'https://cdn.example.test/logo.png',
                '#0F6E62',
                'hello@northwind.test',
                'Northwind Academy',
            ),
            'https://learn.example.test',
        );
    }

    public function testVerificationLetterHidesTheTokenBehindAButton(): void
    {
        $token = str_repeat('ab', 32);
        $letter = $this->layout->verification($token);

        self::assertSame('Verify your Academy account', $letter['subject']);
        self::assertStringContainsString('Welcome to Northwind Academy.', $letter['text']);
        self::assertStringContainsString('Confirm your email address', $letter['text']);
        self::assertStringContainsString('https://learn.example.test/verify-email?token=' . $token, $letter['text']);
        self::assertDoesNotMatchRegularExpression('/\Atoken=/', $letter['text']);

        $visible = strip_tags($letter['html']);
        self::assertStringContainsString('Verify email', $visible);
        self::assertStringContainsString('Northwind Academy', $visible);
        self::assertStringNotContainsString($token, $visible);
        self::assertStringContainsString('https://cdn.example.test/logo.png', $letter['html']);
        self::assertStringContainsString('hello@northwind.test', $letter['html']);
        self::assertStringContainsString('href="https://learn.example.test/verify-email?token=' . $token . '"', $letter['html']);
    }

    public function testRejectsATokenThatIsNotALinkSecret(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->layout->verification('not a token');
    }

    public function testRequestedLettersRenderFactsAndMatchingInboxCopy(): void
    {
        $registry = new TransactionalNotificationTemplateRegistry();
        $renderer = new NotificationTemplateRenderer();
        $cases = [
            TransactionalNotificationEventTypes::APPLICATION_SUBMITTED => [
                'subject' => 'Your application has been received',
                'facts' => ['Ada Lovelace', 'Metabolic Health', 'APP-100'],
                'cta' => 'View your application',
                'href' => '/dashboard',
            ],
            TransactionalNotificationEventTypes::APPLICATION_ADMITTED => [
                'subject' => 'Congratulations! You have been admitted',
                'facts' => ['Ada Lovelace', 'Metabolic Health', 'APP-100'],
                'cta' => 'Start learning',
                'href' => '/learning/enrolments/9',
            ],
            TransactionalNotificationEventTypes::CERTIFICATE_ISSUED => [
                'subject' => 'Your certificate is ready',
                'facts' => ['Ada Lovelace', 'Metabolic Health'],
                'cta' => 'View your certificate',
                'href' => '/certificates/4',
            ],
        ];

        foreach ($cases as $eventType => $expected) {
            $template = $registry->forEventType($eventType);
            $variables = $this->variables($eventType);
            $rendered = $renderer->render($template, $variables);
            $letter = $this->layout->wrap($template->key, $rendered['body'], $variables);

            self::assertSame($expected['subject'], $rendered['subject'], $eventType);
            foreach ($expected['facts'] as $fact) {
                self::assertStringContainsString($fact, $rendered['body'], $eventType);
                self::assertStringContainsString($fact, $letter['text'], $eventType);
                self::assertStringContainsString($fact, strip_tags($letter['html']), $eventType);
            }
            self::assertStringContainsString($expected['cta'], strip_tags($letter['html']), $eventType);
            self::assertStringNotContainsString('<html', $letter['text'], $eventType);

            $copy = LearnerInboxCopy::fromRender(7, 11, $eventType, $rendered['subject'], $letter['text'], $variables);
            self::assertSame($rendered['subject'], $copy->title, $eventType);
            self::assertSame($letter['text'], $copy->body, $eventType);
            self::assertSame($expected['href'], $copy->href, $eventType);
            self::assertStringNotContainsString('<', $copy->body, $eventType);
        }
    }

    /**
     * @return array<string, string>
     */
    private function variables(string $eventType): array
    {
        $variables = [
            'learner_display_name' => 'Ada Lovelace',
            'application_number' => 'APP-100',
            'course_title' => 'Metabolic Health',
            'batch_name' => 'October',
            'status_label' => 'Received',
            'safe_reason' => '',
            'dashboard_link' => 'https://learn.example.test/dashboard',
        ];
        if ($eventType === TransactionalNotificationEventTypes::CERTIFICATE_ISSUED) {
            $variables['certificate_link'] = 'https://learn.example.test/certificates/4';
        }
        if (
            $eventType === TransactionalNotificationEventTypes::APPLICATION_ADMITTED
            || $eventType === TransactionalNotificationEventTypes::ENROLMENT_CREATED
        ) {
            $variables['learning_link'] = 'https://learn.example.test/learning/enrolments/9';
        }

        return $variables;
    }
}
