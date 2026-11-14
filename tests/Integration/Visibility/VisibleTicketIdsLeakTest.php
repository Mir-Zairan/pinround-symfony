<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Identity\Infrastructure\Doctrine\DbalUserQueries;
use App\Insight\Infrastructure\Doctrine\DbalTurnUserQueries;
use App\Organization\Infrastructure\Doctrine\DbalTeamPeopleQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketQueries;
use App\Ticketing\Infrastructure\Doctrine\TicketTeamLookup;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * TicketQueries::visibleTicketIdsOf, the batch check another module asks before it shows a ticket id it keeps: each
 * viewer gets back exactly the cast tickets they may see. Ben sees a Beta Bakery ticket of his own, which every Acme
 * Foods viewer must not get back. Each case runs in a rolled-back transaction under the viewer's own organization.
 */
final class VisibleTicketIdsLeakTest extends VisibilityLeakCast
{
    use ReadsSentSql;

    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000057d3';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000057e3';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000057e9';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        new TicketRows(self::migrator(), self::tenantContext())->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::VIEWERS['Ben'][0]);
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function aViewerGetsBackExactlyTheTicketsTheyMaySeeInOneQuery(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expected = array_keys(array_filter([
            self::teamOnlyTicketId() => $seesTeamOnly,
            self::NO_TEAM_TICKET => $seesNoTeamTicket,
            self::BAKERY_TICKET => 'Ben' === $viewer,
        ]));
        $asked = [self::teamOnlyTicketId(), self::NO_TEAM_TICKET, self::teamOnlyTicketId(), self::BAKERY_TICKET, self::UNKNOWN_TICKET];

        [$visible, $queryCount] = self::asViewer($viewer, function () use ($viewer, $asked): array {
            $visibility = $this->visibilityOf($viewer);
            $this->debugData()->reset();
            $visible = $this->ticketQueries()->visibleTicketIdsOf($visibility, array_map(static fn (string $id): TicketId => new TicketId($id), $asked));

            return [$visible, self::queryCountOf($this->debugData())];
        });

        self::assertSame($expected, self::idsOf($visible));
        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function anEmptyListIsAnsweredWithNoneAndNoQuery(): void
    {
        [$visible, $queryCount] = self::asViewer('Sara', function (): array {
            $visibility = $this->visibilityOf('Sara');
            $this->debugData()->reset();
            $visible = $this->ticketQueries()->visibleTicketIdsOf($visibility, []);

            return [$visible, self::queryCountOf($this->debugData())];
        });

        self::assertSame([], $visible);
        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function twoHundredIdsAreChecked(): void
    {
        $asked = [new TicketId(self::teamOnlyTicketId())];
        for ($number = 1; $number < TicketQueries::MAX_CHECKED_TICKET_IDS; ++$number) {
            $asked[] = new TicketId(\sprintf('0197a3c0-0000-7000-8005-%012x', $number));
        }
        self::assertCount(200, $asked);

        $visible = self::asViewer('Lena', fn (): array => $this->ticketQueries()->visibleTicketIdsOf($this->visibilityOf('Lena'), $asked));

        self::assertSame([self::teamOnlyTicketId()], self::idsOf($visible));
    }

    #[Test]
    public function moreThanTwoHundredIdsAreRefusedBeforeAnyQuery(): void
    {
        $asked = [];
        for ($number = 0; $number <= TicketQueries::MAX_CHECKED_TICKET_IDS; ++$number) {
            $asked[] = new TicketId(\sprintf('0197a3c0-0000-7000-8005-%012x', $number));
        }
        self::assertCount(201, $asked);

        [$refusal, $queryCount] = self::asViewer('Sara', function () use ($asked): array {
            $visibility = $this->visibilityOf('Sara');
            $this->debugData()->reset();
            try {
                $this->ticketQueries()->visibleTicketIdsOf($visibility, $asked);
            } catch (\InvalidArgumentException $refusal) {
                return [$refusal->getMessage(), self::queryCountOf($this->debugData())];
            }
            self::fail('201 ids were checked.');
        });

        self::assertSame('At most 200 tickets are checked for visibility at once.', $refusal);
        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function aViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $lenasVisibility = self::asViewer('Lena', fn (): TicketVisibility => $this->visibilityOf('Lena'));

        [$refusal, $queryCount] = self::transactions()->rolledBackAs(self::BETA_BAKERY, function () use ($lenasVisibility): array {
            $this->debugData()->reset();
            try {
                $this->ticketQueries()->visibleTicketIdsOf($lenasVisibility, [new TicketId(self::BAKERY_TICKET)]);
            } catch (\LogicException $refusal) {
                return [$refusal->getMessage(), self::queryCountOf($this->debugData())];
            }
            self::fail('Lena\'s visibility was read under Beta Bakery.');
        });

        self::assertSame('Ticket visibility is read under the viewer\'s own organization as tenant.', $refusal);
        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function aCheckOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        $saraVisibility = self::asViewer('Sara', fn (): TicketVisibility => $this->visibilityOf('Sara'));
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->ticketQueries()->visibleTicketIdsOf($saraVisibility, [new TicketId(self::teamOnlyTicketId())]);
            self::fail('The check ran outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Ticket visibility is read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }
        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    /**
     * The SQL names Acme Foods while the transaction's tenant is Beta Bakery, so only row-level security hides the
     * tickets Sara, an admin, may see; the same objects under Acme Foods give both back.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTickets(): void
    {
        $saraVisibility = self::asViewer('Sara', fn (): TicketVisibility => $this->visibilityOf('Sara'));
        $asked = [new TicketId(self::teamOnlyTicketId()), new TicketId(self::NO_TEAM_TICKET)];
        $checkAsked = fn (Connection $connection): array => self::idsOf($this->ticketQueriesOn($connection)->visibleTicketIdsOf($saraVisibility, $asked));
        self::assertSame([self::teamOnlyTicketId(), self::NO_TEAM_TICKET], $this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $checkAsked));

        self::assertSame([], $this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $checkAsked));
    }

    private function ticketQueriesOn(Connection $connection): DbalTicketQueries
    {
        $stepQueries = self::createStub(StepQueries::class);

        return new DbalTicketQueries(
            $connection,
            self::tenantContext(),
            $stepQueries,
            new DbalTurnUserQueries($connection, self::tenantContext()),
            new DbalUserQueries($connection),
            new TicketTeamLookup($stepQueries, new DbalTeamPeopleQueries($connection, new DbalUserQueries($connection))),
        );
    }

    private function ownTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions(self::connection(), self::tenantContext());
    }

    /**
     * @param list<TicketId> $ticketIds
     *
     * @return list<string>
     */
    private static function idsOf(array $ticketIds): array
    {
        return array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds);
    }

    private function visibilityOf(string $viewer): TicketVisibility
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities->of(new OrganizationId(self::VIEWERS[$viewer][1]), new UserId(self::VIEWERS[$viewer][0]));
    }

    private function ticketQueries(): TicketQueries
    {
        $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
        \assert($ticketQueries instanceof TicketQueries);

        return $ticketQueries;
    }
}
