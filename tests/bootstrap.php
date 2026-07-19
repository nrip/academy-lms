<?php

declare(strict_types=1);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

putenv('APP_DEBUG=true');
$_ENV['APP_DEBUG'] = 'true';
$_SERVER['APP_DEBUG'] = 'true';

putenv('FORCE_HTTPS=false');
$_ENV['FORCE_HTTPS'] = 'false';
$_SERVER['FORCE_HTTPS'] = 'false';

putenv('LOG_PATH=' . dirname(__DIR__) . '/storage/logs/test.log');
$_ENV['LOG_PATH'] = dirname(__DIR__) . '/storage/logs/test.log';
$_SERVER['LOG_PATH'] = dirname(__DIR__) . '/storage/logs/test.log';

require dirname(__DIR__) . '/vendor/autoload.php';
