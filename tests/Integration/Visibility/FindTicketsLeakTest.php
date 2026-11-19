<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\AgentApi\Infrastructure\Mcp\Tool\FindTickets;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The MCP tool find_tickets, with the viewer's personal access token in the token storage as the api_token firewall
 * leaves it: each viewer finds exactly the open matches they may see, and each match's may_start_draft is the decision
 * DraftRoundCreationVoter takes on that ticket for them.
 *
 * Every ticket here matches the search. Besides the cast's two team-only tickets, Acme Foods has a SHOP ticket for
 * everyone, assigned to Omar and waiting on Raj, and a Done one assigned to Lena; the cast's team-only ticket waits on
 * Mia, its reporter. Beta Bakery has a ticket of its own, assigned to Ben. Tickets are named by what they are, as
 * their keys depend on the numbers the project has handed out before.
 */
final class FindTicketsLeakTest extends VisibilityLeakCast
{
    /** Finds the cast's team-only ticket by its title, every other ticket by "checkout". */
    private const string QUERY = 'LEAK-MARKER or checkout';
    /** The step the cast's no-team ticket and Beta Bakery's ticket are in, which the cast itself does not add. */
    private const string TICKET_ROWS_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string ACME_DONE_STEP = '0197a3c0-0000-7000-8000-00000000f0d9';
    private const string BETA_BAKERY_PROJECT = '0197a3c0-0000-7000-8000-00000000f8d1';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000f8e1';
    private const string FOR_EVERYONE_TITLE = 'Checkout coupon is ignored';
    private const string DONE_TITLE = 'Checkout total was wrong';
    private const string TEAM_ONLY = 'team-only';
    private const string NO_TEAM = 'no team';
    private const string FOR_EVERYONE = 'for everyone';
    private const string BETA_BAKERYS = 'Beta Bakery\'s';

