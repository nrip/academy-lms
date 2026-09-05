<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Assessments;

use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentPublishCompletenessValidator;
use Academy\Domain\Assessments\AssessmentQuestionLink;
use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AssessmentPublishCompletenessValidatorTest extends TestCase
{
    public function testMissingAssessmentIsBlocked(): void
    {
        $validator = new AssessmentPublishCompletenessValidator();
        $blockers = $validator->blockers(null, []);
        self::assertNotSame([], $blockers);
    }

    public function testInsufficientLinksAreBlocked(): void
    {
        $validator = new AssessmentPublishCompletenessValidator();
        $assessment = $this->assessment(3);
        $links = [$this->link(1), $this->link(2)];
        $this->expectException(ValidationException::class);
        $validator->assertReady($assessment, $links);
    }

    public function testEnoughLinksPass(): void
    {
        $validator = new AssessmentPublishCompletenessValidator();
        $assessment = $this->assessment(2);
        $links = [$this->link(1), $this->link(2)];
        self::assertSame([], $validator->blockers($assessment, $links));
    }

    private function assessment(int $questionsPerAttempt): Assessment
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Assessment(
            assessmentId: 1,
            contentId: 10,
            title: 'Module quiz',
            questionsPerAttempt: $questionsPerAttempt,
            passThresholdPercent: '60.00',
            timeLimitSeconds: null,
            maxAttempts: 3,
            cooldownSeconds: null,
            randomiseQuestions: false,
            randomiseOptions: false,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function link(int $questionId): AssessmentQuestionLink
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new AssessmentQuestionLink(
            linkId: $questionId,
            assessmentId: 1,
            questionId: $questionId,
            sequence: $questionId,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
