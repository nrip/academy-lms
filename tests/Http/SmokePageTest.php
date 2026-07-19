<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Tests\Support\ApplicationFactory;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class SmokePageTest extends TestCase
{
    public function testRendersNeutralSmokePage(): void
    {
        $request = new ServerRequest([], [], 'http://localhost/smoke', 'GET');
        $response = ApplicationFactory::handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Academy LMS', $body);
        self::assertStringContainsString('acad-tokens.css', $body);
        self::assertStringContainsString('acad/app.js', $body);
        self::assertStringContainsString('bootstrap.min.css', $body);
        self::assertStringContainsString('jquery.min.js', $body);
        self::assertStringNotContainsString('datatables', strtolower($body));
        self::assertStringNotContainsString('select2', strtolower($body));
        self::assertStringNotContainsString('sweetalert', strtolower($body));
    }
}
