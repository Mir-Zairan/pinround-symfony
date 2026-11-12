<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The ticket page's gate, read as TicketShowController reads it: TicketQueries::pageOf under the viewer's own
 * organization as tenant, then TicketPermission VIEW on the page's own visibility facts. A viewer is shown the title
 * exactly when both let them; otherwise the page is the 404 an unknown key gets.
 */
final class TicketPageLeakTest extends VisibilityLeakCast
{
    /** The step the cast's no-team ticket is in, which the cast itself does not add. */
    private const string NO_TEAM_TICKET_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';

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
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theTeamOnlyTicketsPageShowsItsTitleOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $shownTitle = self::shownTitleAs($viewer, self::keyOf(self::teamOnlyTicketId()));

        self::assertSame($seesTeamOnly ? self::LEAK_MARKER : null, $shownTitle, \sprintf('%s gets the wrong team-only ticket page.', $viewer));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theNoTeamTicketsPageShowsOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $shownTitle = self::shownTitleAs($viewer, self::keyOf(self::NO_TEAM_TICKET));

        self::assertSame($seesNoTeamTicket ? 'Checkout button is hidden' : null, $shownTitle, \sprintf('%s gets the wrong no-team ticket page.', $viewer));
    }

    /**
     * @return string|null the title the page shows the viewer, or null for its 404
     */
    private static function shownTitleAs(string $viewer, string $key): ?string
    {
        return self::asViewer($viewer, static function (TokenInterface $token) use ($viewer, $key): ?string {
            [$userId, $organizationId] = self::VIEWERS[$viewer];
            $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
            $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
            \assert($ticketVisibilities instanceof TicketVisibilities && $ticketQueries instanceof TicketQueries);
            $accessDecisionManager = self::getContainer()->get('security.access.decision_manager');

            $page = $ticketQueries->pageOf($key, $ticketVisibilities->of(new OrganizationId($organizationId), new UserId($userId)));
            if (null === $page || !$accessDecisionManager->decide($token, [TicketPermission::VIEW], $page->visibilityFacts)) {
                return null;
            }

            return $page->title;
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
