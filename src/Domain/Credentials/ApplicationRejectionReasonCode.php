<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

/**
 * Application-level rejection uses the same REQ-REV-1 catalogue as documents.
 */
final class ApplicationRejectionReasonCode
{
    /** @var list<string> */
    public const ALL = DocumentRejectionReasonCode::ALL;

    public static function assertValid(string $code): void
    {
        DocumentRejectionReasonCode::assertValid($code);
    }

    public static function label(string $code): string
    {
        return DocumentRejectionReasonCode::label($code);
    }
}
