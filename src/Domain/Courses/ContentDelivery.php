<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

/**
 * Delivery metadata for a ContentItem. Object bodies stay in private storage.
 */
final class ContentDelivery
{
    public function __construct(
        public readonly ?string $originalFilename = null,
        public readonly ?string $mediaMime = null,
        public readonly ?int $mediaBytes = null,
        public readonly ?string $mediaSha256 = null,
        public readonly ?string $podcastUrl = null,
        public readonly ?string $liveJoinUrl = null,
        public readonly ?DateTimeImmutable $liveStartsAt = null,
        public readonly ?DateTimeImmutable $liveEndsAt = null,
        public readonly ?string $liveProvider = null,
        public readonly ?string $liveRecordingUrl = null,
        public readonly ?string $liveExternalMeetingId = null,
    ) {
    }

    public function usesPrivateObject(): bool
    {
        return $this->mediaMime !== null || $this->originalFilename !== null;
    }
}
