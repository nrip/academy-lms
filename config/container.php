<?php

declare(strict_types=1);

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\TokenConfirmationCleanupService;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Notifications\DeliveryFinaliser;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Application\RBAC\RoleAssignmentService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Application\Security\SessionService;
use Academy\Domain\Audit\AuditWriter;
use Academy\Domain\Identity\OtpHmac;
use Academy\Domain\Identity\TokenConfirmationContextRepository;
use Academy\Domain\Identity\TokenConsumedHandler;
use Academy\Domain\Identity\TokenHmac;
use Academy\Domain\Identity\UserSecuritySnapshotRepository;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\SmsOtpDeliveryPort;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Domain\Outbox\OutboxTransport;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\RBAC\RoleRepository;
use Academy\Domain\Security\RateLimitStore;
use Academy\Domain\Security\SessionRepository;
use Academy\Http\Controllers\HealthController;
use Academy\Http\Controllers\SmokeController;
use Academy\Http\Controllers\Wp01aProbeController;
use Academy\Http\Controllers\Wp01b2aTokenProbeController;
use Academy\Http\Controllers\Wp01bRbacProbeController;
use Academy\Http\Kernel;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\CsrfMiddleware;
use Academy\Http\Middleware\ExceptionHandlerMiddleware;
use Academy\Http\Middleware\RateLimitMiddleware;
use Academy\Http\Middleware\RequestIdMiddleware;
use Academy\Http\Middleware\SecurityHeadersMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Middleware\TrustedProxyMiddleware;
use Academy\Http\Routing\RouteAccess;
use Academy\Http\Routing\RouteRequestHandler;
use Academy\Http\Security\ConfirmationCookieSettings;
use Academy\Http\Security\SecurityHeaderPolicy;
use Academy\Http\Security\SessionCookieSettings;
use Academy\Http\Security\TokenPageHeaderPolicy;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Identity\NoOpTokenConsumedHandler;
use Academy\Infrastructure\Identity\PdoTokenConfirmationContextRepository;
use Academy\Infrastructure\Identity\PdoUserSecuritySnapshotRepository;
use Academy\Infrastructure\Identity\PdoVerificationChallengeRepository;
use Academy\Infrastructure\Identity\PdoVerificationTokenRepository;
use Academy\Infrastructure\Identity\RecordingTokenConsumedHandler;
use Academy\Infrastructure\Logging\LoggerFactory;
use Academy\Infrastructure\Notifications\LocalFileEmailAdapter;
use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Academy\Infrastructure\Notifications\RecordingEmailAdapter;
use Academy\Infrastructure\Notifications\RecordingSmsAdapter;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Academy\Infrastructure\Notifications\UnavailableEmailAdapter;
use Academy\Infrastructure\Notifications\UnavailableSmsAdapter;
use Academy\Infrastructure\Outbox\InMemoryOutboxTransport;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Infrastructure\Outbox\UnconfiguredOutboxTransport;
use Academy\Infrastructure\RateLimit\PdoRateLimitStore;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Infrastructure\RBAC\PdoRoleRepository;
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

        UserSecuritySnapshotRepository::class => static fn (ContainerInterface $c): UserSecuritySnapshotRepository => new PdoUserSecuritySnapshotRepository(
            $c->get(ConnectionFactory::class),
        ),
        RoleRepository::class => static fn (ContainerInterface $c): RoleRepository => new PdoRoleRepository(
            $c->get(ConnectionFactory::class),
        ),
        PermissionRepository::class => static fn (ContainerInterface $c): PermissionRepository => new PdoPermissionRepository(
            $c->get(ConnectionFactory::class),
        ),
        AuthorizationService::class => static fn (ContainerInterface $c): AuthorizationService => new AuthorizationService(
            $c->get(PermissionRepository::class),
        ),
        RoleAssignmentService::class => static fn (ContainerInterface $c): RoleAssignmentService => new RoleAssignmentService(
            $c->get(TransactionManager::class),
            $c->get(RoleRepository::class),
            $c->get(AuditService::class),
            $c->get(SessionService::class),
        ),
        RouteAccess::class => static fn (ContainerInterface $c): RouteAccess => new RouteAccess(
            $c->get(AuthorizationService::class),
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

        TokenHmac::class => static function (ContainerInterface $c): TokenHmac {
            /** @var array{identity_tokens: array{token_pepper: string}} $security */
            $security = $c->get('config.security');

            return new TokenHmac($security['identity_tokens']['token_pepper']);
        },
        OtpHmac::class => static function (ContainerInterface $c): OtpHmac {
            /** @var array{identity_tokens: array{otp_pepper: string}} $security */
            $security = $c->get('config.security');

            return new OtpHmac($security['identity_tokens']['otp_pepper']);
        },
        NotificationKeyMaterial::class => static function (ContainerInterface $c): NotificationKeyMaterial {
            /** @var array{
             *   notifications: array{
             *     delivery_key: string,
             *     delivery_key_previous: ?string,
             *     delivery_key_version: int,
             *     delivery_key_previous_version: ?int
             *   }
             * } $security
             */
            $security = $c->get('config.security');
            $notifications = $security['notifications'];

            return new NotificationKeyMaterial(
                $notifications['delivery_key'],
                $notifications['delivery_key_version'],
                $notifications['delivery_key_previous'],
                $notifications['delivery_key_previous_version'],
            );
        },
        SealedSecretBox::class => static fn (ContainerInterface $c): SealedSecretBox => new SealedSecretBox(
            $c->get(NotificationKeyMaterial::class),
        ),

        VerificationTokenRepository::class => static fn (ContainerInterface $c): VerificationTokenRepository => new PdoVerificationTokenRepository(
            $c->get(ConnectionFactory::class),
        ),
        VerificationChallengeRepository::class => static fn (ContainerInterface $c): VerificationChallengeRepository => new PdoVerificationChallengeRepository(
            $c->get(ConnectionFactory::class),
        ),
        TokenConfirmationContextRepository::class => static fn (ContainerInterface $c): TokenConfirmationContextRepository => new PdoTokenConfirmationContextRepository(
            $c->get(ConnectionFactory::class),
        ),

        RecordingTokenConsumedHandler::class => static fn (): RecordingTokenConsumedHandler => new RecordingTokenConsumedHandler(),
        TokenConsumedHandler::class => static function (ContainerInterface $c): TokenConsumedHandler {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            if ($app['env'] === 'testing') {
                return $c->get(RecordingTokenConsumedHandler::class);
            }

            return new NoOpTokenConsumedHandler();
        },

        RecordingEmailAdapter::class => static fn (): RecordingEmailAdapter => new RecordingEmailAdapter(),
        RecordingSmsAdapter::class => static fn (): RecordingSmsAdapter => new RecordingSmsAdapter(),
        EmailDeliveryPort::class => static function (ContainerInterface $c): EmailDeliveryPort {
            /** @var array{notifications: array{email_adapter: string, local_mail_path: string}} $security */
            $security = $c->get('config.security');
            $adapter = $security['notifications']['email_adapter'];
            if ($adapter === 'recording') {
                return $c->get(RecordingEmailAdapter::class);
            }
            if ($adapter === 'local_file') {
                /** @var array{root: string, storage: string} $paths */
                $paths = $c->get('config.paths');
                $configured = $security['notifications']['local_mail_path'];
                if ($configured !== '' && ($configured[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $configured) === 1)) {
                    $directory = $configured;
                } elseif (str_starts_with($configured, 'storage/') || str_starts_with($configured, 'storage\\')) {
                    $directory = $paths['root'] . '/' . str_replace('\\', '/', $configured);
                } else {
                    $directory = rtrim($paths['storage'], '/\\') . '/' . ltrim(str_replace('\\', '/', $configured), '/');
                }

                return new LocalFileEmailAdapter($directory);
            }

            return new UnavailableEmailAdapter();
        },
        SmsOtpDeliveryPort::class => static function (ContainerInterface $c): SmsOtpDeliveryPort {
            /** @var array{notifications: array{sms_adapter: string}} $security */
            $security = $c->get('config.security');
            $adapter = $security['notifications']['sms_adapter'];

            return match ($adapter) {
                'recording' => $c->get(RecordingSmsAdapter::class),
                default => new UnavailableSmsAdapter(),
            };
        },
        NotificationCapability::class => static function (ContainerInterface $c): NotificationCapability {
            /** @var array{notifications: array{email_adapter: string, sms_adapter: string}} $security */
            $security = $c->get('config.security');

            return NotificationCapability::fromEnvFlags(
                $security['notifications']['email_adapter'] !== 'unavailable',
                $security['notifications']['sms_adapter'] !== 'unavailable',
            );
        },

        VerificationTokenIssuer::class => static fn (ContainerInterface $c): VerificationTokenIssuer => new VerificationTokenIssuer(
            $c->get(TransactionManager::class),
            $c->get(VerificationTokenRepository::class),
            $c->get(TokenHmac::class),
            $c->get(SealedSecretBox::class),
            $c->get(OutboxWriter::class),
        ),
        VerificationChallengeIssuer::class => static fn (ContainerInterface $c): VerificationChallengeIssuer => new VerificationChallengeIssuer(
            $c->get(TransactionManager::class),
            $c->get(VerificationChallengeRepository::class),
            $c->get(OtpHmac::class),
            $c->get(SealedSecretBox::class),
            $c->get(OutboxWriter::class),
        ),
        TokenConfirmationService::class => static fn (ContainerInterface $c): TokenConfirmationService => new TokenConfirmationService(
            $c->get(TransactionManager::class),
            $c->get(VerificationTokenRepository::class),
            $c->get(TokenConfirmationContextRepository::class),
            $c->get(TokenHmac::class),
            $c->get(TokenConsumedHandler::class),
            $c->get(AuditService::class),
            $c->get(RateLimiter::class),
        ),
        DeliveryFinaliser::class => static function (ContainerInterface $c): DeliveryFinaliser {
            /** @var array{outbox: array{max_attempts: int}} $security */
            $security = $c->get('config.security');

            return new DeliveryFinaliser(
                $c->get(TransactionManager::class),
                $c->get(OutboxRepository::class),
                $c->get(VerificationTokenRepository::class),
                $c->get(VerificationChallengeRepository::class),
                $c->get(AuditService::class),
                $security['outbox']['max_attempts'],
            );
        },
        IdentityNotificationDeliveryWorker::class => static function (ContainerInterface $c): IdentityNotificationDeliveryWorker {
            /** @var array{outbox: array{lease_seconds: int, max_attempts: int, backoff_base_seconds: int, backoff_cap_seconds: int}} $security */
            $security = $c->get('config.security');

            return new IdentityNotificationDeliveryWorker(
                $c->get(OutboxRepository::class),
                $c->get(VerificationTokenRepository::class),
                $c->get(VerificationChallengeRepository::class),
                $c->get(SealedSecretBox::class),
                $c->get(EmailDeliveryPort::class),
                $c->get(SmsOtpDeliveryPort::class),
                $c->get(DeliveryFinaliser::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['outbox']['max_attempts'],
                $security['outbox']['backoff_base_seconds'],
                $security['outbox']['backoff_cap_seconds'],
            );
        },
        TokenConfirmationCleanupService::class => static fn (ContainerInterface $c): TokenConfirmationCleanupService => new TokenConfirmationCleanupService(
            $c->get(TokenConfirmationContextRepository::class),
        ),

        ConfirmationCookieSettings::class => static function (ContainerInterface $c): ConfirmationCookieSettings {
            /** @var array{identity_tokens: array{use_host_prefix: bool, cookie_secure: bool}} $security */
            $security = $c->get('config.security');

            return ConfirmationCookieSettings::fromEnvFlags(
                $security['identity_tokens']['use_host_prefix'],
                $security['identity_tokens']['cookie_secure'],
            );
        },
        TokenPageHeaderPolicy::class => static fn (): TokenPageHeaderPolicy => new TokenPageHeaderPolicy(),

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

                /** @var RouteAccess $access */
                $access = $c->get(RouteAccess::class);
                // Probe routes exist only in testing — outside testing they must 404 (not merely auth-deny).
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/allow', [Wp01bRbacProbeController::class, 'allow']),
                    'rbac.role.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/deny', [Wp01bRbacProbeController::class, 'deny']),
                    'document.metadata.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/document', [Wp01bRbacProbeController::class, 'document']),
                    'document.metadata.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/refund', [Wp01bRbacProbeController::class, 'refund']),
                    'finance.refund.approve',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/item/{id}', [Wp01bRbacProbeController::class, 'parameterized']),
                    'rbac.role.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/unknown', [Wp01bRbacProbeController::class, 'unknown']),
                    'does.not.exist.permission',
                );

                $router->get('/verify-email', [Wp01b2aTokenProbeController::class, 'verifyEmailGet']);
                $router->get('/verify-email/confirm', [Wp01b2aTokenProbeController::class, 'verifyEmailConfirmGet']);
                $router->post('/verify-email/confirm', [Wp01b2aTokenProbeController::class, 'verifyEmailConfirmPost']);
                $router->get('/verify-email/result', [Wp01b2aTokenProbeController::class, 'verifyEmailResult']);
                $router->get('/reset-password', [Wp01b2aTokenProbeController::class, 'resetPasswordGet']);
                $router->get('/reset-password/confirm', [Wp01b2aTokenProbeController::class, 'resetPasswordConfirmGet']);
                $router->post('/reset-password/confirm', [Wp01b2aTokenProbeController::class, 'resetPasswordConfirmPost']);
                $router->get('/reset-password/result', [Wp01b2aTokenProbeController::class, 'resetPasswordResult']);
                $router->post('/__wp01b2a/issue-token', [Wp01b2aTokenProbeController::class, 'issueProbe']);
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
        Wp01bRbacProbeController::class => static fn (): Wp01bRbacProbeController => new Wp01bRbacProbeController(),
        Wp01b2aTokenProbeController::class => static function (ContainerInterface $c): Wp01b2aTokenProbeController {
            /** @var array{identity_tokens: array{confirmation_context_ttl_seconds: int}} $security */
            $security = $c->get('config.security');

            return new Wp01b2aTokenProbeController(
                $c->get(TokenConfirmationService::class),
                $c->get(VerificationTokenIssuer::class),
                $c->get(ConfirmationCookieSettings::class),
                $c->get(TokenPageHeaderPolicy::class),
                $c->get(PhpRenderer::class),
                $security['identity_tokens']['confirmation_context_ttl_seconds'],
            );
        },

        TrustedProxyMiddleware::class => static function (ContainerInterface $c): TrustedProxyMiddleware {
            /** @var array{trusted_proxies: list<string>, force_https: bool} $security */
            $security = $c->get('config.security');

            return new TrustedProxyMiddleware($security['trusted_proxies'], $security['force_https']);
        },

        RequestIdMiddleware::class => static fn (): RequestIdMiddleware => new RequestIdMiddleware(),

        ExceptionHandlerMiddleware::class => static function (ContainerInterface $c): ExceptionHandlerMiddleware {
            /** @var array{debug: bool, env: string} $app */
            $app = $c->get('config.app');
            /** @var array{
             *   session: array{
             *     cookie_secure: bool,
             *     cookies: array{session_name: string, csrf_name: string}
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new ExceptionHandlerMiddleware(
                $c->get(LoggerInterface::class),
                $c->get(PhpRenderer::class),
                $c->get(SecurityHeaderPolicy::class),
                SessionCookieSettings::fromSessionConfig($security['session']),
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

        AuthenticationMiddleware::class => static function (ContainerInterface $c): AuthenticationMiddleware {
            /** @var array{
             *   session: array{
             *     cookie_secure: bool,
             *     cookies: array{session_name: string, csrf_name: string}
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new AuthenticationMiddleware(
                $c->get(UserSecuritySnapshotRepository::class),
                $c->get(SessionService::class),
                SessionCookieSettings::fromSessionConfig($security['session']),
            );
        },

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
            // Permission enforcement is route-level only (RequirePermissionMiddleware via RouteAccess).
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
