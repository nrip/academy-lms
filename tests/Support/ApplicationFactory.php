<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Http\Kernel;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApplicationFactory
{
    public static function container(?string $env = null): ContainerInterface
    {
        if ($env !== null) {
            self::setEnv('APP_ENV', $env);
        }

        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, 2) . '/config/bootstrap.php';

        return $container;
    }

    public static function handle(ServerRequestInterface $request, ?string $env = null): ResponseInterface
    {
        $container = self::container($env);

        return $container->get(Kernel::class)->handle($request);
    }

    /**
     * @return array{
     *   session: array{
     *     cookies: array{session_name: string, csrf_name: string}
     *   }
     * }
     */
    public static function securityConfig(?string $env = null): array
    {
        if ($env !== null) {
            self::setEnv('APP_ENV', $env);
        }

        /** @var array{security: array{session: array{cookies: array{session_name: string, csrf_name: string}}}} $config */
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return $config['security'];
    }

    private static function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
