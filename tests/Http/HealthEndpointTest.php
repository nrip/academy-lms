<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Tests\Support\ApplicationFactory;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function testReturnsMinimalOkPayload(): void
    {
        $request = new ServerRequest([], [], 'http://localhost/health', 'GET');
        $request = $request->withHeader('Accept', 'application/json');

        $response = ApplicationFactory::handle($request);
        $body = (string) $response->getBody();
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('request_id', $payload);
        self::assertCount(2, $payload);

        self::assertStringNotContainsString('php', strtolower($body));
        self::assertStringNotContainsString('version', strtolower($body));
        self::assertStringNotContainsString('database', strtolower($body));
        self::assertStringNotContainsString('env', strtolower($body));
        self::assertStringNotContainsString('config', strtolower($body));
    }
}
