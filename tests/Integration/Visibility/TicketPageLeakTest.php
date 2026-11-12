<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Organization\Api\CurrentActor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketPosition;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Application\RoleTicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The ticket page's read, as TicketShowController makes it: the viewer's predicate from RoleTicketVisibilities::ofActor
 * for the token's actor, TicketQueries::pageOf under their own organization as tenant, then TicketPermission VIEW on
 * the page's own visibility facts. A viewer is shown a ticket exactly when they may see it; otherwise the page is the
 * 404 an unknown key gets.
 *
 * A ticket for everyone whose parent is the cast's team-only ticket shows that parent's key only to a viewer who may
 * see the parent.
 */
final class TicketPageLeakTest extends VisibilityLeakCast
{
    /** The step the cast's no-team ticket is in, which the cast itself does not add. */
    private const string NO_TEAM_TICKET_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string CHILD_TICKET = '0197a3c0-0000-7000-8000-0000000057e3';
    private const int CHILD_NUMBER = 91;
    private const string BEFORE_TICKET = '0197a3c0-0000-7000-8000-0000000057e4';
    private const int BEFORE_NUMBER = 92;
    private const string AFTER_TICKET = '0197a3c0-0000-7000-8000-0000000057e5';
    private const int AFTER_NUMBER = 93;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(static fn (Connection $tenantMigrator): int|string => $tenantMigrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                [self::ACME_FOODS, self::NO_TEAM_TICKET_STEP],
            ));
        } finally {
            $tenantContext->clear();
        }
        new TicketRows(self::migrator(), self::tenantContext())->addOf(self::ACME_FOODS, self::CHILD_TICKET, self::SHOP_PROJECT, self::NO_TEAM_REPORTER, self::CHILD_NUMBER);
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET parent_id = ? WHERE id = ?',
            [$teamOnlyTicketId, self::CHILD_TICKET],
        ));
        // Before and after the team-only ticket in its own step, by updated_at at the same priority.
        foreach ([[self::BEFORE_TICKET, self::BEFORE_NUMBER], [self::AFTER_TICKET, self::AFTER_NUMBER]] as [$ticketId, $number]) {
            new TicketRows(self::migrator(), self::tenantContext())->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::NO_TEAM_REPORTER, $number);
        }
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($teamOnlyTicketId): void {
            $migrator->executeStatement(
                "UPDATE tkt_ticket SET priority = 'major', priority_rank = 2, updated_at = '2027-03-15 11:00:00+00' WHERE id = ?",
                [$teamOnlyTicketId],
            );
            foreach ([self::BEFORE_TICKET => '2027-03-15 12:00:00+00', self::AFTER_TICKET => '2027-03-15 10:00:00+00'] as $ticketId => $updatedAt) {
                $migrator->executeStatement(
                    'UPDATE tkt_ticket SET step_id = (SELECT step_id FROM tkt_ticket WHERE id = ?), updated_at = ? WHERE id = ?',
                    [$teamOnlyTicketId, $updatedAt, $ticketId],
                );
            }
        });
        self::ensureKernelShutdown();
    }

    /**
     * The team-only ticket sits between two tickets for everyone in one step. A viewer who may not see it never gets it
     * as a neighbour or in the count: Previous and Next skip it, and the total holds the three tickets for everyone
     * (the two and the child), plus the team-only and the no-team ticket only for a viewer who may see them. Every
     * neighbour opens for the viewer; Beta Bakery's admin gets no position at all.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function aPositionNeverWalksToNorCountsATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $teamOnlyKey = self::keyOf(self::teamOnlyTicketId());
        $beforeKey = 'SHOP-'.self::BEFORE_NUMBER;
        $afterKey = 'SHOP-'.self::AFTER_NUMBER;

        $walked = self::asViewer($viewer, static function (TokenInterface $token) use ($beforeKey, $afterKey, $teamOnlyKey): array {
            $actor = self::getContainer()->get(CurrentActor::class)->actorFor($token->getUser());
            self::assertNotNull($actor);
            $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
            \assert($ticketQueries instanceof TicketQueries);
            $visibility = self::getContainer()->get(RoleTicketVisibilities::class)->ofActor($actor);
            $positionOf = static fn (string $key): ?TicketPosition => $ticketQueries->positionOf($key, new ListFilter($visibility), $visibility);
            $before = $positionOf($beforeKey);
            $after = $positionOf($afterKey);
            $neighbourKeys = array_filter([$before?->previousKey, $before?->nextKey, $after?->previousKey, $after?->nextKey], static fn (?string $key): bool => null !== $key);
            $unopenedNeighbours = array_values(array_filter($neighbourKeys, static fn (string $key): bool => null === $ticketQueries->pageOf($key, $visibility)));

            return [$before, $after, $positionOf($teamOnlyKey), $unopenedNeighbours];
        });
        [$before, $after, $teamOnly, $unopenedNeighbours] = $walked;

        if (self::ACME_FOODS !== self::VIEWERS[$viewer][1]) {
            self::assertSame([null, null, null], [$before, $after, $teamOnly], \sprintf('%s gets a position in another organization.', $viewer));

            return;
        }
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame(
            [
                $seesTeamOnly ? $teamOnlyKey : $afterKey,
                $seesTeamOnly ? $teamOnlyKey : $beforeKey,
                $seesTeamOnly ? 2 : 1,
                3 + ($seesTeamOnly ? 1 : 0) + ($seesNoTeamTicket ? 1 : 0),
                $seesTeamOnly,
                [],
            ],
            [$before->nextKey, $after->previousKey, $after->index - $before->index, $after->total, null !== $teamOnly, $unopenedNeighbours],
            \sprintf('%s walks the wrong neighbours or count.', $viewer),
        );
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theTeamOnlyTicketsPageShowsItsTitleOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $shownPage = self::shownPageAs($viewer, self::keyOf(self::teamOnlyTicketId()));

        self::assertSame($seesTeamOnly ? self::LEAK_MARKER : null, $shownPage?->title, \sprintf('%s gets the wrong team-only ticket page.', $viewer));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theNoTeamTicketsPageShowsOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $shownPage = self::shownPageAs($viewer, self::keyOf(self::NO_TEAM_TICKET));

        self::assertSame($seesNoTeamTicket ? self::NO_TEAM_TICKET : null, $shownPage?->id->toString(), \sprintf('%s gets the wrong no-team ticket page.', $viewer));
    }

    /**
     * Every Acme Foods viewer opens the child, which is for everyone; Beta Bakery's admin gets no page at all.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function aChildsPageShowsItsTeamOnlyParentsKeyOnlyToAViewerWhoMaySeeTheParent(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $isOfAcmeFoods = self::ACME_FOODS === self::VIEWERS[$viewer][1];

        $shownPage = self::shownPageAs($viewer, 'SHOP-'.self::CHILD_NUMBER);

        self::assertSame(
            [$isOfAcmeFoods, $seesTeamOnly ? self::keyOf(self::teamOnlyTicketId()) : null],
            [null !== $shownPage, $shownPage?->parentKey],
            \sprintf('%s gets the wrong parent on the child\'s page.', $viewer),
        );
    }

    /**
     * @return TicketPageView|null the page the viewer is shown, or null for its 404
     */
    private static function shownPageAs(string $viewer, string $key): ?TicketPageView
    {
        return self::asViewer($viewer, static function (TokenInterface $token) use ($key): ?TicketPageView {
            $actor = self::getContainer()->get(CurrentActor::class)->actorFor($token->getUser());
            self::assertNotNull($actor);
            $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
            \assert($ticketQueries instanceof TicketQueries);

            $page = $ticketQueries->pageOf($key, self::getContainer()->get(RoleTicketVisibilities::class)->ofActor($actor));
            $isGranted = null !== $page && self::getContainer()->get('security.access.decision_manager')->decide($token, [TicketPermission::VIEW], $page->visibilityFacts);

            return $isGranted ? $page : null;
        });
    }

    /**
     * Read under Acme Foods as tenant, as row-level security binds the migrator too.
     */
    private static function keyOf(string $ticketId): string
    {
        $key = null;
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($ticketId, &$key): void {
            $key = $migrator->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId]);
        });
        \assert(\is_string($key));

        return $key;
    }
}
