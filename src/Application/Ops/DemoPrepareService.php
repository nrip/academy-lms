<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

use Academy\Application\Credentials\PhpUploadRuntimeGuard;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * One-command Mode A demo preparation (local|testing|ci|uat only).
 *
 * Orchestrates catalogue seed + UAT personas/scenarios. Does not migrate unless
 * explicitly requested via --migrate. Idempotent when re-run.
 */
final class DemoPrepareService
{
    public function __construct(
        private readonly EnvironmentCapability $capability,
        private readonly UatSeedService $uatSeed,
        private readonly string $projectRoot,
        private readonly string $appUrl,
        private readonly bool $fakePaymentGatewayEnabled,
        private readonly bool $fakeScannerEnabled,
        private readonly string $documentsStorageDriver,
        private readonly string $emailAdapter,
    ) {
    }

    /**
     * @return array{
     *   migrated: bool,
     *   catalogue_seeded: bool,
     *   personas: int,
     *   catalogue: bool,
     *   applications: int,
     *   notifications: int,
     *   summary: list<string>,
     *   credentials: list<array{persona: string, email: string, landing: string}>,
     *   password_source: string,
     *   password: string,
     *   app_url: string,
     *   adapters: list<string>
     * }
     */
    public function prepare(bool $confirmed, bool $migrate = false): array
    {
        if (!$confirmed) {
            throw new RuntimeException(
                'demo:prepare requires --confirm. Refusing to mutate without explicit confirmation.',
            );
        }

        $this->assertAllowed();
        PhpUploadRuntimeGuard::assertAdequateForApplication();

        $summary = [];
        $summary[] = 'php_upload:upload_max_filesize=' . (string) ini_get('upload_max_filesize')
            . ' post_max_size=' . (string) ini_get('post_max_size');
        $migrated = false;
        if ($migrate) {
            $this->runMigrate();
            $migrated = true;
            $summary[] = 'migrate:ok';
        } else {
            $summary[] = 'migrate:skipped (pass --migrate to run Phinx migrations)';
        }

        $this->runCatalogueSeeder();
        $summary[] = 'catalogue_seed:ok';

        $seed = $this->uatSeed->seed();
        foreach ($seed['summary'] as $line) {
            $summary[] = $line;
        }

        if (!$seed['catalogue']) {
            throw new RuntimeException('demo:prepare catalogue still missing after seeder run.');
        }

        $adapters = $this->assertDemoAdapters($summary);
        $password = $this->password();
        $passwordSource = $this->passwordSource();

        return [
            'migrated' => $migrated,
            'catalogue_seeded' => true,
            'personas' => $seed['personas'],
            'catalogue' => $seed['catalogue'],
            'applications' => $seed['applications'],
            'notifications' => $seed['notifications'],
            'summary' => $summary,
            'credentials' => [
                ['persona' => 'Learner', 'email' => 'learner@' . UatSeedService::EMAIL_DOMAIN, 'landing' => '/dashboard'],
                ['persona' => 'Reviewer', 'email' => 'reviewer@' . UatSeedService::EMAIL_DOMAIN, 'landing' => '/reviewer/applications'],
                ['persona' => 'Finance', 'email' => 'finance@' . UatSeedService::EMAIL_DOMAIN, 'landing' => '/finance/reconciliation'],
                // Super Admin includes reviewer permission; resolver precedence lands on reviewer queue.
                // Demo work screen remains /admin/notifications (open from nav).
                ['persona' => 'Notification Operations', 'email' => 'ops@' . UatSeedService::EMAIL_DOMAIN, 'landing' => '/reviewer/applications (then Notifications in nav → /admin/notifications)'],
            ],
            'password_source' => $passwordSource,
            'password' => $password,
            'app_url' => rtrim($this->appUrl, '/'),
            'adapters' => $adapters,
        ];
    }

    private function assertAllowed(): void
    {
        if (!$this->capability->allowsUatSeedAndReset()) {
            throw new RuntimeException(
                'demo:prepare refused for APP_ENV=' . $this->capability->name()
                . ' (allowed: local|testing|ci|uat).',
            );
        }
    }

    /**
     * @param list<string> $summary
     * @return list<string>
     */
    private function assertDemoAdapters(array &$summary): array
    {
        $adapters = [];

        if (!$this->fakePaymentGatewayEnabled) {
            throw new RuntimeException(
                'demo:prepare requires PAYMENTS_FAKE_GATEWAY=1 (FakePaymentGateway) for deterministic demo checkout.',
            );
        }
        $adapters[] = 'payments:fake_gateway';
        $summary[] = 'adapter:payments=fake';

        if ($this->documentsStorageDriver !== 'local') {
            throw new RuntimeException(
                'demo:prepare requires DOCUMENTS_STORAGE_DRIVER=local for browser document uploads.',
            );
        }
        $adapters[] = 'documents:local_storage';
        $summary[] = 'adapter:documents=local';

        if (!$this->fakeScannerEnabled) {
            throw new RuntimeException(
                'demo:prepare requires DOCUMENTS_FAKE_SCANNER=1 for deterministic document scanning.',
            );
        }
        $adapters[] = 'scanner:fake';
        $summary[] = 'adapter:scanner=fake';

        if (!in_array($this->emailAdapter, ['local_file', 'recording'], true)) {
            throw new RuntimeException(
                'demo:prepare requires NOTIFICATION_EMAIL_ADAPTER=local_file or recording.',
            );
        }
        $adapters[] = 'email:' . $this->emailAdapter;
        $summary[] = 'adapter:email=' . $this->emailAdapter;

        return $adapters;
    }

    private function phinxEnvironment(): string
    {
        return match ($this->capability->environment()) {
            AppEnvironment::Testing => 'testing',
            AppEnvironment::Ci => 'ci',
            AppEnvironment::Uat => 'uat',
            default => 'development',
        };
    }

    private function phinxManager(): Manager
    {
        $configArray = require $this->projectRoot . '/phinx.php';
        $configArray['paths']['migrations'] = $this->projectRoot . '/database/migrations';
        $configArray['paths']['seeds'] = $this->projectRoot . '/database/seeds';
        $config = new Config($configArray);
        $output = new BufferedOutput();

        return new Manager($config, new StringInput(''), $output);
    }

    private function runMigrate(): void
    {
        try {
            $this->phinxManager()->migrate($this->phinxEnvironment());
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'demo:prepare migrate failed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    private function runCatalogueSeeder(): void
    {
        try {
            $this->phinxManager()->seed($this->phinxEnvironment(), 'Wp02DemoCatalogueSeeder');
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'demo:prepare catalogue seed failed: ' . $exception->getMessage()
                . ' Ensure MySQL is running and migrations are applied.',
                0,
                $exception,
            );
        }
    }

    private function password(): string
    {
        $fromEnv = getenv(UatSeedService::PASSWORD_ENV) ?: ($_ENV[UatSeedService::PASSWORD_ENV] ?? '');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return UatSeedService::DEFAULT_PASSWORD;
    }

    private function passwordSource(): string
    {
        $fromEnv = getenv(UatSeedService::PASSWORD_ENV) ?: ($_ENV[UatSeedService::PASSWORD_ENV] ?? '');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return UatSeedService::PASSWORD_ENV;
        }

        return 'default (' . UatSeedService::PASSWORD_ENV . ' unset)';
    }
}
