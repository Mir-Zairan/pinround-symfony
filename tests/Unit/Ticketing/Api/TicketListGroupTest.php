<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\Ticketing\Api\TicketListGroup;
use App\Workflow\Domain\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ticketing may not use Workflow's Domain, so it keeps a copy of Done's group code; this test fails when Workflow
 * changes it and the copy does not follow.
 */
final class TicketListGroupTest extends TestCase
{
    #[Test]
    public function doneIsWorkflowsGroupCodeOfDone(): void
    {
        self::assertSame(Group::Done->value, new \ReflectionClassConstant(TicketListGroup::class, 'DONE')->getValue());
    }
}
