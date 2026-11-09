<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\RuleSets;

/**
 * Rule sets kept in an array keyed by organization and team ('' for the organization's own).
 */
final class InMemoryRuleSets implements RuleSets
{
    /** @var array<string, RuleSet> */
    public array $saved = [];

    public function saveForOrganization(OrganizationId $organizationId, RuleSet $ruleSet): void
    {
        $this->saved[self::key($organizationId, null)] = $ruleSet;
    }

    public function forOrganization(OrganizationId $organizationId): ?RuleSet
    {
        return $this->saved[self::key($organizationId, null)] ?? null;
    }

    public function saveForTeam(OrganizationId $organizationId, TeamId $teamId, RuleSet $ruleSet): void
    {
        $this->saved[self::key($organizationId, $teamId)] = $ruleSet;
    }

    public function forTeam(OrganizationId $organizationId, TeamId $teamId): ?RuleSet
    {
        return $this->saved[self::key($organizationId, $teamId)] ?? null;
    }

    public static function key(OrganizationId $organizationId, ?TeamId $teamId): string
    {
        return $organizationId->toString().'/'.($teamId?->toString() ?? '');
    }
}
