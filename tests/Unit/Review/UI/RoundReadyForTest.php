<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Review\UI\RoundReadyFor;
use App\Review\UI\VisibleTicket;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\Infrastructure\Identity\NamedUserQueries;
use App\Tests\Unit\Review\UI\Voter\InMemoryTurnUserQueries;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SHOP-1 is Omar's (the assignee) and, in the turn read model, Mia's turn, unless a test says otherwise.
 */
final class RoundReadyForTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000001';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000000002';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000003';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000000004';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000005';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000006';

    #[Test]
    public function aSharedDraftOfSomeoneButTheAssigneeIsTheirFindingsReadyForTheAssigneeWithNoTurnRead(): void
    {
        $turnUserQueries = new InMemoryTurnUserQueries([self::TICKET => self::MIA]);

        $readyForId = new RoundReadyFor($turnUserQueries, new NamedUserQueries([]))->userIdOf(self::page([]), 'draft', new UserId(self::MIA));

        self::assertSame(self::OMAR, $readyForId?->toString());
        self::assertSame([], $turnUserQueries->askedTicketIds);
    }

    /**
     * @return iterable<string, array{string, string, string|null}> the round's kind and owner, and the assignee
     */
    public static function roundsThatAreNoFindingsRound(): iterable
    {
        yield 'the assignee\'s own shared draft' => ['draft', self::OMAR, self::OMAR];
        yield 'a review round by someone but the assignee' => ['review', self::LENA, self::OMAR];
        yield 'a shared draft of a ticket nobody has' => ['draft', self::LENA, null];
    }

    #[Test]
    #[DataProvider('roundsThatAreNoFindingsRound')]
    public function aRoundThatIsNoFindingsRoundIsReadyForWhoseTurnItIs(string $roundKind, string $roundOwnerId, ?string $assigneeId): void
    {
        $turnUserQueries = new InMemoryTurnUserQueries([self::TICKET => self::MIA]);

        $readyForId = new RoundReadyFor($turnUserQueries, new NamedUserQueries([]))->userIdOf(self::page([], $assigneeId), $roundKind, new UserId($roundOwnerId));

        self::assertSame(self::MIA, $readyForId?->toString());
        self::assertSame([self::TICKET], $turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function thePersonReviewNamedWinsAndTheTurnRowIsNotRead(): void
    {
        $turnUserQueries = new InMemoryTurnUserQueries([self::TICKET => self::MIA]);

        $readyForId = new RoundReadyFor($turnUserQueries, new NamedUserQueries([]))->namedOrTurnUserIdOf(self::page([]), new UserId(self::OMAR));

        self::assertSame(self::OMAR, $readyForId?->toString());
        self::assertSame([], $turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function withNobodyNamedItIsWhoseTurnItIs(): void
    {
        $turnUserQueries = new InMemoryTurnUserQueries([self::TICKET => self::MIA]);

        $readyForId = new RoundReadyFor($turnUserQueries, new NamedUserQueries([]))->namedOrTurnUserIdOf(self::page([]), null);

        self::assertSame(self::MIA, $readyForId?->toString());
        self::assertSame([self::TICKET], $turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function aLabelsTurnOrNoTurnRowIsNobody(): void
    {
        $readyFor = new RoundReadyFor(new InMemoryTurnUserQueries([self::TICKET => null]), new NamedUserQueries([]));

        self::assertNull($readyFor->namedOrTurnUserIdOf(self::page([]), null));
        self::assertNull($readyFor->userIdOf(self::page([]), 'review', new UserId(self::LENA)));
        self::assertNull($readyFor->readyForLineOf(self::visibleTo(self::LENA, self::page([self::MIA => 'Mia Tester'])), 'review', new UserId(self::LENA)));
    }

    #[Test]
    public function theLineNamesTheFindingsRoundsAssigneeByFirstNameAndSaysYouToThem(): void
    {
        $readyFor = new RoundReadyFor(new InMemoryTurnUserQueries([self::TICKET => self::MIA]), new NamedUserQueries([]));
        $ticket = self::page([self::MIA => 'Mia Tester', self::OMAR => 'Omar Developer']);

        self::assertSame('Ready for Omar', $readyFor->readyForLineOf(self::visibleTo(self::LENA, $ticket), 'draft', new UserId(self::MIA)));
        self::assertSame('Ready for you', $readyFor->readyForLineOf(self::visibleTo(self::OMAR, $ticket), 'draft', new UserId(self::MIA)));
        self::assertSame('Ready for Mia', $readyFor->readyForLineOf(self::visibleTo(self::LENA, $ticket), 'review', new UserId(self::OMAR)), 'A review round waits for the turn.');
    }

    #[Test]
    public function anEmptyOrUnknownNameLeavesNoLine(): void
    {
        $unknown = new RoundReadyFor(new InMemoryTurnUserQueries([self::TICKET => self::LENA]), new NamedUserQueries([]));
        $empty = new RoundReadyFor(new InMemoryTurnUserQueries([self::TICKET => self::LENA]), new NamedUserQueries([self::LENA => '   ']));

        self::assertNull($unknown->readyForLineOf(self::visibleTo(self::MIA, self::page([])), 'review', new UserId(self::OMAR)));
        self::assertNull($empty->readyForLineOf(self::visibleTo(self::MIA, self::page([])), 'review', new UserId(self::OMAR)));
    }

    #[Test]
    public function aNameTheTicketPageReadIsUsedWithNoQuery(): void
    {
        $userQueries = new NamedUserQueries([self::MIA => 'Maria Identity']);

        $firstName = new RoundReadyFor(new InMemoryTurnUserQueries([]), $userQueries)->firstNameOf(new UserId(self::MIA), self::page([self::MIA => 'Mia Tester']));

        self::assertSame('Mia', $firstName);
        self::assertSame([], $userQueries->byIdsReads);
    }

    #[Test]
    public function aNameTheTicketPageDidNotReadComesFromIdentity(): void
    {
        $userQueries = new NamedUserQueries([self::LENA => 'Lena Lead']);

        $firstName = new RoundReadyFor(new InMemoryTurnUserQueries([]), $userQueries)->firstNameOf(new UserId(self::LENA), self::page([self::MIA => 'Mia Tester']));

        self::assertSame('Lena', $firstName);
        self::assertSame([[self::LENA]], $userQueries->byIdsReads);
    }

    #[Test]
    public function someoneIdentityDoesNotKnowHasNoName(): void
    {
        self::assertNull(new RoundReadyFor(new InMemoryTurnUserQueries([]), new NamedUserQueries([]))->firstNameOf(new UserId(self::LENA), self::page([])));
    }

    private static function visibleTo(string $viewerId, TicketPageView $ticket): VisibleTicket
    {
        $organizationId = new OrganizationId(self::ACME_FOODS);

        return new VisibleTicket(
            new ActorContext(new UserId($viewerId), $organizationId, Roles::none()),
            TicketVisibility::for($organizationId, new UserId($viewerId), [new TeamId(self::SHOP_TEAM)], false, null),
            $ticket,
        );
    }

    /**
     * @param array<string, string> $displayNames by user id
     */
    private static function page(array $displayNames, ?string $assigneeId = self::OMAR): TicketPageView
    {
        $facts = new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::SHOP_TEAM), new UserId(self::MIA));
        $at = new \DateTimeImmutable('2026-11-05 09:00:00+00:00');

        return new TicketPageView(
            new TicketId(self::TICKET),
            'SHOP-1',
            'Cart total shows the wrong sum',
            '',
            'bug',
            'major',
            null,
            null,
            [],
            $at,
            'Shop',
            'In progress',
            'doing',
            'Doing',
            'Mia',
            null === $assigneeId ? null : 'Omar',
            $facts,
            new TicketTurnSnapshot(
                new TicketId(self::TICKET),
                '0197a3c0-0000-7000-8000-000000005121',
                '0197a3c0-0000-7000-8000-000000005122',
                'doing',
                'doing',
                new TeamId(self::SHOP_TEAM),
                new UserId(self::LENA),
                'Lena',
                [],
                [],
                TicketVisibilityFacts::FOR_EVERYONE,
                'major',
                null,
                new UserId(self::MIA),
                null === $assigneeId ? null : new UserId($assigneeId),
            ),
            $displayNames,
            1,
        );
    }
}
