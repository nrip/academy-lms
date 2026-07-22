<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Notifications;

use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Academy\Tests\Support\ApplicationFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves NotificationKeyMaterial is eagerly validated at composition-root bootstrap.
 */
final class NotificationKeyMaterialBootstrapTest extends TestCase
{
    /** @var array<string, string|false|null> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === false || $value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->previousEnv = [];
        parent::tearDown();
    }

    public function testMalformedNonEmptyBase64KeyFailsBootstrap(): void
    {
        $malformed = 'not-valid-base64!!!';
        $this->setEnv([
            'APP_ENV' => 'testing',
            'NOTIFICATION_DELIVERY_KEY' => $malformed,
            'NOTIFICATION_DELIVERY_KEY_PREVIOUS' => '',
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
        ]);

        try {
            ApplicationFactory::container('testing');
            self::fail('Expected bootstrap to reject malformed delivery key.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Notification delivery key must be valid base64.', $exception->getMessage());
            self::assertStringNotContainsString($malformed, $exception->getMessage());
        }
    }

    public function testValidBase64WrongByteLengthFailsBootstrap(): void
    {
        $wrongLength = base64_encode(str_repeat("\1", 16));
        $this->setEnv([
            'APP_ENV' => 'testing',
            'NOTIFICATION_DELIVERY_KEY' => $wrongLength,
            'NOTIFICATION_DELIVERY_KEY_PREVIOUS' => '',
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
        ]);

        try {
            ApplicationFactory::container('testing');
            self::fail('Expected bootstrap to reject wrong-length delivery key.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Notification delivery key must decode to exactly 32 bytes.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($wrongLength, $exception->getMessage());
        }
    }

    public function testMalformedPreviousKeyFailsBootstrapWhenConfigured(): void
    {
        $current = base64_encode(str_repeat("\2", 32));
        $malformedPrevious = '%%%not-base64%%%';
        $this->setEnv([
            'APP_ENV' => 'testing',
            'NOTIFICATION_DELIVERY_KEY' => $current,
            'NOTIFICATION_DELIVERY_KEY_PREVIOUS' => $malformedPrevious,
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '2',
        ]);

        try {
            ApplicationFactory::container('testing');
            self::fail('Expected bootstrap to reject malformed previous delivery key.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Notification delivery key must be valid base64.', $exception->getMessage());
            self::assertStringNotContainsString($malformedPrevious, $exception->getMessage());
            self::assertStringNotContainsString($current, $exception->getMessage());
        }
    }

    public function testValidCurrentKeyBootsSuccessfully(): void
    {
        $current = base64_encode(str_repeat("\5", 32));
        $this->setEnv([
            'APP_ENV' => 'testing',
            'NOTIFICATION_DELIVERY_KEY' => $current,
            'NOTIFICATION_DELIVERY_KEY_PREVIOUS' => '',
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
        ]);

        $container = ApplicationFactory::container('testing');
        $keys = $container->get(NotificationKeyMaterial::class);
        self::assertSame(1, $keys->currentVersion());
        self::assertSame(str_repeat("\5", 32), $keys->currentKey());
    }

    public function testValidCurrentPlusValidPreviousKeyBootsSuccessfully(): void
    {
        $current = base64_encode(str_repeat("\6", 32));
        $previous = base64_encode(str_repeat("\7", 32));
        $this->setEnv([
            'APP_ENV' => 'testing',
            'NOTIFICATION_DELIVERY_KEY' => $current,
            'NOTIFICATION_DELIVERY_KEY_PREVIOUS' => $previous,
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '2',
        ]);

        $container = ApplicationFactory::container('testing');
        $keys = $container->get(NotificationKeyMaterial::class);
        self::assertSame(2, $keys->currentVersion());
        self::assertSame(str_repeat("\6", 32), $keys->keyForVersion(2));
        self::assertSame(str_repeat("\7", 32), $keys->keyForVersion(1));
    }

    public function testEagerlyResolvedInstanceIsSameAsInjectedIntoSealedSecretBox(): void
    {
        $current = base64_encode(str_repeat("\x08", 32));
        $this->setEnv([
            'APP_ENV' => 'testing',
            'NOTIFICATION_DELIVERY_KEY' => $current,
            'NOTIFICATION_DELIVERY_KEY_PREVIOUS' => '',
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
        ]);

        $container = ApplicationFactory::container('testing');
        $fromContainer = $container->get(NotificationKeyMaterial::class);
        $box = $container->get(SealedSecretBox::class);

        $reflection = new ReflectionClass($box);
        $property = $reflection->getProperty('keys');
        $injected = $property->getValue($box);

        self::assertSame($fromContainer, $injected);
        self::assertSame($fromContainer, $container->get(NotificationKeyMaterial::class));
    }

    /**
     * @param array<string, string> $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->previousEnv)) {
                $existing = getenv($key);
                $this->previousEnv[$key] = $existing === false ? false : (string) $existing;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
