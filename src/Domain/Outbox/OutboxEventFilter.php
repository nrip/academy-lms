<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

use InvalidArgumentException;

/**
 * Explicit include/exclude filter for outbox claim — no mixed nullable semantics.
 */
final class OutboxEventFilter
{
    public const MODE_INCLUDE = 'include';
    public const MODE_EXCLUDE = 'exclude';

    /**
     * @param list<string> $eventTypes
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $eventTypes,
    ) {
        if ($this->mode !== self::MODE_INCLUDE && $this->mode !== self::MODE_EXCLUDE) {
            throw new InvalidArgumentException('Outbox event filter mode must be include or exclude.');
        }
        if ($this->eventTypes === []) {
            throw new InvalidArgumentException('Outbox event filter requires at least one event type.');
        }
        foreach ($this->eventTypes as $type) {
            if ($type === '') {
                throw new InvalidArgumentException('Outbox event type must not be empty.');
            }
        }
    }

    /**
     * @param list<string> $eventTypes
     */
    public static function includeOnly(array $eventTypes): self
    {
        return new self(self::MODE_INCLUDE, array_values($eventTypes));
    }

    /**
     * @param list<string> $eventTypes
     */
    public static function excluding(array $eventTypes): self
    {
        return new self(self::MODE_EXCLUDE, array_values($eventTypes));
    }
}
