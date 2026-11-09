<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
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
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test)'
            .' VALUES (?, NULL, ?, ?, ?)'
            .' ON CONFLICT (organization_id, team_id) DO UPDATE SET tester_moves_done = EXCLUDED.tester_moves_done,'
            .' no_self_review = EXCLUDED.no_self_review, review_before_test = EXCLUDED.review_before_test',
            [$organizationId->toString(), $ruleSet->testerMovesDone, $ruleSet->noSelfReview, $ruleSet->reviewBeforeTest],
            [1 => ParameterType::BOOLEAN, 2 => ParameterType::BOOLEAN, 3 => ParameterType::BOOLEAN],
        );
    }

    public function forOrganization(OrganizationId $organizationId): ?RuleSet
    {
        $this->refuseOutsideATransaction();

        $row = $this->connection->fetchAssociative(
            'SELECT tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE organization_id = ? AND team_id IS NULL',
            [$organizationId->toString()],
        );
        if (false === $row) {
            return null;
        }

        return new RuleSet(
            testerMovesDone: self::rule($row['tester_moves_done']),
            noSelfReview: self::rule($row['no_self_review']),
            reviewBeforeTest: self::rule($row['review_before_test']),
        );
    }

    private function refuseOutsideATransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Rule sets are read and saved only inside an open transaction.');
        }
    }

    private static function rule(mixed $value): bool
    {
        return \is_bool($value) ? $value : throw new \LogicException('A rule from the database is not a boolean.');
    }
}
