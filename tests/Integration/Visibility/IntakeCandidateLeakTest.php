<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * IntakeCandidateQueries::openCandidatesIn, the ticket titles the intake check sends to an AI: no viewer gets a cast
 * ticket, as both are team-only, not even the Shop lead, the reporter, the admin or the triager who may see them.
 * SHOP also has SHOP-50, for everyone, which every Acme Foods viewer gets. Beta Bakery has a project with SHOP's id and
 * its own SHOP-1 in it, which only Ben gets. Each case runs in a rolled-back transaction under the viewer's own
 * organization.
 */
final class IntakeCandidateLeakTest extends VisibilityLeakCast
{
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-0000000057e4';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000057e5';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ticketRows = new TicketRows(self::migrator(), self::tenantContext());
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_PROJECT, 50, 'all', self::SHOP_TEAM, self::VIEWERS['Raj'][0]);
        $ticketRows->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::SHOP_PROJECT, self::VIEWERS['Ben'][0]);
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function aViewerGetsNoTeamOnlyTicketAsACandidateWhetherOrNotTheyMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        [$visibility, $shopCandidates, $noTeamProjectCandidates] = self::asViewer($viewer, function () use ($viewer): array {
            $visibility = $this->visibilityOf($viewer);

            return [$visibility, $this->queries()->openCandidatesIn(self::SHOP_PROJECT, $visibility), $this->queries()->openCandidatesIn(self::NO_TEAM_PROJECT, $visibility)];
        });

        // Those who may see a cast ticket are why the query must leave team-only tickets out, not only hidden ones.
        self::assertSame([$seesTeamOnly, $seesNoTeamTicket], [$visibility->isVisible(self::teamOnlyTicket()), $visibility->isVisible(self::noTeamTicket())]);
        self::assertNotSame([], $shopCandidates);
        self::assertSame('Ben' === $viewer ? ['SHOP-1'] : ['SHOP-50'], self::keysOf($shopCandidates));
        foreach ($shopCandidates as $candidate) {
            self::assertNotSame(self::LEAK_MARKER, $candidate->title);
        }
        self::assertSame([], $noTeamProjectCandidates);
    }

    private function visibilityOf(string $viewer): TicketVisibility
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities->of(new OrganizationId(self::VIEWERS[$viewer][1]), new UserId(self::VIEWERS[$viewer][0]));
    }

    private function queries(): IntakeCandidateQueries
    {
        $queries = self::getContainer()->get('test.ticketing_intake_candidate_queries');
        \assert($queries instanceof IntakeCandidateQueries);

        return $queries;
    }

    /**
     * @param list<IntakeCandidate> $candidates
     *
     * @return list<string>
     */
    private static function keysOf(array $candidates): array
    {
        return array_map(static fn (IntakeCandidate $candidate): string => $candidate->key, $candidates);
    }
}
