<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function testLiveReturnsMinimalOkPayload(): void
    {
        $request = new ServerRequest([], [], 'http://localhost/health/live', 'GET');
        $request = $request->withHeader('Accept', 'application/json');

        $response = ApplicationFactory::handle($request);
        $body = (string) $response->getBody();
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('request_id', $payload);
        self::assertCount(2, $payload);
        self::assertStringNotContainsString('database', strtolower($body));
        self::assertStringNotContainsString('password', strtolower($body));
    }

    public function testLegacyHealthAliasRemainsMinimal(): void
    {
        $request = new ServerRequest([], [], 'http://localhost/health', 'GET');
        $request = $request->withHeader('Accept', 'application/json');

        $response = ApplicationFactory::handle($request);
        $body = (string) $response->getBody();
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertCount(2, $payload);
        self::assertStringNotContainsString('php', strtolower($body));
        self::assertStringNotContainsString('config', strtolower($body));
    }

    public function testReadyReportsWithoutSecretsWhenDbAvailable(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();

        $request = new ServerRequest([], [], 'http://localhost/health/ready', 'GET');
        $request = $request->withHeader('Accept', 'application/json');

        $response = ApplicationFactory::handle($request);
        $body = (string) $response->getBody();
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertContains($response->getStatusCode(), [200, 503]);
        self::assertArrayHasKey('ready', $payload);
        self::assertArrayHasKey('checks', $payload);
        self::assertArrayHasKey('build', $payload);
        self::assertArrayHasKey('request_id', $payload);
        self::assertStringNotContainsString('password', strtolower($body));
        self::assertStringNotContainsString('pepper', strtolower($body));
        self::assertStringNotContainsString('secret', strtolower($body));
        self::assertStringNotContainsString('/Users/', $body);
        if ($payload['ready'] === true) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('ready', $payload['status']);
        }
    }
}
