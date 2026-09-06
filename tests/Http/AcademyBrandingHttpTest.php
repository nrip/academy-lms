<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Tests\Support\ApplicationFactory;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class AcademyBrandingHttpTest extends TestCase
{
    /** @var list<string> */
    private array $envKeys = [
        'ACADEMY_NAME',
        'ACADEMY_LOGO_URL',
        'ACADEMY_PRIMARY_COLOR',
        'ACADEMY_SUPPORT_EMAIL',
        'ACADEMY_CERTIFICATE_ISSUER_NAME',
    ];

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        $this->clearBrandingEnv();
    }

    protected function tearDown(): void
    {
        $this->clearBrandingEnv();
    }

    public function testLoginAndCatalogueAndSmokeUseConfiguredBranding(): void
    {
        $this->setBrandingEnv([
            'ACADEMY_NAME' => 'Contoso CME Academy',
            'ACADEMY_LOGO_URL' => '/assets/brand/contoso.svg',
            'ACADEMY_PRIMARY_COLOR' => '#1A5F9E',
            'ACADEMY_SUPPORT_EMAIL' => 'help@contoso.example',
            'ACADEMY_CERTIFICATE_ISSUER_NAME' => 'Contoso CME Board',
        ]);

        $login = ApplicationFactory::handle(new ServerRequest([], [], 'http://localhost/login', 'GET'));
        $loginBody = (string) $login->getBody();
        self::assertSame(200, $login->getStatusCode());
        self::assertStringContainsString('Contoso CME Academy', $loginBody);
        self::assertStringContainsString('/assets/brand/contoso.svg', $loginBody);
        self::assertStringContainsString('--acad-teal: #1A5F9E', $loginBody);
        self::assertStringContainsString('help@contoso.example', $loginBody);

        $smoke = ApplicationFactory::handle(new ServerRequest([], [], 'http://localhost/smoke', 'GET'));
        $smokeBody = (string) $smoke->getBody();
        self::assertSame(200, $smoke->getStatusCode());
        self::assertStringContainsString('Contoso CME Academy', $smokeBody);
        self::assertStringContainsString('--acad-teal: #1A5F9E', $smokeBody);
    }

    /**
     * @param array<string, string> $values
     */
    private function setBrandingEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function clearBrandingEnv(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}
