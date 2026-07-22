<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use Academy\Domain\Exception\ValidationException;

/**
 * Pure input-shaping for Draft creation. Entity-factory only (Rule 3 /
 * WP02_IMPLEMENTATION_NOTE.md "ApplicationStateMachine not introduced" —
 * this class never transitions status, it only validates the raw request
 * shape before DraftApplicationService loads and evaluates the batch.
 */
final class ApplicationDraftFactory
{
    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function batchIdFromInput(array $input): int
    {
        $raw = $input['batch_id'] ?? null;

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1) {
            $value = (int) trim($raw);
            if ($value > 0) {
                return $value;
            }
        }

        throw new ValidationException('Please select a valid batch.', [
            'batch_id' => ['A valid batch_id is required.'],
        ]);
    }
}
