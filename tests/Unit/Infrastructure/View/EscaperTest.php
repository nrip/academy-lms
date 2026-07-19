<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\View;

use Academy\Infrastructure\View\Escaper;
use PHPUnit\Framework\TestCase;

final class EscaperTest extends TestCase
{
    public function testHtmlEscapesSpecialCharacters(): void
    {
        $escaper = new Escaper();

        self::assertSame('&lt;script&gt;', $escaper->html('<script>'));
        self::assertSame('a&amp;b', $escaper->attr('a&b'));
    }

    public function testJsEncodesValuesSafely(): void
    {
        $escaper = new Escaper();

        self::assertSame('"hello"', $escaper->js('hello'));
        self::assertSame('{"a":1}', $escaper->js(['a' => 1]));
    }
}
