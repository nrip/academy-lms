<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Branding;

use Academy\Application\Branding\AcademyBranding;
use PHPUnit\Framework\TestCase;

final class AcademyBrandingTest extends TestCase
{
    public function testDefaultsMatchPhase1Tokens(): void
    {
        $branding = AcademyBranding::defaults();

        self::assertSame('Academy LMS', $branding->name);
        self::assertSame('/assets/brand/logo.svg', $branding->logoUrl);
        self::assertSame('#0F6E62', $branding->primaryColor);
        self::assertSame('', $branding->supportEmail);
        self::assertSame('Academy LMS', $branding->certificateIssuerName);
        self::assertSame('15, 110, 98', $branding->primaryColorRgb());
    }

    public function testFromConfigAppliesCustomerValues(): void
    {
        $branding = AcademyBranding::fromConfig([
            'name' => 'Contoso CME Academy',
            'logo_url' => '/assets/brand/contoso.svg',
            'primary_color' => '#1a5f9e',
            'support_email' => 'help@contoso.example',
            'certificate_issuer_name' => 'Contoso CME Board',
        ]);

        self::assertSame('Contoso CME Academy', $branding->name);
        self::assertSame('/assets/brand/contoso.svg', $branding->logoUrl);
        self::assertSame('#1A5F9E', $branding->primaryColor);
        self::assertSame('help@contoso.example', $branding->supportEmail);
        self::assertSame('Contoso CME Board', $branding->certificateIssuerName);
        self::assertSame('26, 95, 158', $branding->primaryColorRgb());
    }

    public function testIssuerFallsBackToAcademyName(): void
    {
        $branding = AcademyBranding::fromConfig([
            'name' => 'Demo Academy',
            'certificate_issuer_name' => '',
        ]);

        self::assertSame('Demo Academy', $branding->certificateIssuerName);
    }

    public function testRejectsUnsafeLogoAndInvalidColorAndEmail(): void
    {
        $branding = AcademyBranding::fromConfig([
            'logo_url' => 'javascript:alert(1)',
            'primary_color' => 'red',
            'support_email' => 'not-an-email',
        ]);

        self::assertSame(AcademyBranding::DEFAULT_LOGO_URL, $branding->logoUrl);
        self::assertSame(AcademyBranding::DEFAULT_PRIMARY_COLOR, $branding->primaryColor);
        self::assertSame('', $branding->supportEmail);
    }

    public function testAllowsHttpsLogoUrl(): void
    {
        $branding = AcademyBranding::fromConfig([
            'logo_url' => 'https://cdn.example.test/logo.png',
        ]);

        self::assertSame('https://cdn.example.test/logo.png', $branding->logoUrl);
    }
}
