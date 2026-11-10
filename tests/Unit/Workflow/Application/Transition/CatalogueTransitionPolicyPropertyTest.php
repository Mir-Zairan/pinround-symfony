<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TransitionView;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 1,000 seeded random tickets (also in team steps of To do, Doing and Waiting), people and rules: what available()
 * and moveChoices() list is exactly what check() and checkMoveTo() allow.
 */
final class CatalogueTransitionPolicyPropertyTest extends TestCase
{
    private const int COMBINATIONS = 1000;
    private const string REVIEW_FIRST_NOTE = 'Team rule: code review comes before testing.';

    #[Test]
    public function whatIsOfferedIsAllowedAndWhatIsNotOfferedIsRefusedInEveryRandomCombination(): void
    {
        $random = new Randomizer(new Mt19937(1));
        $policy = GuardFixtures::policy();
        $people = self::people();
        $steps = GuardFixtures::stepsWithTeamSteps();
        $stepOfAnotherTeam = new StepView('0190a000-0000-7000-8000-0000000001ff', 'Other team step', 'doing', 'Doing', 11, 'team', null);
        $combinationsWithAVerb = 0;
        $combinationsWithAMoveChoice = 0;

        for ($i = 0; $i < self::COMBINATIONS; ++$i) {
            $ticket = GuardFixtures::randomTicket($random, $steps);
            $actor = $people[$random->getInt(0, \count($people) - 1)];
            $rules = new RuleSetView(1 === $random->getInt(0, 1), 1 === $random->getInt(0, 1), 1 === $random->getInt(0, 1));
            $context = \sprintf('combination %d', $i);

            $offered = [];
            foreach ($policy->available($ticket, $actor, $rules, GuardFixtures::team())->transitions as $view) {
                $offered[$view->verb] = $view;
            }

            foreach (Verb::cases() as $verb) {
                $decision = $policy->check($ticket, $actor, $rules, GuardFixtures::team(), $verb->value);
                $view = $offered[$verb->value] ?? null;

                if ($view instanceof TransitionView) {
                    self::assertTrue($decision->isAllowed, $context.': '.$verb->value.' is offered but refused');
                    self::assertEquals($view->moveTarget, $decision->moveTarget, $context.': '.$verb->value);
                } else {
                    self::assertFalse($decision->isAllowed, $context.': '.$verb->value.' is allowed but not offered');
                    self::assertNull($decision->moveTarget, $context.': '.$verb->value);
                }
            }

            $combinationsWithAVerb += [] === $offered ? 0 : 1;

            $decision = $policy->checkMoveTo($ticket, $actor, $rules, $stepOfAnotherTeam, $steps);
            self::assertFalse($decision->isAllowed, $context.': a step outside the move targets is allowed');
            self::assertNull($decision->moveTarget, $context);

            $groups = $policy->moveChoices($ticket, $actor, $steps)->groups;
            $combinationsWithAMoveChoice += [] === $groups ? 0 : 1;
            if ([] === $groups) {
                foreach ($steps as $step) {
                    self::assertFalse($policy->checkMoveTo($ticket, $actor, $rules, $step, $steps)->isAllowed, $context.': move to '.$step->name().' without a list');
                }
            }

            foreach ($groups as $group) {
                foreach ($group->choices as $choice) {
                    $decision = $policy->checkMoveTo($ticket, $actor, $rules, $choice->step, $steps);
                    $name = $context.': move to '.$choice->step->name();

                    if ($choice->isCurrent) {
                        self::assertFalse($decision->isAllowed, $name);
                    } elseif ('progress' === $ticket->coreCode && 'test' === $choice->step->coreCode() && $rules->reviewBeforeTest) {
                        self::assertFalse($decision->isAllowed, $name);
                        self::assertSame(self::REVIEW_FIRST_NOTE, $decision->ruleNote?->render(GuardFixtures::nameOf(...)), $name);
                    } else {
                        self::assertTrue($decision->isAllowed, $name);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $combinationsWithAVerb);
        self::assertGreaterThan(0, $combinationsWithAMoveChoice);
    }

    /**
     * @return list<ActorInTeam>
     */
    private static function people(): array
    {
        return [
            GuardFixtures::lena(),
            GuardFixtures::lena('tester'),
            GuardFixtures::omar(),
            GuardFixtures::mia(),
            GuardFixtures::raj(),
            GuardFixtures::sara(),
            GuardFixtures::viewer(),
            GuardFixtures::otherTeamMember(),
        ];
    }
}
