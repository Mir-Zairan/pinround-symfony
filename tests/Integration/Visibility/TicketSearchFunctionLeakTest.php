<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * search_ticket_ids, which writes the visibility condition out in its migration instead of using TicketVisibilitySql:
 * each viewer of the cast finds through it exactly the tickets TicketVisibilitySql lets them see among the matches,
 * and never a ticket of another organization.
 *
 * Besides the cast's two team-only tickets, Acme Foods has a ticket for everyone and Beta Bakery one of its own, all
 * three about the checkout, so every viewer finds something and a wrong organization would show.
 */
final class TicketSearchFunctionLeakTest extends VisibilityLeakCast
{
    /** Finds the cast's team-only ticket by its title and the no-team ticket and the two below by theirs. */
    private const string QUERY = 'LEAK-MARKER or checkout';
    private const string ACME_TICKET_FOR_EVERYONE = '0197a3c0-0000-7000-8000-0000000057e3';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000057e4';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000057d4';
    private const string PERSON_COLUMN_TICKET = '0197a3c0-0000-7000-8000-0000000057e5';
    private const string SEARCH_CALL = 'SELECT ticket_id FROM search_ticket_ids(CAST(:org AS uuid), CAST(:query AS text), CAST(:me AS uuid),
        CAST(:teams AS uuid[]), CAST(:is_admin AS boolean), CAST(:is_triager AS boolean), NULL, NULL, NULL)';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ticketRows = new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
        $ticketRows->addOf(self::ACME_FOODS, self::ACME_TICKET_FOR_EVERYONE, self::SHOP_PROJECT, self::VIEWERS['Lena'][0], 91);
        $ticketRows->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::VIEWERS['Ben'][0], 1);
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theSearchFindsATeamOnlyTicketOnlyForAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expectedTicketIds = 'Ben' === $viewer
            ? [self::BETA_TICKET]
            : array_keys(array_filter([self::ACME_TICKET_FOR_EVERYONE => true, self::teamOnlyTicketId() => $seesTeamOnly, self::NO_TEAM_TICKET => $seesNoTeamTicket]));

        self::assertEqualsCanonicalizing($expectedTicketIds, self::searchAs($viewer, self::QUERY), \sprintf('%s finds the wrong tickets.', $viewer));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theSearchFindsWhatTicketVisibilitySqlLetsTheViewerSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $visibleMatches = self::asViewer($viewer, static fn (): array => self::visibleMatchesOf($viewer, self::QUERY));

        self::assertNotSame([], $visibleMatches);
        self::assertSame(
            [$seesTeamOnly, $seesNoTeamTicket],
            [\in_array(self::teamOnlyTicketId(), $visibleMatches, true), \in_array(self::NO_TEAM_TICKET, $visibleMatches, true)],
            $viewer,
        );
        self::assertEqualsCanonicalizing($visibleMatches, self::searchAs($viewer, self::QUERY), $viewer);
    }

    #[Test]
    public function aKeyInLowerCaseFindsItsTicket(): void
    {
        $key = self::asViewer('Lena', static fn (): mixed => self::connection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [self::teamOnlyTicketId()]));
        self::assertIsString($key);

        self::assertSame([self::teamOnlyTicketId()], self::searchAs('Lena', strtolower($key)));
    }

    /**
     * A team-only Shop ticket with Noor, outside the Shop team, in one person column; the cast covers the reporter's.
     * The ticket is added in Noor's rolled-back transaction, so no other case and no other test sees it.
     */
    #[Test]
    #[DataProvider('personColumnsBesidesTheReporter')]
    public function anOutsiderInAPersonColumnOfATeamOnlyTicketFindsIt(string $personColumn): void
    {
        $noor = self::VIEWERS['Noor'][0];
        $people = ['assignee_id' => null, 'pending_assignee_id' => null, 'reviewer_id' => null, 'tester_id' => null, 'waiting_on_user_id' => null, $personColumn => $noor];

        [$foundBySearch, $foundByVisibilitySql] = self::asViewer('Noor', static function () use ($people): array {
            self::connection()->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id,
                     visibility, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 92, 'SHOP-92', 'Refund form drops the postcode', '', 'bug', 'major', 2, ?, ?, ?, ?, ?, ?, ?,
                     'team', '{}', '{}', '2027-03-15 10:00:00+00', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::PERSON_COLUMN_TICKET, self::SHOP_PROJECT, self::SHOP_TEAM, ShopTeamRows::TO_DO_STEP, self::NO_TEAM_REPORTER, ...array_values($people)],
            );

            return [self::searchMatchesOf('Noor', 'postcode'), self::visibleMatchesOf('Noor', 'postcode')];
        });

        self::assertSame([self::PERSON_COLUMN_TICKET], $foundBySearch, $personColumn);
        self::assertSame([self::PERSON_COLUMN_TICKET], $foundByVisibilitySql, $personColumn);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function personColumnsBesidesTheReporter(): iterable
    {
        foreach (['assignee_id', 'pending_assignee_id', 'reviewer_id', 'tester_id', 'waiting_on_user_id'] as $personColumn) {
            yield $personColumn => [$personColumn];
        }
    }

    /**
     * Sara, an admin of Acme Foods, may see every ticket of it; asking for Beta Bakery's under her own tenant, or for
     * her own with no tenant set, finds nothing, though Beta Bakery's ticket matches.
     */
    #[Test]
    public function aCallerFindsNothingOfAnOrganizationThatIsNotItsTenant(): void
    {
        $sara = self::asViewer('Sara', static fn (): TicketVisibility => self::visibilityOf('Sara'));

        $foundUnderAnotherTenant = self::asViewer('Sara', static fn (): array => self::connection()->fetchFirstColumn(
            self::SEARCH_CALL,
            [...self::argumentsOf($sara, self::QUERY), 'org' => self::BETA_BAKERY],
        ));
        $foundWithoutTenant = self::connection()->fetchFirstColumn(self::SEARCH_CALL, self::argumentsOf($sara, self::QUERY));

        self::assertSame([[], []], [$foundUnderAnotherTenant, $foundWithoutTenant]);
        self::assertSame([self::BETA_TICKET], self::searchAs('Ben', self::QUERY));
    }

    /**
     * @return list<string> the ids search_ticket_ids finds for the viewer in a request under their own organization
     */
    private static function searchAs(string $viewer, string $query): array
    {
        return self::asViewer($viewer, static fn (): array => self::searchMatchesOf($viewer, $query));
    }

    /**
     * @return list<string> the ids search_ticket_ids finds for the viewer in the transaction under way
     */
    private static function searchMatchesOf(string $viewer, string $query): array
    {
        return array_map(
            static fn (mixed $ticketId): string => \is_string($ticketId) ? $ticketId : '',
            self::connection()->fetchFirstColumn(self::SEARCH_CALL, self::argumentsOf(self::visibilityOf($viewer), $query)),
        );
    }

    /**
     * @return list<mixed> the ids of the tickets that match the query as the function matches and that
     *                     TicketVisibilitySql lets the viewer see, in the transaction under way
     */
    private static function visibleMatchesOf(string $viewer, string $query): array
    {
        $visibility = TicketVisibilitySql::of(self::visibilityOf($viewer), 't');

        return self::connection()->fetchFirstColumn(
            'SELECT t.id FROM tkt_ticket t
             WHERE '.$visibility->sql().'
               AND (t.search @@ websearch_to_tsquery(\'simple\', public.tkt_unaccent(CAST(:query AS text)))
                    OR t.title % CAST(:query AS text) OR t.key = upper(btrim(CAST(:query AS text))))',
            [...$visibility->parameters(), 'query' => $query],
        );
    }

    /**
     * The function's arguments for the viewer, bound as strings that the call casts, as TicketVisibilitySql binds its own.
     *
     * @return array<string, string>
     */
    private static function argumentsOf(TicketVisibility $visibility, string $query): array
    {
        return [
            'org' => $visibility->organizationId()->toString(),
            'query' => $query,
            'me' => $visibility->viewerId()->toString(),
            'teams' => '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $visibility->teamIds())).'}',
            'is_admin' => $visibility->isAdmin() ? 'true' : 'false',
            'is_triager' => $visibility->isTriager() ? 'true' : 'false',
        ];
    }

    private static function visibilityOf(string $viewer): TicketVisibility
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities->of(new OrganizationId($organizationId), new UserId($userId));
    }
}
