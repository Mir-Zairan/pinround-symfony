<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\MoveSideEffects;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where each verb takes the ticket (FR-WF-16); null means "back to the previous step".
 */
final class MoveSideEffectsTest extends TestCase
{
    /**
     * @return iterable<string, array{Verb, Group|null, bool, CoreStep|null}> verb, group of the company or team step,
     *                                                                        has a previous step, expected core target
     */
    public static function specialTargets(): iterable
    {
        yield 'Input given with a previous step goes back to it' => [Verb::InputGiven, null, true, null];
        yield 'Input given without a previous step goes to To do' => [Verb::InputGiven, null, false, CoreStep::Todo];
        yield 'Go on with a previous step goes back to it' => [Verb::GoOn, null, true, null];
        yield 'Go on without a previous step goes to In progress' => [Verb::GoOn, null, false, CoreStep::Progress];
        yield 'Move on from To do goes to In progress' => [Verb::MoveOn, Group::Todo, false, CoreStep::Progress];
        yield 'Move on from Doing goes to Code review' => [Verb::MoveOn, Group::Doing, false, CoreStep::Review];
        yield 'Move on from Checking goes to Testing' => [Verb::MoveOn, Group::Check, false, CoreStep::Test];
        yield 'Move on from Checking ignores the previous step' => [Verb::MoveOn, Group::Check, true, CoreStep::Test];
        yield 'Move on from Waiting with a previous step goes back to it' => [Verb::MoveOn, Group::Wait, true, null];
        yield 'Move on from Waiting without a previous step goes to In progress' => [Verb::MoveOn, Group::Wait, false, CoreStep::Progress];
    }

    #[Test]
    #[DataProvider('specialTargets')]
    public function theGoOnVerbsAndMoveOnPickTheirTargetFromThePreviousStepAndTheGroup(Verb $verb, ?Group $group, bool $hasPreviousStep, ?CoreStep $expected): void
    {
        $transition = new TransitionCatalogue()->for($verb);

        self::assertSame($expected, new MoveSideEffects()->coreTargetOf($transition, $group, $hasPreviousStep));
    }

    #[Test]
    public function everyOtherVerbGoesToItsRowsTargetWhateverThePreviousStepIs(): void
    {
        $catalogue = new TransitionCatalogue();
        $checked = 0;
        foreach ($catalogue->all() as $transition) {
            if (\in_array($transition->verb, [Verb::InputGiven, Verb::GoOn, Verb::MoveOn], true)) {
                continue;
            }

            self::assertSame($transition->to, new MoveSideEffects()->coreTargetOf($transition, null, true), $transition->verb->value);
            self::assertSame($transition->to, new MoveSideEffects()->coreTargetOf($transition, null, false), $transition->verb->value);
            ++$checked;
        }

        self::assertSame(11, $checked);
    }

    #[Test]
    public function giveItToFromBacklogGoesToToDo(): void
    {
        $transition = new TransitionCatalogue()->for(Verb::GiveItTo);

        self::assertTrue($transition->startsFrom(CoreStep::Backlog));
        self::assertSame(CoreStep::Todo, new MoveSideEffects()->coreTargetOf($transition, null, false));
    }

    /**
     * @return iterable<string, array{Group|null}>
     */
    public static function groupsMoveOnCannotLeave(): iterable
    {
        yield 'no group, a core step' => [null];
        yield 'Done' => [Group::Done];
    }

    #[Test]
    #[DataProvider('groupsMoveOnCannotLeave')]
    public function moveOnOutsideACompanyOrTeamStepBeforeDoneIsALogicError(?Group $group): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('"Move on to…" leaves a company or team step outside the Done group.');

        new MoveSideEffects()->coreTargetOf(new TransitionCatalogue()->for(Verb::MoveOn), $group, false);
    }
}
