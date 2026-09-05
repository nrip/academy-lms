<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

interface QuestionRepository
{
    public function findById(int $questionId): ?Question;

    /** @return list<Question> */
    public function listByBankId(int $bankId): array;

    /**
     * @param array{
     *   bank_id: int,
     *   question_type: string,
     *   stem: string,
     *   marks: string,
     *   explanation: ?string,
     *   version: int,
     *   status: string
     * } $data
     */
    public function insert(array $data): int;

    /**
     * @param array{
     *   stem: string,
     *   marks: string,
     *   explanation: ?string,
     *   status: string,
     *   version: int
     * } $data
     */
    public function update(int $questionId, array $data): bool;

    public function delete(int $questionId): bool;
}
