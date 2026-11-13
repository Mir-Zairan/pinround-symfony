<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\TicketPageParts;
use App\Web\UI\Component\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The status pill's group, which the ticket page and a move's stream answer both take from TicketPageParts.
 */
final class TicketPagePartsTest extends TestCase
{
    #[Test]
    public function everyWorkflowGroupHasAStatusPillGroup(): void
    {
        $pillGroups = array_map(TicketPageParts::pillGroupOf(...), ['todo', 'doing', 'check', 'wait', 'done']);

        self::assertSame(array_map(static fn (Group $group): string => $group->value, Group::cases()), $pillGroups);
    }

    #[Test]
    public function anUnknownGroupHasNoStatusPill(): void
    {
        $this->expectExceptionObject(new \LogicException('A step group "checking" has no status pill.'));

        TicketPageParts::pillGroupOf('checking');
    }
}
