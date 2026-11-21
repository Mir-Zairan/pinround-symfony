<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Review\Api\RoundView;
use App\Review\Api\TicketRounds;
use App\Review\Application\RoundsVisibleTo;
use App\Review\UI\RoundBars;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bar's lines for a person the ticket's page did not name, which RoundBarTest cannot seed through a team member.
 */
final class RoundBarsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000001';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000000002';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000003';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000000004';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000005';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000006';
    private const string FORMER_MEMBER = '0197a3c0-0000-7000-8000-000000000007';

    #[Test]
    public function aTurnPersonIdentityKnowsByNameOnlyIsNamedByTheirFirstName(): void
    {
        $bar = self::roundBars(['Lena Lead'])->forTicket(self::visibleTicketOf(self::OMAR));

        self::assertNotNull($bar);
        self::assertSame(2, $bar->roundNumber);
        self::assertSame('Ready for Lena', $bar->readyForLine);
    }

    #[Test]
    public function aTurnPersonIdentityDoesNotKnowLeavesTheBarWithNoReadyForLine(): void
    {
        $bar = self::roundBars([], turnUserId: self::FORMER_MEMBER)->forTicket(self::visibleTicketOf(self::OMAR));

        self::assertNotNull($bar);
        self::assertSame(2, $bar->roundNumber);
        self::assertNull($bar->readyForLine);
    }

    #[Test]
    public function anEmptyNameLeavesTheBarWithNoReadyForLine(): void
    {
        $bar = self::roundBars(['   '])->forTicket(self::visibleTicketOf(self::OMAR));

        self::assertNotNull($bar);
        self::assertSame(2, $bar->roundNumber);
        self::assertNull($bar->readyForLine);
    }

    /**
     * @param list<string> $lenasDisplayName Identity's name for Lena, if it knows her
     */
    private static function roundBars(array $lenasDisplayName, string $turnUserId = self::LENA): RoundBars
    {
        $rounds = [
            new RoundView('0197a3c0-0000-7000-8000-000000000102', 2, 'review', new UserId(self::MIA), new \DateTimeImmutable('2026-11-05 09:00:00+00:00'), 'open', []),
            new RoundView('0197a3c0-0000-7000-8000-000000000101', 1, 'review', new UserId(self::MIA), new \DateTimeImmutable('2026-11-04 09:00:00+00:00'), 'closed', []),
        ];
        $roundsVisibleTo = new class($rounds) implements RoundsVisibleTo {
            /**
             * @param list<RoundView> $rounds
             */
            public function __construct(private readonly array $rounds)
            {
            }

            public function forVisibleTo(TicketId $ticketId, TicketVisibility $viewer): TicketRounds
            {
                return new TicketRounds($this->rounds);
            }
        };
        $displayNames = [] === $lenasDisplayName ? [] : [self::LENA => $lenasDisplayName[0]];

        return new RoundBars($roundsVisibleTo, new RoundReadyFor(new InMemoryTurnUserQueries([self::TICKET => $turnUserId]), new NamedUserQueries($displayNames)));
    }

    private static function visibleTicketOf(string $viewerId): VisibleTicket
    {
        $organizationId = new OrganizationId(self::ACME_FOODS);
        $facts = new TicketVisibilityFacts($organizationId, TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::SHOP_TEAM), new UserId(self::MIA));
        $ticket = new TicketPageView(
            new TicketId(self::TICKET),
            'SHOP-1',
            'Cart total shows the wrong sum',
            '',
            'bug',
            'major',
            null,
            null,
            [],
            new \DateTimeImmutable('2026-11-04 10:00:00+00:00'),
            'Shop',
            'In progress',
            'doing',
            'Doing',
            'Mia',
            'Omar',
            $facts,
            new TicketTurnSnapshot(new TicketId(self::TICKET), '0197a3c0-0000-7000-8000-000000005121', '0197a3c0-0000-7000-8000-000000005122', 'doing', 'doing', new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), 'Omar', [], [], TicketVisibilityFacts::FOR_EVERYONE, 'major', null, new UserId(self::MIA)),
            [self::MIA => 'Mia Tester', self::OMAR => 'Omar Developer'],
            1,
        );

        return new VisibleTicket(
            new ActorContext(new UserId($viewerId), $organizationId, Roles::none()),
            TicketVisibility::for($organizationId, new UserId($viewerId), [new TeamId(self::SHOP_TEAM)], false, null),
            $ticket,
        );
    }
}
