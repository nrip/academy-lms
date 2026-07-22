<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

final class ProfileCompleteness
{
    /**
     * @param list<string> $completedSections
     * @param list<string> $incompleteSections
     * @param list<string> $missingRequiredFieldKeys
     */
    public function __construct(
        public readonly int $percentage,
        public readonly array $completedSections,
        public readonly array $incompleteSections,
        public readonly array $missingRequiredFieldKeys,
    ) {
    }

    /**
     * @return array{
     *   percentage: int,
     *   completed_sections: list<string>,
     *   incomplete_sections: list<string>,
     *   missing_required_field_keys: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'percentage' => $this->percentage,
            'completed_sections' => $this->completedSections,
            'incomplete_sections' => $this->incompleteSections,
            'missing_required_field_keys' => $this->missingRequiredFieldKeys,
        ];
    }
}
