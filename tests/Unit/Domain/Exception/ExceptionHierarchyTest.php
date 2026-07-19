<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Exception;

use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @param class-string<DomainException> $class
     */
    #[DataProvider('exceptionProvider')]
    public function testExceptionsExtendDomainException(string $class): void
    {
        $exception = new $class();
        self::assertInstanceOf(DomainException::class, $exception);
    }

    /**
     * @return array<string, array{0: class-string<DomainException>}>
     */
    public static function exceptionProvider(): array
    {
        return [
            'validation' => [ValidationException::class],
            'authentication' => [AuthenticationException::class],
            'authorization' => [AuthorizationException::class],
            'not_found' => [NotFoundException::class],
            'conflict' => [ConflictException::class],
            'domain_rule' => [DomainRuleException::class],
            'external' => [ExternalServiceException::class],
        ];
    }

    public function testValidationExceptionExposesFields(): void
    {
        $exception = new ValidationException('Invalid', ['email' => ['Required']]);
        self::assertSame(['email' => ['Required']], $exception->fields());
    }
}
