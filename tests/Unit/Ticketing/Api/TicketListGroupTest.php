<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketQueries;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\StepOrigin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ticketing may not use Workflow's Domain, so it keeps copies of the group order, labels and hints, and of the step
 * origin order; these tests fail when Workflow changes one and the copy does not follow.
 */
final class TicketListGroupTest extends TestCase
{
    #[Test]
    public function theGroupsComeInWorkflowsListOrderWithItsLabelsAndHints(): void
    {
        self::assertSame(
            array_map(static fn (Group $group): array => [$group->value, $group->label(), $group->listHint()], Group::inListOrder()),
            array_map(
                static fn (string $code, array $labelAndHint): array => [$code, ...$labelAndHint],
                array_keys(TicketListGroup::IN_LIST_ORDER),
                array_values(TicketListGroup::IN_LIST_ORDER),
            ),
        );
        self::assertSame(Group::Done->value, new \ReflectionClassConstant(TicketListGroup::class, 'DONE')->getValue());
    }

    #[Test]
    public function theStepOriginsComeInWorkflowsOrder(): void
    {
        self::assertSame(
            array_map(static fn (StepOrigin $origin): string => $origin->value, StepOrigin::cases()),
            new \ReflectionClassConstant(DbalTicketQueries::class, 'STEP_ORIGIN_ORDER')->getValue(),
        );
    }

    #[Test]
    public function aGroupGivesTheLabelAndHintOfItsCode(): void
    {
        $waiting = new TicketListGroup('wait', []);
        $done = new TicketListGroup('done', []);

        self::assertSame(['Waiting', 'Someone must answer first', 'Done', null], [$waiting->label(), $waiting->hint(), $done->label(), $done->hint()]);
    }

    #[Test]
    public function aCodeThatIsNoGroupIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TicketListGroup('blocked', []);
    }
}
