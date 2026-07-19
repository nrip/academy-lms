<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Http\Kernel;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApplicationFactory
{
    public static function container(): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, 2) . '/config/bootstrap.php';

        return $container;
    }

    public static function handle(ServerRequestInterface $request): ResponseInterface
    {
        $container = self::container();

        return $container->get(Kernel::class)->handle($request);
    }
}
