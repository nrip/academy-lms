<?php

declare(strict_types=1);

/**
 * Application bootstrap (composition root helper).
 *
 * Eagerly resolves NotificationKeyMaterial so malformed delivery-key
 * configuration fails before the application serves requests or runs jobs.
 * Validation is owned solely by NotificationKeyMaterial (no duplicated decode).
 *
 * @return \Psr\Container\ContainerInterface
 */

use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Psr\Container\ContainerInterface;

/** @var ContainerInterface $container */
$container = (require __DIR__ . '/container.php')();

// Fail-fast: validate current/previous delivery keys and versions at startup.
$container->get(NotificationKeyMaterial::class);

return $container;
