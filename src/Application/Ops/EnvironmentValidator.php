<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

/**
 * Validates required configuration without echoing secret values (RC-01).
 *
 * Web and CLI share this validator. Production-like modes fail closed on
 * missing mandatory key material and forbidden fake adapters.
 */
final class EnvironmentValidator
{
    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config, EnvironmentCapability $capability): EnvironmentValidationResult
    {
        $errors = [];
        $warnings = [];

        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $database = is_array($config['database'] ?? null) ? $config['database'] : [];
        $logging = is_array($config['logging'] ?? null) ? $config['logging'] : [];
        $paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];
        $security = is_array($config['security'] ?? null) ? $config['security'] : [];

        $env = $app['env'] ?? '';
        if (!is_string($env) || AppEnvironment::tryFromMixed($env) === null) {
            $errors[] = 'APP_ENV must be one of: local, testing, ci, uat, staging, production.';
        }

        if (($app['url'] ?? '') === '') {
            $errors[] = 'APP_URL is required.';
        }

        foreach (['host', 'name', 'user', 'charset'] as $dbKey) {
            if (($database[$dbKey] ?? '') === '') {
                $errors[] = 'DB_' . strtoupper($dbKey === 'name' ? 'NAME' : $dbKey) . ' is required.';
            }
        }
        if ((int) ($database['port'] ?? 0) < 1) {
            $errors[] = 'DB_PORT must be a positive integer.';
        }

        $pepper = (string) ($security['rate_limit_pepper'] ?? '');
        if ($pepper === '') {
            $errors[] = 'RATE_LIMIT_PEPPER is required.';
        }

        $identity = is_array($security['identity_tokens'] ?? null) ? $security['identity_tokens'] : [];
        foreach (['token_pepper' => 'TOKEN_PEPPER', 'otp_pepper' => 'OTP_PEPPER'] as $key => $label) {
            if (($identity[$key] ?? '') === '') {
                $errors[] = $label . ' is required.';
            }
        }

        $notifications = is_array($security['notifications'] ?? null) ? $security['notifications'] : [];
        if (($notifications['delivery_key'] ?? '') === '') {
            $errors[] = 'NOTIFICATION_DELIVERY_KEY is required.';
        }

        $emailAdapter = (string) ($notifications['email_adapter'] ?? '');
        $smsAdapter = (string) ($notifications['sms_adapter'] ?? '');
        if ($capability->isProductionLike()) {
            if (in_array($emailAdapter, ['recording', 'local_file'], true)) {
                $errors[] = 'Recording/local email adapters are forbidden in staging/production.';
            }
            if ($smsAdapter === 'recording') {
                $errors[] = 'Recording SMS adapters are forbidden in staging/production.';
            }
        } elseif (in_array($emailAdapter, ['recording', 'local_file'], true)
            && !$capability->allowsFakeOrLocalAdapters()
        ) {
            $errors[] = 'Fake/local email adapters are not permitted in this environment.';
        }

        $documents = is_array($security['documents'] ?? null) ? $security['documents'] : [];
        $storageDriver = (string) ($documents['storage_driver'] ?? 'unconfigured');
        $fakeScanner = (bool) ($documents['fake_scanner_enabled'] ?? false);
        if ($storageDriver === 'local' && !$capability->allowsFakeOrLocalAdapters()) {
            $errors[] = 'DOCUMENTS_STORAGE_DRIVER=local is not permitted in this environment.';
        }
        if ($fakeScanner && !$capability->allowsFakeOrLocalAdapters()) {
            $errors[] = 'DOCUMENTS_FAKE_SCANNER is not permitted in this environment.';
        }
        if ($storageDriver === 'local' && ($documents['local_signing_secret'] ?? '') === '') {
            $errors[] = 'DOCUMENTS_LOCAL_SIGNING_SECRET is required when using local document storage.';
        }

        $payments = is_array($security['payments'] ?? null) ? $security['payments'] : [];
        $fakeGateway = (bool) ($payments['fake_gateway_enabled'] ?? false);
        if ($fakeGateway && !$capability->allowsFakeOrLocalAdapters()) {
            $errors[] = 'PAYMENTS_FAKE_GATEWAY is not permitted in this environment.';
        }
        if ($capability->isProductionLike() && $fakeGateway) {
            $errors[] = 'PAYMENTS_FAKE_GATEWAY is forbidden in staging/production.';
        }
        if ($capability->isProductionLike() && ($payments['razorpay_webhook_secret'] ?? '') === '') {
            $warnings[] = 'RAZORPAY_WEBHOOK_SECRET is empty; webhook ingress will fail closed until configured.';
        }

        $storagePath = (string) ($paths['storage'] ?? '');
        if ($storagePath === '' || (!is_dir($storagePath) && !@mkdir($storagePath, 0775, true))) {
            $errors[] = 'Storage path is missing or not writable.';
        } elseif (!is_writable($storagePath)) {
            $errors[] = 'Storage path is not writable.';
        }

        $logPath = (string) ($logging['path'] ?? '');
        if ($logPath !== '') {
            $logDir = dirname($logPath);
            if (!is_dir($logDir) && !@mkdir($logDir, 0775, true)) {
                $errors[] = 'Log directory is missing or not creatable.';
            } elseif (!is_writable($logDir)) {
                $errors[] = 'Log directory is not writable.';
            }
        }

        if (($app['debug'] ?? false) === true && $capability->isProductionLike()) {
            $warnings[] = 'APP_DEBUG=true in a production-like environment; prefer false for UAT/production.';
        }

        if ($errors !== []) {
            return EnvironmentValidationResult::failure($errors, $warnings);
        }

        return EnvironmentValidationResult::success($warnings);
    }
}
