<?php

declare(strict_types=1);

use Academy\Http\Controllers\HealthController;
use Academy\Http\Controllers\SmokeController;
use Academy\Http\Kernel;
use Academy\Http\Middleware\ExceptionHandlerMiddleware;
use Academy\Http\Middleware\RequestIdMiddleware;
use Academy\Http\Middleware\SecurityHeadersMiddleware;
use Academy\Http\Middleware\TrustedProxyMiddleware;
use Academy\Http\Routing\RouteRequestHandler;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Logging\LoggerFactory;
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

        Router::class => static function (ContainerInterface $c): Router {
            $strategy = new ApplicationStrategy();
            $strategy->setContainer($c);

            $router = new Router();
            $router->setStrategy($strategy);

            $router->get('/health', [HealthController::class, 'handle']);
            $router->get('/smoke', [SmokeController::class, 'handle']);

            return $router;
        },

        RouteRequestHandler::class => static fn (ContainerInterface $c): RouteRequestHandler => new RouteRequestHandler(
            $c->get(Router::class),
        ),

        HealthController::class => static fn (): HealthController => new HealthController(),

        SmokeController::class => static fn (ContainerInterface $c): SmokeController => new SmokeController(
            $c->get(PhpRenderer::class),
        ),

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
                $app['debug'],
                $app['env'],
            );
        },

        SecurityHeadersMiddleware::class => static function (ContainerInterface $c): SecurityHeadersMiddleware {
            /** @var array{force_https: bool} $security */
            $security = $c->get('config.security');

            return new SecurityHeadersMiddleware($security['force_https']);
        },

        Kernel::class => static function (ContainerInterface $c): Kernel {
            // Eventual pipeline positions (not implemented in Phase 0):
            // Session, Authentication, CSRF, Rate Limiting, Permission.
            $middleware = [
                $c->get(TrustedProxyMiddleware::class),
                $c->get(RequestIdMiddleware::class),
                $c->get(ExceptionHandlerMiddleware::class),
                $c->get(SecurityHeadersMiddleware::class),
            ];

            return new Kernel($middleware, $c->get(RouteRequestHandler::class));
        },
    ]);

    return $builder->build();
};
