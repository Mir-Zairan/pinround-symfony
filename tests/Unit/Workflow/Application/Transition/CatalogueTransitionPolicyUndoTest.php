<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\MoveToUndo;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TicketState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Undo after a mistaken move (TransitionPolicy::checkUndo, gap list 1.10): Omar started work on his ticket, from To do to
 * In progress, at 09:30:00; the clock and the asker vary.
 */
final class CatalogueTransitionPolicyUndoTest extends TestCase
{
    private const string MOVED_AT = '2027-03-16 09:30:00+00:00';

    #[Test]
    public function theMoverMayUndoWithinThirtySecondsBackToTheStepTheMoveLeft(): void
    {
        foreach (['2027-03-16 09:30:00+00:00', '2027-03-16 09:30:30+00:00'] as $now) {
            $decision = GuardFixtures::policy()->checkUndo(self::ticket(), GuardFixtures::omar(), GuardFixtures::team(), self::omarsMove(), self::toDo(), GuardFixtures::steps(), new \DateTimeImmutable($now));

            self::assertTrue($decision->isAllowed, $now);
            self::assertEquals(MoveTarget::step(self::toDo()->id()), $decision->moveTarget, $now);
            self::assertFalse($decision->isLeadOnlyMove, $now);
        }
    }

    /**
     * @return iterable<string, array{ActorInTeam, MoveToUndo, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'after 30 seconds' => [GuardFixtures::omar(), self::omarsMove(), '2027-03-16 09:30:31+00:00', 'Undo works for 30 seconds after a move. Ask Lena to move it back.'];
        yield 'the lead after 30 seconds of her own move' => [GuardFixtures::lena(), self::omarsMove(GuardFixtures::LENA), '2027-03-16 09:31:00+00:00', 'Undo works for 30 seconds after a move. Use Move to another step… instead.'];
        yield 'a clock before the move' => [GuardFixtures::omar(), self::omarsMove(), '2027-03-16 09:29:59+00:00', 'Undo works for 30 seconds after a move. Ask Lena to move it back.'];
        yield 'someone who did not make the move, even the lead' => [GuardFixtures::lena(), self::omarsMove(), '2027-03-16 09:30:05+00:00', 'Only Omar can undo this move.'];
        yield 'a move that is not the last change' => [GuardFixtures::omar(), new MoveToUndo(new UserId(GuardFixtures::OMAR), GuardFixtures::ticketStepId(), new \DateTimeImmutable(self::MOVED_AT), false), '2027-03-16 09:30:05+00:00', 'The ticket changed after this move, so it can’t be undone.'];
        yield 'a ticket no longer in the step the move entered' => [GuardFixtures::omar(), new MoveToUndo(new UserId(GuardFixtures::OMAR), GuardFixtures::previousStepId(), new \DateTimeImmutable(self::MOVED_AT), true), '2027-03-16 09:30:05+00:00', 'The ticket changed after this move, so it can’t be undone.'];
        yield 'a viewer' => [GuardFixtures::viewer(), self::omarsMove(GuardFixtures::VIEWER), '2027-03-16 09:30:05+00:00', 'Managers can read and comment. Only the team moves tickets.'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function anUndoIsRefusedWithItsNote(ActorInTeam $actor, MoveToUndo $move, string $now, string $expectedNote): void
    {
        $decision = GuardFixtures::policy()->checkUndo(self::ticket(), $actor, GuardFixtures::team(), $move, self::toDo(), GuardFixtures::steps(), new \DateTimeImmutable($now));

        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->moveTarget);
        self::assertSame($expectedNote, $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function aStepThatIsNoMoveTargetAnyMoreIsRefusedWithoutANote(): void
    {
        $targetsWithoutToDo = array_values(array_filter(GuardFixtures::steps(), static fn (StepView $step): bool => $step->id() !== self::toDo()->id()));

        $decision = GuardFixtures::policy()->checkUndo(self::ticket(), GuardFixtures::omar(), GuardFixtures::team(), self::omarsMove(), self::toDo(), $targetsWithoutToDo, new \DateTimeImmutable('2027-03-16 09:30:05+00:00'));

        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->ruleNote);
    }

    #[Test]
    public function noUndoWhileAQuestionIsOpen(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA);

        $decision = GuardFixtures::policy()->checkUndo($ticket, GuardFixtures::omar(), GuardFixtures::team(), self::omarsMove(), self::toDo(), GuardFixtures::steps(), new \DateTimeImmutable('2027-03-16 09:30:05+00:00'));

        self::assertFalse($decision->isAllowed);
        self::assertNotNull($decision->ruleNote);
    }

    private static function ticket(): TicketState
    {
        return GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
    }

    private static function omarsMove(string $movedBy = GuardFixtures::OMAR): MoveToUndo
    {
        return new MoveToUndo(new UserId($movedBy), GuardFixtures::ticketStepId(), new \DateTimeImmutable(self::MOVED_AT), true);
    }

    private static function toDo(): StepView
    {
        return GuardFixtures::steps()[7];
    }
}
