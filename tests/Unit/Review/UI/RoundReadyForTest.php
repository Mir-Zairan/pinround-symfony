<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Review\UI\RoundReadyFor;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\Infrastructure\Identity\NamedUserQueries;
use App\Tests\Unit\Review\UI\Voter\InMemoryTurnUserQueries;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundReadyForTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000001';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000000002';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000003';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000000004';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000005';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000006';

    #[Test]
    public function thePersonReviewNamedWinsAndTheTurnRowIsNotRead(): void
    {
        $turnUserQueries = new InMemoryTurnUserQueries([self::TICKET => self::MIA]);

        $readyForId = new RoundReadyFor($turnUserQueries, new NamedUserQueries([]))->userIdOf(self::page([]), new UserId(self::OMAR));

        self::assertSame(self::OMAR, $readyForId?->toString());
        self::assertSame([], $turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function withNobodyNamedItIsWhoseTurnItIs(): void
    {
        $turnUserQueries = new InMemoryTurnUserQueries([self::TICKET => self::MIA]);

        $readyForId = new RoundReadyFor($turnUserQueries, new NamedUserQueries([]))->userIdOf(self::page([]), null);

        self::assertSame(self::MIA, $readyForId?->toString());
        self::assertSame([self::TICKET], $turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function aLabelsTurnOrNoTurnRowIsNobody(): void
    {
        $readyFor = new RoundReadyFor(new InMemoryTurnUserQueries([self::TICKET => null]), new NamedUserQueries([]));

        self::assertNull($readyFor->userIdOf(self::page([]), null));
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

    /**
     * @param array<string, string> $displayNames by user id
     */
    private static function page(array $displayNames): TicketPageView
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
            'Omar',
            $facts,
            new TicketTurnSnapshot(new TicketId(self::TICKET), '0197a3c0-0000-7000-8000-000000005121', '0197a3c0-0000-7000-8000-000000005122', 'doing', 'doing', new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), 'Omar', [], [], TicketVisibilityFacts::FOR_EVERYONE, 'major', null, new UserId(self::MIA)),
            $displayNames,
            1,
        );
    }
}
