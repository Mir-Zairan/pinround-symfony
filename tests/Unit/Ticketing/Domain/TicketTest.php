<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Domain\BlankTicketTitle;
use App\Ticketing\Domain\InvalidWaitingOn;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketDescriptionTooLong;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\TicketTitleTooLong;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
use App\Ticketing\Domain\WaitingOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000031a1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000031d1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000031c1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000031e1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000031b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000031b2';
    private const string BACKLOG_STEP = '0197a3c0-0000-7000-8000-0000000031f1';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-0000000031f2';
    private const string IN_PROGRESS_STEP = '0197a3c0-0000-7000-8000-0000000031f3';
    private const string NEEDS_INPUT_STEP = '0197a3c0-0000-7000-8000-0000000031f4';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000031f5';
    private const string SHIPPED_STEP = '0197a3c0-0000-7000-8000-0000000031f6';

    #[Test]
    public function aTicketWithAnAssigneeStartsInTheToDoStep(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));

        self::assertSame([self::TO_DO_STEP, self::OMAR], [$ticket->stepId(), $ticket->assignee()?->toString()]);
    }

    #[Test]
    public function aTicketWithoutAnAssigneeStartsInTheBacklogStep(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', null);

        self::assertSame([self::BACKLOG_STEP, null], [$ticket->stepId(), $ticket->assignee()]);
    }

    #[Test]
    public function aNewTicketKeepsWhatItWasReportedWithAndATrimmedTitle(): void
    {
        $reportedAt = new \DateTimeImmutable('2027-03-15 10:00:00+00:00');
        $ticket = self::reported("  Checkout button is hidden \n", 'Steps: open the cart.', null, $reportedAt);

        self::assertSame(
            [self::TICKET, self::ACME_FOODS, self::SHOP_PROJECT, 'SHOP-12', 'Checkout button is hidden', 'Steps: open the cart.', TicketType::Bug, Priority::Major, Visibility::Team, self::LENA, self::SHOP_TEAM, $reportedAt],
            [
                $ticket->id()->toString(), $ticket->organizationId()->toString(), $ticket->projectId()->toString(), $ticket->key()->toString(),
                $ticket->title(), $ticket->description(), $ticket->type(), $ticket->priority(), $ticket->visibility(),
                $ticket->reporter()->toString(), $ticket->team()?->toString(), $ticket->createdAt(),
            ],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTitles(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces and a newline' => ["   \n "];
    }

    #[Test]
    #[DataProvider('blankTitles')]
    public function aBlankTitleIsRefused(string $title): void
    {
        $this->expectException(BlankTicketTitle::class);
        self::reported($title, '', null);
    }

    #[Test]
    public function aTitleOf201CharactersIsRefused(): void
    {
        $this->expectException(TicketTitleTooLong::class);
        self::reported(str_repeat('é', 201), '', null);
    }

    #[Test]
    public function aTitleOf200CharactersAndADescriptionOf50000CharactersAreKept(): void
    {
        $ticket = self::reported(' '.str_repeat('é', 200).' ', str_repeat('é', 50_000), null);

        self::assertSame([200, 50_000], [mb_strlen($ticket->title()), mb_strlen($ticket->description())]);
    }

    #[Test]
    public function aDescriptionOf50001CharactersIsRefused(): void
    {
        $this->expectException(TicketDescriptionTooLong::class);
        self::reported('Checkout button is hidden', str_repeat('é', 50_001), null);
    }

    #[Test]
    public function aMoveKeepsTheStepItLeftAndTheMoveTime(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));
        $movedAt = new \DateTimeImmutable('2027-03-16 09:30:00+00:00');

        $ticket->move(self::IN_PROGRESS_STEP, 'doing', false, null, $movedAt);

        self::assertSame([self::IN_PROGRESS_STEP, self::TO_DO_STEP, null, null, null, $movedAt], self::moveStateOf($ticket));
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:00:00+00:00'), $ticket->createdAt());
    }

    #[Test]
    public function enteringTheDoneGroupClosesTheTicketAndLeavingItReopensIt(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));
        $closedAt = new \DateTimeImmutable('2027-03-16 09:30:00+00:00');
        $ticket->move(self::DONE_STEP, 'done', false, null, $closedAt);
        self::assertSame($closedAt, $ticket->closedAt());

        $ticket->move(self::SHIPPED_STEP, 'done', false, null, new \DateTimeImmutable('2027-03-17 08:00:00+00:00'));
        self::assertSame([self::SHIPPED_STEP, self::DONE_STEP, $closedAt], [$ticket->stepId(), $ticket->previousStepId(), $ticket->closedAt()]);

        $ticket->move(self::IN_PROGRESS_STEP, 'doing', false, null, new \DateTimeImmutable('2027-03-18 08:00:00+00:00'));
        self::assertSame([self::IN_PROGRESS_STEP, self::SHIPPED_STEP, null], [$ticket->stepId(), $ticket->previousStepId(), $ticket->closedAt()]);
    }

    #[Test]
    public function enteringNeedsInputWaitsOnThePersonAndLeavingItClearsThem(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));
        $askedAt = new \DateTimeImmutable('2027-03-16 09:30:00+00:00');
        $ticket->move(self::NEEDS_INPUT_STEP, 'wait', true, WaitingOn::ofPerson(new UserId(self::LENA)), $askedAt);
        self::assertSame([self::NEEDS_INPUT_STEP, self::TO_DO_STEP, null, self::LENA, null, $askedAt], self::moveStateOf($ticket));

        $answeredAt = new \DateTimeImmutable('2027-03-17 08:00:00+00:00');
        $ticket->move(self::TO_DO_STEP, 'todo', false, null, $answeredAt);
        self::assertSame([self::TO_DO_STEP, self::NEEDS_INPUT_STEP, null, null, null, $answeredAt], self::moveStateOf($ticket));
    }

    #[Test]
    public function enteringNeedsInputWaitsOnATrimmedLabel(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));
        $askedAt = new \DateTimeImmutable('2027-03-16 09:30:00+00:00');

        $ticket->move(self::NEEDS_INPUT_STEP, 'wait', true, WaitingOn::ofLabel(' Customer '), $askedAt);

        self::assertSame([self::NEEDS_INPUT_STEP, self::TO_DO_STEP, null, null, 'Customer', $askedAt], self::moveStateOf($ticket));
    }

    /**
     * @return iterable<string, array{string, string, bool, WaitingOn|null, class-string<\Throwable>, string}>
     */
    public static function refusedMoves(): iterable
    {
        yield 'waiting on someone in a step that is not Needs input' => [
            self::IN_PROGRESS_STEP, 'doing', false, WaitingOn::ofPerson(new UserId(self::LENA)),
            InvalidWaitingOn::class, 'Only a ticket in Needs input waits on someone.',
        ];
        yield 'Needs input without anyone to wait on' => [
            self::NEEDS_INPUT_STEP, 'wait', true, null,
            InvalidWaitingOn::class, 'A ticket in Needs input waits on a person or a label.',
        ];
        yield 'a group that does not exist' => [
            self::IN_PROGRESS_STEP, 'started', false, null,
            \InvalidArgumentException::class, 'A step group is one of todo, doing, check, wait or done.',
        ];
    }

    /**
     * @param class-string<\Throwable> $expectedException
     */
    #[Test]
    #[DataProvider('refusedMoves')]
    public function aRefusedMoveLeavesTheTicketAsItWas(string $stepId, string $groupCode, bool $isNeedsInputStep, ?WaitingOn $waitingOn, string $expectedException, string $expectedMessage): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));
        $before = self::moveStateOf($ticket);

        try {
            $ticket->move($stepId, $groupCode, $isNeedsInputStep, $waitingOn, new \DateTimeImmutable('2027-03-16 09:30:00+00:00'));
            self::fail('The move was not refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame([$expectedException, $expectedMessage], [$refusal::class, $refusal->getMessage()]);
        }

        self::assertSame($before, self::moveStateOf($ticket));
    }

    #[Test]
    public function aNewTicketHasNoTurnPeopleNoReleaseNoRiskCheckAndItsFirstVersion(): void
    {
        $ticket = self::reported('Checkout button is hidden', '', new UserId(self::OMAR));

        self::assertSame(
            [null, null, null, null, null, null, null, false, 1],
            [
                $ticket->previousStepId(), $ticket->pendingAssignee(), $ticket->reviewer(), $ticket->tester(), $ticket->waitingOnUser(),
                $ticket->waitingOnLabel(), $ticket->release(), $ticket->isRiskCheck(), $ticket->version(),
            ],
        );
    }

    /**
     * @return array{string, string|null, \DateTimeImmutable|null, string|null, string|null, \DateTimeImmutable}
     */
    private static function moveStateOf(Ticket $ticket): array
    {
        return [
            $ticket->stepId(), $ticket->previousStepId(), $ticket->closedAt(), $ticket->waitingOnUser()?->toString(), $ticket->waitingOnLabel(), $ticket->updatedAt(),
        ];
    }

    private static function reported(string $title, string $description, ?UserId $assignee, ?\DateTimeImmutable $reportedAt = null): Ticket
    {
        return Ticket::report(
            new TicketId(self::TICKET),
            new OrganizationId(self::ACME_FOODS),
            new ProjectId(self::SHOP_PROJECT),
            new TicketKey(new ProjectKey('SHOP'), 12),
            $title,
            $description,
            TicketType::Bug,
            Priority::Major,
            Visibility::Team,
            new UserId(self::LENA),
            $assignee,
            new TeamId(self::SHOP_TEAM),
            self::BACKLOG_STEP,
            self::TO_DO_STEP,
            $reportedAt ?? new \DateTimeImmutable('2027-03-15 10:00:00+00:00'),
        );
    }
}
