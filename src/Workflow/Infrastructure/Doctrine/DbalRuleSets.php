<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\RuleSets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * wf_rule_set has no primary key (its unique index counts NULL team ids as equal), so no entity maps it. The
 * organization's row is the one without a team.
 */
final readonly class DbalRuleSets implements RuleSets
{
    public function __construct(private Connection $connection)
    {
    }

    public function saveForOrganization(OrganizationId $organizationId, RuleSet $ruleSet): void
    {
        $this->refuseOutsideATransaction();

        $this->connection->executeStatement(
            self::INSERT_ROW.' ON CONFLICT (organization_id, team_id) DO UPDATE SET tester_moves_done = EXCLUDED.tester_moves_done,'
            .' no_self_review = EXCLUDED.no_self_review, review_before_test = EXCLUDED.review_before_test',
            self::rowValues($organizationId, null, $ruleSet),
            self::ROW_TYPES,
        );
    }

    public function forOrganization(OrganizationId $organizationId): ?RuleSet
    {
        $this->refuseOutsideATransaction();

        return self::ruleSet($this->connection->fetchAssociative(
            'SELECT tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE organization_id = ? AND team_id IS NULL',
            [$organizationId->toString()],
        ));
    }

    /**
     * The upsert updates the switched rule's column only, so it does not lose a rule another transaction switched
     * after this one read $startingRules; it waits for that transaction's row lock, then updates the committed row.
     */
    public function switchRule(OrganizationId $organizationId, ?TeamId $teamId, string $ruleCode, bool $isOn, RuleSet $startingRules): void
    {
        $column = self::columnOf($ruleCode);
        $this->refuseOutsideATransaction();

        $this->connection->executeStatement(
            self::INSERT_ROW.\sprintf(' ON CONFLICT (organization_id, team_id) DO UPDATE SET %1$s = EXCLUDED.%1$s', $column),
            self::rowValues($organizationId, $teamId, $startingRules->withRule($ruleCode, $isOn)),
            self::ROW_TYPES,
        );
    }

    public function forTeam(OrganizationId $organizationId, TeamId $teamId): ?RuleSet
    {
        $this->refuseOutsideATransaction();

        return self::ruleSet($this->connection->fetchAssociative(
            'SELECT tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE organization_id = ? AND team_id = ?',
            [$organizationId->toString(), $teamId->toString()],
        ));
    }

    private const string INSERT_ROW = 'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test)'
        .' VALUES (?, ?, ?, ?, ?)';

    private const array ROW_TYPES = [2 => ParameterType::BOOLEAN, 3 => ParameterType::BOOLEAN, 4 => ParameterType::BOOLEAN];

    /**
     * @return list<string|bool|null>
     */
    private static function rowValues(OrganizationId $organizationId, ?TeamId $teamId, RuleSet $ruleSet): array
    {
        return [$organizationId->toString(), $teamId?->toString(), $ruleSet->testerMovesDone, $ruleSet->noSelfReview, $ruleSet->reviewBeforeTest];
    }

    /**
     * The column is picked from this fixed list, so the rule code a caller passes never becomes SQL text.
     *
     * @throws \InvalidArgumentException when $ruleCode is not one of RuleSet::RULE_CODES
     */
    private static function columnOf(string $ruleCode): string
    {
        return match ($ruleCode) {
            'tester_moves_done' => 'tester_moves_done',
            'no_self_review' => 'no_self_review',
            'review_before_test' => 'review_before_test',
            default => throw new \InvalidArgumentException(\sprintf('There is no rule "%s".', $ruleCode)),
        };
    }

    private function refuseOutsideATransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Rule sets are read and saved only inside an open transaction.');
        }
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private static function ruleSet(array|false $row): ?RuleSet
    {
        if (false === $row) {
            return null;
        }

        return new RuleSet(
            testerMovesDone: self::rule($row['tester_moves_done']),
            noSelfReview: self::rule($row['no_self_review']),
            reviewBeforeTest: self::rule($row['review_before_test']),
        );
    }

    private static function rule(mixed $value): bool
    {
        return \is_bool($value) ? $value : throw new \LogicException('A rule from the database is not a boolean.');
    }
}
