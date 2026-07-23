<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

/**
 * REQ-REV-1 fixed rejection / resubmission reason codes.
 */
final class DocumentRejectionReasonCode
{
    public const BLURRY_ILLEGIBLE = 'blurry_illegible';
    public const WRONG_DOCUMENT = 'wrong_document';
    public const INCOMPLETE = 'incomplete';
    public const EXPIRED_REGISTRATION = 'expired_registration';
    public const NAME_MISMATCH = 'name_mismatch';
    public const QUALIFICATION_INELIGIBLE = 'qualification_ineligible';
    public const REGISTRATION_NUMBER_NOT_VISIBLE = 'registration_number_not_visible';
    public const ISSUING_AUTHORITY_NOT_IDENTIFIABLE = 'issuing_authority_not_identifiable';
    public const OTHER = 'other';

    /** @var list<string> */
    public const ALL = [
        self::BLURRY_ILLEGIBLE,
        self::WRONG_DOCUMENT,
        self::INCOMPLETE,
        self::EXPIRED_REGISTRATION,
        self::NAME_MISMATCH,
        self::QUALIFICATION_INELIGIBLE,
        self::REGISTRATION_NUMBER_NOT_VISIBLE,
        self::ISSUING_AUTHORITY_NOT_IDENTIFIABLE,
        self::OTHER,
    ];

    /** @var array<string, string> */
    private const LABELS = [
        self::BLURRY_ILLEGIBLE => 'Blurry/Illegible',
        self::WRONG_DOCUMENT => 'Wrong Document',
        self::INCOMPLETE => 'Incomplete',
        self::EXPIRED_REGISTRATION => 'Expired Registration',
        self::NAME_MISMATCH => 'Name Mismatch',
        self::QUALIFICATION_INELIGIBLE => "Qualification Doesn't Meet Eligibility",
        self::REGISTRATION_NUMBER_NOT_VISIBLE => 'Registration Number Not Visible',
        self::ISSUING_AUTHORITY_NOT_IDENTIFIABLE => 'Issuing Authority Not Identifiable',
        self::OTHER => 'Other',
    ];

    public static function assertValid(string $code): void
    {
        if (!in_array($code, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid document rejection reason code.');
        }
    }

    public static function label(string $code): string
    {
        self::assertValid($code);

        return self::LABELS[$code];
    }
}
