<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupTest extends TestCase
{
    #[Test]
    public function listsShowWaitingFirstAndDoneLast(): void
    {
        self::assertSame(
            [Group::Wait, Group::Check, Group::Doing, Group::Todo, Group::Done],
            Group::inListOrder(),
        );
    }

    #[Test]
    public function listPositionFollowsTheListOrder(): void
    {
        self::assertSame(0, Group::Wait->listPosition());
        self::assertSame(3, Group::Todo->listPosition());
        self::assertSame(4, Group::Done->listPosition());
    }

    #[Test]
    public function everyGroupIsInTheListOrderOnce(): void
    {
        self::assertEqualsCanonicalizing(Group::cases(), Group::inListOrder());
    }

    #[Test]
    public function labelsArePlainWords(): void
    {
        self::assertSame(
            ['todo' => 'To do', 'doing' => 'Doing', 'check' => 'Checking', 'wait' => 'Waiting', 'done' => 'Done'],
            array_combine(
                array_map(static fn (Group $group): string => $group->value, Group::cases()),
                array_map(static fn (Group $group): string => $group->label(), Group::cases()),
            ),
        );
    }

    #[Test]
    public function hintsMatchTheLists(): void
    {
        self::assertSame('Someone must answer first', Group::Wait->listHint());
        self::assertSame('Being checked', Group::Check->listHint());
        self::assertSame('Being built', Group::Doing->listHint());
        self::assertSame('Not started', Group::Todo->listHint());
        self::assertNull(Group::Done->listHint());
    }
}
