<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;

final class LearningQuestionBodyPolicy
{
    public const MAX_CHARS = 2000;

    public static function normalize(string $body): string
    {
        $body = trim(str_replace("\0", '', $body));
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if ($body === '') {
            throw new ValidationException('Enter a question.');
        }
        if (mb_strlen($body) > self::MAX_CHARS) {
            throw new ValidationException('Keep the question within ' . self::MAX_CHARS . ' characters.');
        }

        return $body;
    }

    public static function normalizeResponse(string $body): string
    {
        $body = trim(str_replace("\0", '', $body));
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if ($body === '') {
            throw new ValidationException('Enter a response.');
        }
        if (mb_strlen($body) > self::MAX_CHARS) {
            throw new ValidationException('Keep the response within ' . self::MAX_CHARS . ' characters.');
        }

        return $body;
    }

    public static function assertCanRespond(LearningQuestion $question): void
    {
        if ($question->isClosed()) {
            throw new DomainRuleException('This question is closed.');
        }
    }

    public static function assertCanClose(LearningQuestion $question): void
    {
        if ($question->isClosed()) {
            throw new DomainRuleException('This question is already closed.');
        }
    }
}
