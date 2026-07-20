<?php

declare(strict_types=1);

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Application\Security\SessionService;
use Academy\Domain\Audit\AuditWriter;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Domain\Outbox\OutboxTransport;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Security\RateLimitStore;
use Academy\Domain\Security\SessionRepository;
use Academy\Http\Controllers\HealthController;
use Academy\Http\Controllers\SmokeController;
use Academy\Http\Controllers\Wp01aProbeController;
use Academy\Http\Kernel;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\CsrfMiddleware;
use Academy\Http\Middleware\ExceptionHandlerMiddleware;
use Academy\Http\Middleware\RateLimitMiddleware;
use Academy\Http\Middleware\RequestIdMiddleware;
use Academy\Http\Middleware\SecurityHeadersMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Middleware\TrustedProxyMiddleware;
use Academy\Http\Routing\RouteRequestHandler;
use Academy\Http\Security\SecurityHeaderPolicy;
use Academy\Http\Security\SessionCookieSettings;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Logging\LoggerFactory;
use Academy\Infrastructure\Outbox\InMemoryOutboxTransport;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Infrastructure\Outbox\UnconfiguredOutboxTransport;
use Academy\Infrastructure\RateLimit\PdoRateLimitStore;
use Academy\Infrastructure\Scheduler\PdoSchedulerLock;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Infrastructure\View\Escaper;
use Academy\Infrastructure\View\PhpRenderer;
use DI\ContainerBuilder;
use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return static function (): ContainerInterface {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->useAttributes(false);

    $config = require __DIR__ . '/app.php';

    $builder->addDefinitions([
        'config' => $config,
        'config.app' => $config['app'],
        'config.database' => $config['database'],
        'config.logging' => $config['logging'],
        'config.security' => $config['security'],
        'config.paths' => $config['paths'],

        LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
            /** @var array{name: string, level: string, path: string, json: bool} $logging */
            $logging = $c->get('config.logging');

            return LoggerFactory::create($logging);
        },
        Logger::class => static fn (ContainerInterface $c): LoggerInterface => $c->get(LoggerInterface::class),

        ConnectionFactory::class => static function (ContainerInterface $c): ConnectionFactory {
            /** @var array{
             *   host: string,
             *   port: int,
             *   name: string,
             *   user: string,
             *   password: string,
             *   charset: string,
             *   options: array<int, mixed>
             * } $database
             */
            $database = $c->get('config.database');

            return new ConnectionFactory($database);
        },

        TransactionManager::class => static fn (ContainerInterface $c): TransactionManager => new TransactionManager(
            $c->get(ConnectionFactory::class),
        ),

        Escaper::class => static fn (): Escaper => new Escaper(),

        PhpRenderer::class => static function (ContainerInterface $c): PhpRenderer {
            /** @var array{templates: string} $paths */
            $paths = $c->get('config.paths');

            return new PhpRenderer($paths['templates'], $c->get(Escaper::class));
        },

        SecurityHeaderPolicy::class => static function (ContainerInterface $c): SecurityHeaderPolicy {
            /** @var array{force_https: bool} $security */
            $security = $c->get('config.security');

            return new SecurityHeaderPolicy($security['force_https']);
        },

        SessionRepository::class => static fn (ContainerInterface $c): SessionRepository => new PdoSessionRepository(
            $c->get(ConnectionFactory::class),
        ),
        CsrfTokenManager::class => static fn (): CsrfTokenManager => new CsrfTokenManager(),
        SessionService::class => static function (ContainerInterface $c): SessionService {
            /** @var array{
             *   session: array{
             *     activity_write_throttle_seconds: int,
             *     timeouts: array{
             *       default: array{idle_seconds: int, absolute_seconds: int},
             *       privileged: array{idle_seconds: int, absolute_seconds: int}
             *     }
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new SessionService(
                $c->get(SessionRepository::class),
                $c->get(CsrfTokenManager::class),
                $c->get(LoggerInterface::class),
                $security['session']['timeouts']['default'],
                $security['session']['timeouts']['privileged'],
                $security['session']['activity_write_throttle_seconds'],
            );
        },

        RateLimitKeyFactory::class => static function (ContainerInterface $c): RateLimitKeyFactory {
            /** @var array{rate_limit_pepper: string} $security */
            $security = $c->get('config.security');

            return new RateLimitKeyFactory($security['rate_limit_pepper']);
        },
        RateLimitStore::class => static fn (ContainerInterface $c): RateLimitStore => new PdoRateLimitStore(
            $c->get(ConnectionFactory::class),
        ),
        RateLimiter::class => static function (ContainerInterface $c): RateLimiter {
            /** @var array{rate_limit: array{policies: array<string, array{limit: int, window_seconds: int, failure: string}>}} $security */
            $security = $c->get('config.security');

            return new RateLimiter(
                $c->get(RateLimitStore::class),
                $c->get(RateLimitKeyFactory::class),
                $c->get(LoggerInterface::class),
                $security['rate_limit']['policies'],
            );
        },

        AuditRedactor::class => static fn (): AuditRedactor => new AuditRedactor(),
        AuditWriter::class => static fn (ContainerInterface $c): AuditWriter => new PdoAuditWriter(
            $c->get(ConnectionFactory::class),
        ),
        AuditService::class => static fn (ContainerInterface $c): AuditService => new AuditService(
            $c->get(AuditWriter::class),
            $c->get(AuditRedactor::class),
        ),

        PdoOutboxRepository::class => static fn (ContainerInterface $c): PdoOutboxRepository => new PdoOutboxRepository(
            $c->get(ConnectionFactory::class),
        ),
        OutboxRepository::class => static fn (ContainerInterface $c): OutboxRepository => $c->get(PdoOutboxRepository::class),
        OutboxWriter::class => static fn (ContainerInterface $c): OutboxWriter => $c->get(PdoOutboxRepository::class),
        OutboxTransport::class => static function (ContainerInterface $c): OutboxTransport {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{outbox: array{transport: string}} $security */
            $security = $c->get('config.security');
            $transport = $security['outbox']['transport'];
            if ($transport === 'memory' && in_array($app['env'], ['testing', 'ci', 'local'], true)) {
                return new InMemoryOutboxTransport();
            }

            return new UnconfiguredOutboxTransport();
        },
        OutboxRelayService::class => static function (ContainerInterface $c): OutboxRelayService {
            /** @var array{outbox: array{lease_seconds: int, max_attempts: int, backoff_base_seconds: int, backoff_cap_seconds: int}} $security */
            $security = $c->get('config.security');

            return new OutboxRelayService(
                $c->get(OutboxRepository::class),
                $c->get(OutboxTransport::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['outbox']['max_attempts'],
                $security['outbox']['backoff_base_seconds'],
                $security['outbox']['backoff_cap_seconds'],
            );
        },

        PdoSchedulerLock::class => static fn (ContainerInterface $c): PdoSchedulerLock => new PdoSchedulerLock(
            $c->get(ConnectionFactory::class),
        ),

        Router::class => static function (ContainerInterface $c): Router {
            $strategy = new ApplicationStrategy();
            $strategy->setContainer($c);

            $router = new Router();
            $router->setStrategy($strategy);

            $router->get('/health', [HealthController::class, 'handle']);
            $router->get('/smoke', [SmokeController::class, 'handle']);

            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            if ($app['env'] === 'testing') {
                $router->get('/__wp01a/probe', [Wp01aProbeController::class, 'probe']);
                $router->post('/__wp01a/probe', [Wp01aProbeController::class, 'probe']);
                $router->get('/__wp01a/protected', [Wp01aProbeController::class, 'protected']);
                $router->post('/__wp01a/limited', [Wp01aProbeController::class, 'limited']);
            }

            return $router;
        },

        RouteRequestHandler::class => static fn (ContainerInterface $c): RouteRequestHandler => new RouteRequestHandler(
            $c->get(Router::class),
        ),

        HealthController::class => static fn (): HealthController => new HealthController(),
        SmokeController::class => static fn (ContainerInterface $c): SmokeController => new SmokeController(
            $c->get(PhpRenderer::class),
        ),
        Wp01aProbeController::class => static fn (): Wp01aProbeController => new Wp01aProbeController(),

        TrustedProxyMiddleware::class => static function (ContainerInterface $c): TrustedProxyMiddleware {
            /** @var array{trusted_proxies: list<string>, force_https: bool} $security */
            $security = $c->get('config.security');

            return new TrustedProxyMiddleware($security['trusted_proxies'], $security['force_https']);
        },

        RequestIdMiddleware::class => static fn (): RequestIdMiddleware => new RequestIdMiddleware(),

        ExceptionHandlerMiddleware::class => static function (ContainerInterface $c): ExceptionHandlerMiddleware {
            /** @var array{debug: bool, env: string} $app */
            $app = $c->get('config.app');

            return new ExceptionHandlerMiddleware(
                $c->get(LoggerInterface::class),
                $c->get(PhpRenderer::class),
                $c->get(SecurityHeaderPolicy::class),
                $app['debug'],
                $app['env'],
            );
        },

        SecurityHeadersMiddleware::class => static fn (ContainerInterface $c): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(
            $c->get(SecurityHeaderPolicy::class),
        ),

        SessionMiddleware::class => static function (ContainerInterface $c): SessionMiddleware {
            /** @var array{
             *   session: array{
             *     cookie_secure: bool,
             *     cookies: array{session_name: string, csrf_name: string},
             *     required_path_prefixes: list<string>
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new SessionMiddleware(
                $c->get(SessionService::class),
                SessionCookieSettings::fromSessionConfig($security['session']),
                $security['session']['required_path_prefixes'],
            );
        },

        AuthenticationMiddleware::class => static fn (): AuthenticationMiddleware => new AuthenticationMiddleware(),

        CsrfMiddleware::class => static fn (ContainerInterface $c): CsrfMiddleware => new CsrfMiddleware(
            $c->get(SessionService::class),
        ),

        RateLimitMiddleware::class => static function (ContainerInterface $c): RateLimitMiddleware {
            /** @var array{rate_limit: array{path_policies: array<string, string>}} $security */
            $security = $c->get('config.security');

            return new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                pathPolicies: $security['rate_limit']['path_policies'],
            );
        },

        Kernel::class => static function (ContainerInterface $c): Kernel {
            // Observed order (outer → inner): TrustedProxy → RequestId → ExceptionHandler
            // → SecurityHeaders → Session → Authentication → RateLimit → CSRF → Router
            $middleware = [
                $c->get(TrustedProxyMiddleware::class),
                $c->get(RequestIdMiddleware::class),
                $c->get(ExceptionHandlerMiddleware::class),
                $c->get(SecurityHeadersMiddleware::class),
                $c->get(SessionMiddleware::class),
                $c->get(AuthenticationMiddleware::class),
                $c->get(RateLimitMiddleware::class),
                $c->get(CsrfMiddleware::class),
            ];

            return new Kernel($middleware, $c->get(RouteRequestHandler::class));
        },
    ]);

    return $builder->build();
};
