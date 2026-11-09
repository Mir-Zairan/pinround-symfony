<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoreStepTest extends TestCase
{
    #[Test]
    public function exactlyEightCoreStepsEachHaveTheirGroupAndName(): void
    {
        $expected = [
            'backlog' => ['Backlog', Group::Todo],
            'todo' => ['To do', Group::Todo],
            'progress' => ['In progress', Group::Doing],
            'review' => ['Code review', Group::Check],
            'test' => ['Testing', Group::Check],
            'input' => ['Needs input', Group::Wait],
            'hold' => ['On hold', Group::Wait],
            'done' => ['Done', Group::Done],
        ];

        $actual = [];
        foreach (CoreStep::cases() as $coreStep) {
            $actual[$coreStep->value] = [$coreStep->stepName(), $coreStep->group()];
        }

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function positionsFollowTheCoreOrderOfTheWorkflowEngineFromZero(): void
    {
        $positions = [];
        foreach (CoreStep::cases() as $coreStep) {
            $positions[$coreStep->value] = $coreStep->position();
        }

        self::assertSame(['backlog' => 0, 'todo' => 1, 'progress' => 2, 'review' => 3, 'test' => 4, 'input' => 5, 'hold' => 6, 'done' => 7], $positions);
    }
}
