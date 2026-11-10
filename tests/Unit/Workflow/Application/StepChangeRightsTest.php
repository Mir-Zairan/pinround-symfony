<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application;

use App\Organization\Api\StepPolicy;
use App\Workflow\Application\StepChangeRights;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepChangeRightsTest extends TestCase
{
    /**
     * @return iterable<string, array{StepPolicy, bool, bool, bool}> policy, is lead of the team, may change team steps,
     *                                                               may change team rules
     */
    public static function policiesAndLeads(): iterable
    {
        yield 'locked, lead' => [StepPolicy::Locked, true, false, false];
        yield 'locked, not lead' => [StepPolicy::Locked, false, false, false];
        yield 'extend, lead' => [StepPolicy::Extend, true, true, false];
        yield 'extend, not lead' => [StepPolicy::Extend, false, false, false];
        yield 'team, lead' => [StepPolicy::Team, true, true, true];
        yield 'team, not lead' => [StepPolicy::Team, false, false, false];
    }

    #[Test]
    #[DataProvider('policiesAndLeads')]
    public function onlyTheLeadOfTheTeamChangesItsStepsAndRulesAsThePolicyAllows(StepPolicy $stepPolicy, bool $isLeadOfTeam, bool $mayChangeTeamSteps, bool $mayChangeTeamRules): void
    {
        $rights = new StepChangeRights($stepPolicy, $isLeadOfTeam);

        self::assertSame($mayChangeTeamSteps, $rights->mayChangeTeamSteps());
        self::assertSame($mayChangeTeamRules, $rights->mayChangeTeamRules());
    }

    #[Test]
    public function everyPolicyHasACase(): void
    {
        $coveredPolicies = array_unique(array_map(static fn (array $case): string => $case[0]->value, iterator_to_array(self::policiesAndLeads())));

        self::assertEqualsCanonicalizing(array_map(static fn (StepPolicy $policy): string => $policy->value, StepPolicy::cases()), array_values($coveredPolicies));
    }
}
