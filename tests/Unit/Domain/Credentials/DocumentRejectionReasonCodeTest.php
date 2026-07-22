<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Credentials;

use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentRejectionReasonCodeTest extends TestCase
{
    #[DataProvider('validCodes')]
    public function testAllCodesAreValid(string $code): void
    {
        DocumentRejectionReasonCode::assertValid($code);
        self::assertNotSame('', DocumentRejectionReasonCode::label($code));
    }

    public function testAllConstantContainsEveryCode(): void
    {
        self::assertCount(count(DocumentRejectionReasonCode::ALL), array_unique(DocumentRejectionReasonCode::ALL));
    }

    public function testInvalidCodeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentRejectionReasonCode::assertValid('not_a_real_code');
    }

    public function testLabelOnInvalidCodeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentRejectionReasonCode::label('forged');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function validCodes(): array
    {
        return array_map(static fn (string $code): array => [$code], DocumentRejectionReasonCode::ALL);
    }
}
