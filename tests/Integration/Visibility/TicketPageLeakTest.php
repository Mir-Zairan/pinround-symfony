<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Organization\Api\CurrentActor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
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
        self::ensureKernelShutdown();
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
