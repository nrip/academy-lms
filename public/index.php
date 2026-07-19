<?php

declare(strict_types=1);

use Academy\Http\Kernel;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Container\ContainerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var ContainerInterface $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$request = ServerRequestFactory::fromGlobals();
$response = $container->get(Kernel::class)->handle($request);

(new SapiEmitter())->emit($response);
