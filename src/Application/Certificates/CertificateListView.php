<?php

declare(strict_types=1);

namespace Academy\Application\Certificates;

use Academy\Domain\Certificates\Certificate;

final class CertificateListView
{
    /**
     * @param list<Certificate> $certificates
     * @param list<string> $incompleteTitles
     */
    public function __construct(
        public readonly int $enrolmentId,
        public readonly array $certificates,
        public readonly bool $eligible,
        public readonly array $incompleteTitles,
        public readonly ?string $message,
    ) {
    }
}
