<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Insight\Api\MyTicket;
use App\Insight\Api\MyTicketQueries;
use App\Insight\Api\TicketViewer;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tickets the MCP tool list_my_tickets reads (MyTicketQueries::myTicketsOf), as the tool does: the viewer's
 * visibility from TicketVisibilities::of() under their own organization as tenant. Both cast tickets have a turn row
 * committed to wait on Ben, Beta Bakery's admin, by his user id; inside each viewer's rolled-back transaction they wait
 * on, or are assigned to, that viewer instead, so only visibility can leave one out. Beta Bakery has a ticket waiting
 * on Ben.
 */
final class MyTicketsLeakTest extends VisibilityLeakCast
{
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000f7f2';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ben = self::VIEWERS['Ben'][0];
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($ben, $teamOnlyTicketId): void {
            $updated = $migrator->executeStatement(
                "UPDATE ins_ticket_turn SET turn_user_id = ?, turn_label = NULL, kind = 'build' WHERE ticket_id = ?",
                [$ben, $teamOnlyTicketId],
            );
            if (1 !== $updated) {
                throw new \LogicException('The cast\'s team-only ticket has no turn row to wait on Ben.');
            }
            self::insertTurn($migrator, self::ACME_FOODS, self::NO_TEAM_TICKET, self::NO_TEAM_PROJECT, 'team', self::NO_TEAM_REPORTER, $ben);
        });
        new TicketRows(self::migrator(), self::tenantContext())->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, $ben, 72);
        self::asTenantMigrator(self::BETA_BAKERY, static fn (Connection $migrator) => self::insertTurn(
            $migrator,
            self::BETA_BAKERY,
            self::BETA_BAKERY_TICKET,
            self::SHOP_PROJECT,
            'all',
            $ben,
            $ben,
        ));
        self::ensureKernelShutdown();
    }

    /**
     * Ben reads another organization, so he is left out here.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function acmeFoodsViewers(): iterable
    {
        foreach (self::viewers() as $case => $viewer) {
            if ('Ben' !== $viewer[0]) {
                yield $case => $viewer;
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acmeFoodsViewerNames(): iterable
    {
        foreach (self::acmeFoodsViewers() as $case => [$viewer]) {
            yield $case => [$viewer];
        }
    }

    #[Test]
    #[DataProvider('acmeFoodsViewers')]
    public function aTicketWhoseTurnIsTheViewersIsListedOnlyWhenTheyMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertEqualsCanonicalizing(
            self::expectedTicketIds($seesTeamOnly, $seesNoTeamTicket),
            self::listedTicketIdsOf($viewer, 'turn_user_id'),
            \sprintf('%s gets the wrong tickets whose turn is theirs.', $viewer),
        );
    }

    /**
     * The assignee is one of a ticket's people, whom the visibility predicate lets see it (ADR-0013), unlike the person
     * whose turn it is; so assigned, every viewer gets both cast tickets.
     */
    #[Test]
    #[DataProvider('acmeFoodsViewerNames')]
    public function aTicketAssignedToTheViewerIsListedAsTheirsToSee(string $viewer): void
    {
        self::assertEqualsCanonicalizing(
            [self::teamOnlyTicketId(), self::NO_TEAM_TICKET],
            self::listedTicketIdsOf($viewer, 'assignee_id'),
            \sprintf('%s gets the wrong tickets assigned to them.', $viewer),
        );
    }

    /**
     * The expectations written out, so a change to the provider cannot hide one: a manager, an outsider and the triager
     * are each the turn of the team-only ticket of a team they are not in, and never get it.
     */
    #[Test]
    public function theTeamOnlyTicketOfAnotherTeamIsNeverListedEvenWhenItIsTheirTurn(): void
    {
        $expected = ['Raj' => [], 'Noor' => [], 'Omar' => [self::NO_TEAM_TICKET], 'Sara' => [self::teamOnlyTicketId(), self::NO_TEAM_TICKET]];

        foreach ($expected as $viewer => $ticketIds) {
            self::assertEqualsCanonicalizing($ticketIds, self::listedTicketIdsOf($viewer, 'turn_user_id'), $viewer.' gets the wrong tickets.');
        }
    }

    #[Test]
    public function aViewerOutsideTheTeamNeverReadsTheTeamOnlyTicketsTitle(): void
    {
        $titles = self::asViewer('Raj', static function (): array {
            self::makeCastTicketsWaitOn('Raj', 'turn_user_id');

            return array_map(static fn (MyTicket $ticket): string => $ticket->title, self::myTicketQueries()->myTicketsOf(self::ticketViewerOf('Raj'), MyTicketQueries::MAX_TICKETS)->tickets);
        });
        $sarasTitles = self::asViewer('Sara', static function (): array {
            self::makeCastTicketsWaitOn('Sara', 'turn_user_id');

            return array_map(static fn (MyTicket $ticket): string => $ticket->title, self::myTicketQueries()->myTicketsOf(self::ticketViewerOf('Sara'), MyTicketQueries::MAX_TICKETS)->tickets);
        });

        self::assertContains(self::LEAK_MARKER, $sarasTitles, 'The marker is not where an admin reads it, so its absence proves nothing.');
        self::assertNotContains(self::LEAK_MARKER, $titles);
    }

    /**
     * Both of Acme Foods' cast tickets wait on Ben's user id, committed, yet he gets only his own organization's.
     */
    #[Test]
    public function anotherOrganizationsAdminGetsOnlyTheirOwnOrganizationsTicketsThoughAcmeFoodsTicketsWaitOnTheirId(): void
    {
        $ticketIds = self::asViewer('Ben', static fn (): array => array_map(
            static fn (MyTicket $ticket): string => $ticket->id->toString(),
            self::myTicketQueries()->myTicketsOf(self::ticketViewerOf('Ben'), MyTicketQueries::MAX_TICKETS)->tickets,
        ));

        self::assertSame([self::BETA_BAKERY_TICKET], $ticketIds);
    }

    /**
     * @return list<string>
     */
    private static function expectedTicketIds(bool $seesTeamOnly, bool $seesNoTeamTicket): array
    {
        return array_values(array_filter(
            [$seesTeamOnly ? self::teamOnlyTicketId() : null, $seesNoTeamTicket ? self::NO_TEAM_TICKET : null],
            static fn (?string $ticketId): bool => null !== $ticketId,
        ));
    }

    /**
     * @param 'turn_user_id'|'assignee_id' $part how the cast tickets name the viewer, inside their rolled-back transaction
     *
     * @return list<string> the ids of the tickets the viewer gets
     */
    private static function listedTicketIdsOf(string $viewer, string $part): array
    {
        return self::asViewer($viewer, static function () use ($viewer, $part): array {
            self::makeCastTicketsWaitOn($viewer, $part);

            return array_map(
                static fn (MyTicket $ticket): string => $ticket->id->toString(),
                self::myTicketQueries()->myTicketsOf(self::ticketViewerOf($viewer), MyTicketQueries::MAX_TICKETS)->tickets,
            );
        });
    }

    /**
     * In the transaction under Acme Foods that is open, as the app's connection.
     *
     * @param 'turn_user_id'|'assignee_id' $part
     */
    private static function makeCastTicketsWaitOn(string $viewer, string $part): void
    {
        $updated = self::connection()->executeStatement(
            \sprintf('UPDATE ins_ticket_turn SET %s = ? WHERE ticket_id IN (?, ?)', $part),
            [self::VIEWERS[$viewer][0], self::teamOnlyTicketId(), self::NO_TEAM_TICKET],
        );
        if (2 !== $updated) {
            throw new \LogicException(\sprintf('The cast tickets\' turn rows could not name %s.', $viewer));
        }
    }

    private static function insertTurn(Connection $migrator, string $organizationId, string $ticketId, string $projectId, string $audience, string $reporterId, string $turnUserId): void
    {
        $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, NULL, ?, 'doing', ?, NULL, 'build', 100, '2027-03-01 09:00:00+00', ?, ?)",
            [$organizationId, $ticketId, $projectId, $turnUserId, $audience, $reporterId],
        );
    }

    private static function ticketViewerOf(string $viewer): TicketViewer
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];
        $visibility = self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));

        return new TicketViewer($visibility->organizationId(), $visibility->viewerId(), $visibility->teamIds(), $visibility->isAdmin(), $visibility->isTriager());
    }

    private static function myTicketQueries(): MyTicketQueries
    {
        $queries = self::getContainer()->get('test.insight_my_ticket_queries');
        \assert($queries instanceof MyTicketQueries);

        return $queries;
    }

    private static function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }
}
