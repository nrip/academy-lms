<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\CourseCoverPolicy;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class CourseCoverPolicyTest extends TestCase
{
    public function testAcceptsPngWithinLimit(): void
    {
        $policy = new CourseCoverPolicy();
        $png = $this->pngBytes();
        $checked = $policy->assertImage($png);

        self::assertSame('image/png', $checked['mime']);
        self::assertSame(strlen($png), $checked['bytes']);
    }

    public function testRejectsNonImage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('JPG, PNG, or WebP');

        (new CourseCoverPolicy())->assertImage('%PDF-1.4 not an image');
    }

    public function testRejectsOversizedImage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('larger than the');

        (new CourseCoverPolicy(1))->assertImage($this->pngBytes());
    }

    public function testRejectsLessonMediaPrefix(): void
    {
        $this->expectException(ValidationException::class);
        (new CourseCoverPolicy())->assertObjectKey('learning/media/cover.png');
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($bytes);

        return $bytes;
    }
}
