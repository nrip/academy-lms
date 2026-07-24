<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Domain\Storage\ObjectStorage;

final class ObjectStorageFactory
{
    /**
     * @param array{driver: string, local_base_path: string, local_signing_secret: string} $config
     */
    public static function create(array $config, string $env): ObjectStorage
    {
        $capability = EnvironmentCapability::fromEnvName($env);
        if ($config['driver'] === 'local' && $capability->allowsFakeOrLocalAdapters()) {
            return new LocalObjectStorage(
                $config['local_base_path'],
                $config['local_signing_secret'],
                $env,
            );
        }

        return new UnconfiguredObjectStorage();
    }
}
