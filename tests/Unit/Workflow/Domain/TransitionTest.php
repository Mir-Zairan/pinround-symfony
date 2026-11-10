<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\AssigneeInTodo;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransitionTest extends TestCase
{
    #[Test]
    public function startsFromIsTrueOnlyForTheStepsItLeaves(): void
    {
        $transition = new Transition(Verb::GiveItTo, 'Give it to…', [CoreStep::Todo, CoreStep::Backlog], CoreStep::Todo, [Mover::Lead], true);

        self::assertTrue($transition->startsFrom(CoreStep::Todo));
        self::assertTrue($transition->startsFrom(CoreStep::Backlog));
        self::assertFalse($transition->startsFrom(CoreStep::Progress));
    }

    #[Test]
    public function aTransitionTakesAnyAssigneeInTodoUnlessToldOtherwise(): void
    {
        $transition = new Transition(Verb::PlanIt, 'Plan it', [CoreStep::Backlog], CoreStep::Todo, [Mover::Lead], true);

        self::assertSame(AssigneeInTodo::Any, $transition->assigneeInTodo);
    }

    /**
     * @return iterable<string, array{AssigneeInTodo, CoreStep, bool, bool}> assignee in To do, ticket's step, has an
     *                                                                       assignee, applies in it
     */
    public static function ticketsInAStep(): iterable
    {
        yield 'any, To do, nobody' => [AssigneeInTodo::Any, CoreStep::Todo, false, true];
        yield 'any, To do, someone' => [AssigneeInTodo::Any, CoreStep::Todo, true, true];
        yield 'nobody, To do, nobody' => [AssigneeInTodo::Nobody, CoreStep::Todo, false, true];
        yield 'nobody, To do, someone' => [AssigneeInTodo::Nobody, CoreStep::Todo, true, false];
        yield 'someone, To do, nobody' => [AssigneeInTodo::Someone, CoreStep::Todo, false, false];
        yield 'someone, To do, someone' => [AssigneeInTodo::Someone, CoreStep::Todo, true, true];
        yield 'nobody, Backlog, someone' => [AssigneeInTodo::Nobody, CoreStep::Backlog, true, true];
        yield 'someone, Backlog, nobody' => [AssigneeInTodo::Someone, CoreStep::Backlog, false, true];
        yield 'any, a step it does not leave' => [AssigneeInTodo::Any, CoreStep::Progress, true, false];
    }

    #[Test]
    #[DataProvider('ticketsInAStep')]
    public function appliesInOnlyItsFromStepsAndInToDoOnlyWithTheAssigneeItAsksFor(AssigneeInTodo $assigneeInTodo, CoreStep $step, bool $hasAssignee, bool $appliesInStep): void
    {
        $transition = new Transition(Verb::GiveItTo, 'Give it to…', [CoreStep::Todo, CoreStep::Backlog], CoreStep::Todo, [Mover::Lead], true, $assigneeInTodo);

        self::assertSame($appliesInStep, $transition->appliesIn($step, $hasAssignee));
    }

    #[Test]
    public function aTransitionLeavingNoStepIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A transition leaves at least one step.');

        new Transition(Verb::PlanIt, 'Plan it', [], CoreStep::Todo, [Mover::Lead], true);
    }

    #[Test]
    public function aTransitionNobodyMayUseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A transition has at least one mover.');

        new Transition(Verb::PlanIt, 'Plan it', [CoreStep::Backlog], CoreStep::Todo, [], true);
    }
}
