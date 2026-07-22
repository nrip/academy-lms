<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

/**
 * Object keys are random (never sequential ids) per AGENTS.md §10 "Random
 * object keys; original names preserved only as metadata". The sanitized
 * filename is retained in the key purely so FakeMalwareScanner can derive a
 * deterministic outcome from it in local/testing/ci — production scanning
 * never depends on the key contents.
 */
final class DocumentObjectKeyGenerator
{
    public function generate(int $applicationId, int $requirementId, string $sanitizedFilename): string
    {
        $entropy = bin2hex(random_bytes(16));

        return sprintf(
            'applications/%d/requirements/%d/%s__%s',
            $applicationId,
            $requirementId,
            $entropy,
            $sanitizedFilename,
        );
    }
}
