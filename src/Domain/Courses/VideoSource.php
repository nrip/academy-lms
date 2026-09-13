<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

/**
 * Validated video source for a ContentItem (no arbitrary iframe HTML).
 */
final class VideoSource
{
    public function __construct(
        public readonly string $deliveryMode,
        public readonly string $provider,
        public readonly string $sourceUrl,
        public readonly ?string $embedUrl,
    ) {
    }

    public function isEmbedded(): bool
    {
        return $this->deliveryMode === VideoDeliveryMode::EMBEDDED && $this->embedUrl !== null;
    }
}
