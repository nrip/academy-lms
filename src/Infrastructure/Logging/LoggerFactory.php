<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    /**
     * @param array{name: string, level: string, path: string, json: bool} $config
     */
    public static function create(array $config): LoggerInterface
    {
        $logger = new Logger($config['name']);
        $levelName = strtoupper($config['level']);
        $level = match ($levelName) {
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'NOTICE' => Level::Notice,
            'WARNING' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY' => Level::Emergency,
            default => Level::Info,
        };

        $directory = dirname($config['path']);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $handler = new StreamHandler($config['path'], $level);
        if ($config['json']) {
            $handler->setFormatter(new JsonFormatter());
        } else {
            $handler->setFormatter(new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                null,
                true,
                true,
            ));
        }

        $logger->pushHandler($handler);
        $logger->pushProcessor(new SensitiveDataProcessor());

        return $logger;
    }
}
