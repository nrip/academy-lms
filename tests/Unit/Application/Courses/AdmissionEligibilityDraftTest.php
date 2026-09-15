<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Courses;

use Academy\Application\Courses\AdmissionEligibilityDraft;
use Academy\Application\Courses\LearnerCategoryCatalog;
use Academy\Domain\Courses\EligibilityRule;
use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AdmissionEligibilityDraftTest extends TestCase
{
    public function testKnownCategoriesBecomeACustomerSummaryAndExtraRulesBecomeNotes(): void
    {
        $draft = AdmissionEligibilityDraft::fromRules([
            $this->rule('profession', 'in', 'doctor,nurse', 'Must be a registered doctor or nurse.', 1),
            $this->rule('medical_council_registration_number', 'not_empty', 'true', 'Must hold a valid council registration.', 2),
        ]);

        self::assertSame(['Doctor', 'Nurse'], $draft->selectedLabels());
        self::assertSame(
            "Must be a registered doctor or nurse.\nMust hold a valid council registration.",
            $draft->notesText(),
        );

        $saved = AdmissionEligibilityDraft::fromPosted(
            ['Nurse', 'Doctor'],
            $draft->notesText(),
            $draft,
        );
        $rows = $saved->persistenceRows();

        self::assertSame('profession', $rows[0]['field']);
        self::assertSame('doctor,nurse', $rows[0]['value']);
        self::assertSame('Doctor or Nurse', $rows[0]['display_label']);
        self::assertSame('note', $rows[1]['field']);
        self::assertSame('Must be a registered doctor or nurse.', $rows[1]['display_label']);
        self::assertSame('Must hold a valid council registration.', $rows[2]['display_label']);
    }

    public function testUnknownCategoryTokenIsKeptWithoutBecomingACheckbox(): void
    {
        $draft = AdmissionEligibilityDraft::fromRules([
            $this->rule('profession', 'in', 'doctor,future_category', 'Doctor or a future category.', 1),
        ]);

        self::assertSame(['Doctor'], $draft->selectedLabels());
        self::assertTrue($draft->hasUnlistedCategories());

        $saved = AdmissionEligibilityDraft::fromPosted(['Doctor'], '', $draft);
        self::assertSame('doctor,future_category', $saved->persistenceRows()[0]['value']);
        self::assertSame('Doctor', $saved->persistenceRows()[0]['display_label']);
    }

    public function testRejectsAnUnrecognisedCategory(): void
    {
        $this->expectException(ValidationException::class);
        AdmissionEligibilityDraft::fromPosted(['Physician'], '', AdmissionEligibilityDraft::fromRules([]));
    }

    public function testRejectsAnOverlongNote(): void
    {
        $this->expectException(ValidationException::class);
        AdmissionEligibilityDraft::fromPosted([], str_repeat('a', 256), AdmissionEligibilityDraft::fromRules([]));
    }

    public function testSummaryUsesCustomerLabelsOnly(): void
    {
        self::assertSame(
            'Doctor, Nurse, or Allied medical professional',
            LearnerCategoryCatalog::summary(['nurse', 'allied_professional', 'doctor']),
        );
        self::assertNull(LearnerCategoryCatalog::keyForLabel('allied_professional'));
    }

    private function rule(string $field, string $operator, string $value, string $label, int $sort): EligibilityRule
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new EligibilityRule(1, 1, $field, $operator, $value, 'AND', $label, $sort, $at, $at);
    }
}
