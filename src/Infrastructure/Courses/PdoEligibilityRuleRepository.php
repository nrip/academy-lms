<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\EligibilityRule;
use Academy\Domain\Courses\EligibilityRuleRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoEligibilityRuleRepository implements EligibilityRuleRepository
{
    private const COLUMNS = 'rule_id, course_version_id, field, operator, value, logic_group,
        display_label, sort_order, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByCourseVersionId(int $courseVersionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM eligibility_rules WHERE course_version_id = :version_id
             ORDER BY sort_order ASC, rule_id ASC',
        );
        $stmt->execute(['version_id' => $courseVersionId]);

        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[] = $this->mapRow($row);
        }

        return $rules;
    }

    public function insert(array $data): int
    {
        $pdo = $this->connections->connection();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO eligibility_rules (
                course_version_id, field, operator, value, logic_group, display_label, sort_order,
                created_at, updated_at
             ) VALUES (
                :course_version_id, :field, :operator, :value, :logic_group, :display_label, :sort_order,
                :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'course_version_id' => $data['course_version_id'],
            'field' => $data['field'],
            'operator' => $data['operator'],
            'value' => $data['value'],
            'logic_group' => $data['logic_group'],
            'display_label' => $data['display_label'],
            'sort_order' => $data['sort_order'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): EligibilityRule
    {
        $utc = new DateTimeZone('UTC');

        return new EligibilityRule(
            ruleId: (int) $row['rule_id'],
            courseVersionId: (int) $row['course_version_id'],
            field: (string) $row['field'],
            operator: (string) $row['operator'],
            value: (string) $row['value'],
            logicGroup: (string) $row['logic_group'],
            displayLabel: (string) $row['display_label'],
            sortOrder: (int) $row['sort_order'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
