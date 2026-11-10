<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Application\Turn\OrderedTurnRules;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 1,000 seeded random tickets, teams and rules: whoever the turn rules give the turn to gets at least one verb from the
 * transition policy, so the ticket page never tells someone it is their turn and offers them no button.
 *
 * Left out by design: a ticket without a team (nobody moves it), an open question (the ticket is paused for everyone),
 * a check of a new ticket by someone other than the lead (that turn is about Give it to… in To do only), and a turn
 * held by a label or by someone outside the team.
 */
final class TurnHolderVerbsPropertyTest extends TestCase
{
    private const int CASES = 1000;

    #[Test]
    public function theTurnHolderAlwaysHasAVerb(): void
    {
        $turnRules = new OrderedTurnRules(new ReviewerChoice(), new TesterChoice());
        $policy = GuardFixtures::policy();
        $steps = GuardFixtures::stepsWithTeamSteps();
        $casesChecked = 0;

        for ($seed = 1; $seed <= self::CASES; ++$seed) {
            $random = new Randomizer(new Mt19937($seed));
            $ticket = GuardFixtures::randomTicket($random, $steps);
            $hasTesters = 1 === $random->getInt(0, 1);
            $team = GuardFixtures::team(testers: $hasTesters, hasOtherDeveloper: 1 === $random->getInt(0, 1));
            $rules = new RuleSetView(1 === $random->getInt(0, 1), 1 === $random->getInt(0, 1), 1 === $random->getInt(0, 1));

            if (null === $ticket->teamId || null !== $ticket->askedUserId) {
                continue;
            }

            $turn = $turnRules->turnFor($ticket, $team);
            $holder = null === $turn->userId ? null : self::actorFor($turn->userId, $hasTesters);
            if (null === $holder || (NeedsYouKind::Check === $turn->kind && GuardFixtures::LENA !== $holder->userId->toString())) {
                continue;
            }

            ++$casesChecked;
            self::assertNotSame(
                [],
                $policy->available($ticket, $holder, $rules, $team)->transitions,
                \sprintf('seed %d: %s has the turn (%s) and no verb; %s', $seed, GuardFixtures::nameOf($holder->userId), $turn->kind->name ?? 'none', self::describe($ticket, $team, $rules, $steps)),
            );
        }

        self::assertGreaterThan(self::CASES / 4, $casesChecked);
    }

    /**
     * Lena leads and develops, Omar develops; Mia has the job tester only while the team has testers, so her verbs in a
     * team without them come from being the ticket's tester, not from her job.
     */
    private static function actorFor(UserId $userId, bool $hasTesters): ?ActorInTeam
    {
        return match ($userId->toString()) {
            GuardFixtures::LENA => GuardFixtures::lena(),
            GuardFixtures::OMAR => GuardFixtures::omar(),
            GuardFixtures::MIA => $hasTesters ? GuardFixtures::mia() : new ActorInTeam($userId, ActorInTeam::TEAM_ROLE_MEMBER, ActorInTeam::JOB_OTHER),
            default => null,
        };
    }

    /**
     * @param list<StepView> $steps
     */
    private static function describe(TicketState $ticket, TeamFacts $team, RuleSetView $rules, array $steps): string
    {
        $stepName = 'unknown step';
        foreach ($steps as $step) {
            if ($step->id() === $ticket->stepId) {
                $stepName = $step->name();
            }
        }

        $person = static fn (?UserId $userId): string => null === $userId ? 'nobody' : GuardFixtures::nameOf($userId);

        return \sprintf(
            'step %s (group %s), assignee %s, reviewer %s, tester %s, reporter %s, waiting on %s%s, checker %s%s, previous step %s; team with%s testers and with%s another developer; rules tester_moves_done %s, no_self_review %s, review_before_test %s',
            $stepName,
            $ticket->groupCode,
            $person($ticket->assigneeId),
            $person($ticket->reviewerId),
            $person($ticket->testerId),
            $person($ticket->reporterId),
            $person($ticket->waitingOnUserId),
            null === $ticket->waitingOnLabel ? '' : ' or '.$ticket->waitingOnLabel,
            $person($ticket->checkerId),
            $ticket->isCheckNeeded ? ' (check needed)' : '',
            null === $ticket->previousStepId ? 'none' : 'set',
            [] === $team->testers ? 'out' : '',
            \count($team->developers) > 1 ? '' : 'out',
            $rules->testerMovesDone ? 'on' : 'off',
            $rules->noSelfReview ? 'on' : 'off',
            $rules->reviewBeforeTest ? 'on' : 'off',
        );
    }
}
