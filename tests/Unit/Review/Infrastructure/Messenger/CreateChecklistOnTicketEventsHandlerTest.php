<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Infrastructure\Messenger;

use App\Review\Infrastructure\Messenger\CreateChecklistOnTicketEventsHandler;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\Application\RecordingChecklistCommands;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Api\Event\TicketMoved;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreateChecklistOnTicketEventsHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fac01';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000fac02';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000fac03';
    private const string STEP = '0197a3c0-0000-7000-8000-0000000fac04';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000fac09';

    #[Test]
    public function bothTicketEventsAreHandledOnTheEventBus(): void
    {
        $handled = [];
        foreach (new \ReflectionClass(CreateChecklistOnTicketEventsHandler::class)->getMethods() as $method) {
            foreach ($method->getAttributes(AsMessageHandler::class) as $attribute) {
                $parameterType = $method->getParameters()[0]->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $parameterType);
                $handled[$parameterType->getName()] = $attribute->newInstance()->bus;
            }
        }

        self::assertSame([TicketCreated::class => 'event.bus', TicketMoved::class => 'event.bus'], $handled);
    }

    #[Test]
    public function startWorkAsksNoStepAndStampsTheClockTimeNotTheEventTime(): void
    {
        $commands = new RecordingChecklistCommands();
        $tickets = new InMemoryTicketTurnQueries([], new TicketId(self::BROKEN_TICKET));
        $handler = new CreateChecklistOnTicketEventsHandler($commands, $tickets, new MockClock('2027-03-16 09:30:00 UTC'));

        $handler->onTicketMoved(new TicketMoved(self::ACME_FOODS, self::TICKET, 'SHOP-1', self::STEP, self::STEP, 'start_work', self::LENA, new \DateTimeImmutable('2027-03-15 08:00:00 UTC')));

        self::assertSame([[self::TICKET, '2027-03-16 09:30:00']], $commands->created);
        self::assertSame([], $tickets->askedTicketIds);
    }

    #[Test]
    public function aTicketOfNoTeamHasNoCoreCodeAndMakesNothing(): void
    {
        $commands = new RecordingChecklistCommands();
        $snapshot = new TicketTurnSnapshot(new TicketId(self::TICKET), self::STEP, self::STEP, null, 'todo', null, null, null, [], [], 'all', 'major', null, new UserId(self::LENA));
        $handler = new CreateChecklistOnTicketEventsHandler($commands, new InMemoryTicketTurnQueries([$snapshot], new TicketId(self::BROKEN_TICKET)), new MockClock('2027-03-16 09:30:00 UTC'));

        $handler->onTicketCreated(new TicketCreated(self::ACME_FOODS, self::TICKET, self::STEP, self::STEP, self::LENA, null));
        $handler->onTicketCreated(new TicketCreated(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000fac0a', self::STEP, self::STEP, self::LENA, null));

        self::assertSame([], $commands->created);
    }
}
