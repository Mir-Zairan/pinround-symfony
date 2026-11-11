<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Domain\BlankTicketTitle;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketDescriptionTooLong;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\TicketTitleTooLong;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
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
