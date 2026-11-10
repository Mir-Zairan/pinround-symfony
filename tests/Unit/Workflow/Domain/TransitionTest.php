<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\AssigneeInTodo;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\Verb;
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