    private static ?string $forEveryoneTicketId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                [$organizationId, self::TICKET_ROWS_STEP],
            ));
        }
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 9)",
            [self::ACME_FOODS, self::ACME_DONE_STEP],
        ));

        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $ticketFactory = new TicketFactory($ticketCommands, self::transactions(), self::connection());
        $forEveryoneTicketId = $ticketFactory->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::VIEWERS['Mia'][0], ['title' => self::FOR_EVERYONE_TITLE])->toString();
        $doneTicketId = $ticketFactory->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::VIEWERS['Mia'][0], ['title' => self::DONE_TITLE])->toString();
        self::$forEveryoneTicketId = $forEveryoneTicketId;
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($forEveryoneTicketId, $doneTicketId, $teamOnlyTicketId): void {
            $omar = self::VIEWERS['Omar'][0];
            $migrator->executeStatement('UPDATE tkt_ticket SET assignee_id = ? WHERE id = ?', [$omar, $forEveryoneTicketId]);
            $migrator->executeStatement('UPDATE ins_ticket_turn SET assignee_id = ?, turn_user_id = ? WHERE ticket_id = ?', [$omar, self::VIEWERS['Raj'][0], $forEveryoneTicketId]);
            $migrator->executeStatement('UPDATE ins_ticket_turn SET turn_user_id = ? WHERE ticket_id = ?', [self::VIEWERS['Mia'][0], $teamOnlyTicketId]);
            // The turn projector leaves a Done ticket without a turn row.
            $migrator->executeStatement("UPDATE tkt_ticket SET step_id = ?, assignee_id = ?, closed_at = '2027-03-16 10:00:00+00' WHERE id = ?", [self::ACME_DONE_STEP, self::VIEWERS['Lena'][0], $doneTicketId]);
            $migrator->executeStatement('DELETE FROM ins_ticket_turn WHERE ticket_id = ?', [$doneTicketId]);
        });
        new TicketRows(self::migrator(), self::tenantContext())->addWithAudienceOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::BETA_BAKERY_PROJECT, 77, 'all', null, self::VIEWERS['Ben'][0], ['assignee_id' => self::VIEWERS['Ben'][0]]);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$forEveryoneTicketId = null;
        parent::tearDownAfterClass();
    }

    /**
     * What each viewer finds, with may_start_draft: the ticket for everyone always, the cast's team-only tickets as the
     * cast's viewers() says, the Done ticket never, and Beta Bakery's ticket only to its own admin.
     *
     * @return iterable<string, array{string, array<string, bool>}>
     */
    public static function expectedMatches(): iterable
    {
        $expected = [
            'Lena' => [self::TEAM_ONLY => false, self::FOR_EVERYONE => false],
            'Mia' => [self::TEAM_ONLY => true, self::FOR_EVERYONE => false],
            'Sara' => [self::TEAM_ONLY => false, self::NO_TEAM => false, self::FOR_EVERYONE => false],
            'Omar' => [self::NO_TEAM => false, self::FOR_EVERYONE => true],
            'Raj' => [self::FOR_EVERYONE => true],
            'Noor' => [self::FOR_EVERYONE => false],
            'Ben' => [self::BETA_BAKERYS => true],
        ];
        foreach ($expected as $viewer => $mayStartDraftByTicket) {
            ksort($mayStartDraftByTicket);
            yield \sprintf('%s, %s', $viewer, self::VIEWERS[$viewer][3]) => [$viewer, $mayStartDraftByTicket];
        }
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theCastsTeamOnlyTicketsAreFoundOnlyByAViewerWhoMaySeeThem(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $foundTickets = array_keys(self::foundAs($viewer));

        self::assertNotSame([], $foundTickets, \sprintf('%s finds nothing, so a refusal would pass for no leak.', $viewer));
        self::assertSame($seesTeamOnly, \in_array(self::TEAM_ONLY, $foundTickets, true), \sprintf('%s gets the wrong answer for the team-only ticket.', $viewer));
        self::assertSame($seesNoTeamTicket, \in_array(self::NO_TEAM, $foundTickets, true), \sprintf('%s gets the wrong answer for the no-team ticket.', $viewer));
    }

    /**
     * Written out by viewer, so a change to the cast's viewers() cannot hide one: no Done ticket, no other
     * organization's ticket, and may_start_draft for the assignee and the turn person only.
     *
     * @param array<string, bool> $mayStartDraftByTicket
     */
    #[Test]
    #[DataProvider('expectedMatches')]
    public function eachViewerFindsExactlyTheOpenMatchesTheyMaySeeAndMayStartADraftOnlyAsAssigneeOrTurnPerson(string $viewer, array $mayStartDraftByTicket): void
    {
        $found = self::foundAs($viewer);

        self::assertSame($mayStartDraftByTicket, array_map(static fn (array $ticket): bool => $ticket['may_start_draft'], $found), $viewer);
    }

    /**
     * The tool works may_start_draft out from the facts it read for the whole list; the voter reads each ticket on its
     * own. They agree on every ticket the tool returns, true and false both.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function mayStartDraftIsTheDraftRoundCreationVotersDecision(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $found = self::foundAs($viewer);

        self::assertNotSame([], $found, \sprintf('%s finds nothing to compare.', $viewer));
        foreach ($found as $ticket => ['may_start_draft' => $mayStartDraft, 'is_draft_create_granted' => $isGranted]) {
            self::assertSame($isGranted, $mayStartDraft, \sprintf('%s: may_start_draft on the %s ticket is not the voter\'s decision.', $viewer, $ticket));
        }
    }

    /**
     * Calls the tool as the viewer, and asks the voter for RoundPermission DRAFT_CREATE on each ticket it returns, in the
     * same transaction and with the same token.
     *
     * @return array<string, array{may_start_draft: bool, is_draft_create_granted: bool}> by what the ticket is (its id
     *                                                                                    when it is none of the named
     *                                                                                    ones), sorted
     */
    private static function foundAs(string $viewer): array
    {
        $found = self::asViewerWithApiToken($viewer, static function (FindTickets $findTickets): array {
            $structuredContent = $findTickets->call(['text' => self::QUERY], null)->structuredContent;
            self::assertIsArray($structuredContent);
            self::assertFalse($structuredContent['has_more'] ?? null);
            self::assertIsArray($structuredContent['tickets'] ?? null);
            $authorizationChecker = self::getContainer()->get('security.authorization_checker');
            $found = [];
            foreach ($structuredContent['tickets'] as $ticket) {
                self::assertIsArray($ticket);
                self::assertIsString($ticket['key'] ?? null);
                self::assertIsBool($ticket['may_start_draft'] ?? null);
                $ticketId = self::connection()->fetchOne('SELECT id FROM tkt_ticket WHERE key = ?', [$ticket['key']]);
                self::assertIsString($ticketId);
                $found[self::nameOf($ticketId)] = [
                    'may_start_draft' => $ticket['may_start_draft'],
                    'is_draft_create_granted' => $authorizationChecker->isGranted(RoundPermission::DRAFT_CREATE, new TicketId($ticketId)),
                ];
            }

            return $found;
        });
        ksort($found);

        return $found;
    }

    private static function nameOf(string $ticketId): string
    {
        return match ($ticketId) {
            self::teamOnlyTicketId() => self::TEAM_ONLY,
            self::NO_TEAM_TICKET => self::NO_TEAM,
            self::forEveryoneTicketId() => self::FOR_EVERYONE,
            self::BETA_BAKERY_TICKET => self::BETA_BAKERYS,
            default => $ticketId,
        };
    }

    /**
     * Runs the work in the viewer's rolled-back transaction with a read token of their organization in the token
     * storage, as the api_token firewall leaves it; the storage is emptied after.
     *
     * @template T
     *
     * @param \Closure(FindTickets): T $work
     *
     * @return T
     */
    private static function asViewerWithApiToken(string $viewer, \Closure $work): mixed
    {
        return self::asViewer($viewer, static function (TokenInterface $loginToken) use ($viewer, $work): mixed {
            $securityUser = $loginToken->getUser();
            \assert($securityUser instanceof SecurityUser);
            $access = new ApiTokenAccess('0197a3c0-0000-7000-8000-00000000f8a1', new OrganizationId(self::VIEWERS[$viewer][1]), ['read'], new \DateTimeImmutable('2027-04-15 10:00:00 UTC'));
            $tokenStorage = self::getContainer()->get('security.token_storage');
            $tokenStorage->setToken(new ApiTokenAuthentication($securityUser, $access, null));
            try {
                return $work(self::getContainer()->get(FindTickets::class));
            } finally {
                $tokenStorage->setToken(null);
            }
        });
    }

    private static function forEveryoneTicketId(): string
    {
        return self::$forEveryoneTicketId ?? throw new \LogicException('The ticket for everyone is set up before each class.');
    }
}
