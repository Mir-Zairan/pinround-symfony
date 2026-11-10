<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Application\Turn\OrderedTurnRules;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Api's TurnRules as the container gives it to another module.
 */
final class TurnRulesWiringTest extends KernelTestCase
{
    #[Test]
    public function theApiResolvesToTheOrderedRulesAndGivesTheLeadABacklogTicket(): void
    {
        $rules = self::getContainer()->get('test.workflow_turn_rules');
        self::assertInstanceOf(OrderedTurnRules::class, $rules);
        $lena = new UserId('0190a000-0000-7000-8000-00000000000a');
        $ticket = new TicketState('0190a000-0000-7000-8000-0000000000f3', 'backlog', 'todo', new TeamId('0190a000-0000-7000-8000-0000000000f2'));

        $turn = $rules->turnFor($ticket, new TeamFacts($lena, 'Lena'));

        self::assertNotNull($turn->userId);
        self::assertTrue($turn->userId->equals($lena));
        self::assertSame(NeedsYouKind::Build, $turn->kind);
    }
}
