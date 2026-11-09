<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application;

use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Unit\Ticketing\Application\FixedTeamQueries;
use App\Workflow\Application\RuleTeamNotFound;
use App\Workflow\Application\RuleUnknown;
use App\Workflow\Application\SetRule;
use App\Workflow\Application\TeamRulesNeedTeamPolicy;
use App\Workflow\Domain\RuleSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetRuleTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ea1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000001ec1';
    private const string BETA_BAKERY_TEAM = '0197a3c0-0000-7000-8000-000000001ec9';

    #[Test]
    public function anOrganizationRuleTurnsOffAndTheOtherTwoStay(): void
    {
        $ruleSets = new InMemoryRuleSets();
        $ruleSets->saveForOrganization(self::acmeFoods(), new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true));

        self::setRule($ruleSets, StepPolicy::Extend)(self::acmeFoods(), null, 'review_before_test', false);

        self::assertEquals(
            [InMemoryRuleSets::key(self::acmeFoods(), null) => new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: false)],
            $ruleSets->saved,
        );
    }

    #[Test]
    public function anOrganizationWithoutARuleSetStartsFromTheDefaults(): void
    {
        $ruleSets = new InMemoryRuleSets();

        self::setRule($ruleSets, StepPolicy::Locked)(self::acmeFoods(), null, 'tester_moves_done', false);

        self::assertEquals(new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: true), $ruleSets->forOrganization(self::acmeFoods()));
    }

    #[Test]
    public function theFirstTeamRuleSetCopiesTheOrganizationsThenChangesTheOneRule(): void
    {
        $ruleSets = new InMemoryRuleSets();
        $ruleSets->saveForOrganization(self::acmeFoods(), new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false));

        self::setRule($ruleSets, StepPolicy::Team)(self::acmeFoods(), self::shopTeam(), 'no_self_review', false);

        self::assertEquals(
            [
                InMemoryRuleSets::key(self::acmeFoods(), null) => new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false),
                InMemoryRuleSets::key(self::acmeFoods(), self::shopTeam()) => new RuleSet(testerMovesDone: false, noSelfReview: false, reviewBeforeTest: false),
            ],
            $ruleSets->saved,
        );
    }

    #[Test]
    public function aTeamThatHasItsOwnRuleSetChangesThatOneNotACopyOfTheOrganizations(): void
    {
        $ruleSets = new InMemoryRuleSets();
        $ruleSets->saveForOrganization(self::acmeFoods(), RuleSet::defaults());
        $ruleSets->saveForTeam(self::acmeFoods(), self::shopTeam(), new RuleSet(testerMovesDone: false, noSelfReview: false, reviewBeforeTest: true));

        self::setRule($ruleSets, StepPolicy::Team)(self::acmeFoods(), self::shopTeam(), 'review_before_test', false);

        self::assertEquals(new RuleSet(testerMovesDone: false, noSelfReview: false, reviewBeforeTest: false), $ruleSets->forTeam(self::acmeFoods(), self::shopTeam()));
        self::assertEquals(RuleSet::defaults(), $ruleSets->forOrganization(self::acmeFoods()));
    }

    #[Test]
    public function aTeamRuleIsRefusedUnderEveryPolicyButTeam(): void
    {
        foreach ([StepPolicy::Extend, StepPolicy::Locked] as $stepPolicy) {
            $ruleSets = new InMemoryRuleSets();
            try {
                self::setRule($ruleSets, $stepPolicy)(self::acmeFoods(), self::shopTeam(), 'no_self_review', false);
                self::fail(\sprintf('A team rule was set under %s.', $stepPolicy->value));
            } catch (TeamRulesNeedTeamPolicy) {
            }
            self::assertSame([], $ruleSets->saved, $stepPolicy->value);
        }
    }

    #[Test]
    public function anUnknownRuleIsRefusedAndNothingIsSaved(): void
    {
        $ruleSets = new InMemoryRuleSets();

        try {
            self::setRule($ruleSets, StepPolicy::Team)(self::acmeFoods(), null, 'lead_moves_done', false);
            self::fail('An unknown rule was set.');
        } catch (RuleUnknown $unknown) {
            self::assertSame('There is no rule "lead_moves_done".', $unknown->getMessage());
        }

        self::assertSame([], $ruleSets->saved);
    }

    #[Test]
    public function aTeamRuleForATeamThatIsNotTheOrganizationsIsRefusedAndNothingIsSaved(): void
    {
        $ruleSets = new InMemoryRuleSets();
        $ruleSets->saveForOrganization(self::acmeFoods(), RuleSet::defaults());

        try {
            self::setRule($ruleSets, StepPolicy::Team)(self::acmeFoods(), new TeamId(self::BETA_BAKERY_TEAM), 'no_self_review', false);
            self::fail('A rule was set for a team of another organization.');
        } catch (RuleTeamNotFound $notFound) {
            self::assertSame('That team is not in this organization.', $notFound->getMessage());
        }

        self::assertEquals([InMemoryRuleSets::key(self::acmeFoods(), null) => RuleSet::defaults()], $ruleSets->saved);
    }

    /**
     * Acme Foods has the one team SHOP.
     */
    private static function setRule(InMemoryRuleSets $ruleSets, StepPolicy $stepPolicy): SetRule
    {
        return new SetRule($ruleSets, new FixedStepPolicy($stepPolicy), new FixedTeamQueries(self::acmeFoods(), [self::shopTeam()]));
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }

    private static function shopTeam(): TeamId
    {
        return new TeamId(self::SHOP_TEAM);
    }
}
