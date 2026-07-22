<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

/**
 * Internal transaction rollback signal for duplicate email/mobile on registration.
 * Not mapped to HTTP responses.
 */
final class DuplicateRegistrationSignal extends \RuntimeException
{
}
