<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Organization\Api\TeamPermission;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ProjectSummary;
use App\Workflow\Api\StepOccupancy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * The ticket counts that exist today. The team page shows each project's open tickets to whoever may view the team, so
 * a viewer who may read SHOP's count must also see the team-only ticket it counts. The step count is a guard before a
 * step is removed and counts every open ticket on purpose; the tenant alone bounds it.
 */
final class TicketCountsLeakTest extends VisibilityLeakCast
{
    /**
     * Viewers who read a count that includes the team-only ticket they may not see, with the open work that ends it.
     * The test fails once the leak is gone, so the entry is removed with the fix.
     */
    private const array KNOWN_LEAKS = [
        'Raj' => 'TODO(P03.T2): TeamVoter lets a Manager view every team page, whose project counts include team-only tickets; count them per viewer with TicketVisibilitySql (ADR-0013 "manager counts").',
    ];

    #[Test]
    #[DataProvider('viewers')]
    public function aViewerWhoReadsTheTeamPageCountSeesEveryTicketItCounts(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        [$mayViewTeam, $shopOpenTickets] = self::asViewer($viewer, static fn (TokenInterface $token): array => [
            self::accessDecisionManager()->decide($token, [TeamPermission::VIEW], new TeamId(self::SHOP_TEAM)),
            array_map(static fn (ProjectSummary $project): int => $project->openTicketCount(), self::projectQueries()->projectsOf(new TeamId(self::SHOP_TEAM))),
        ]);
        $readsTeamOnlyTicketInCount = $mayViewTeam && [1] === $shopOpenTickets;

        if (\array_key_exists($viewer, self::KNOWN_LEAKS)) {
            self::assertTrue($readsTeamOnlyTicketInCount && !$seesTeamOnly, 'The known leak is gone; remove its KNOWN_LEAKS entry.');
            self::markTestIncomplete(self::KNOWN_LEAKS[$viewer]);
        }
        self::assertFalse($readsTeamOnlyTicketInCount && !$seesTeamOnly, \sprintf('%s reads SHOP\'s open count of %s with a ticket they may not see.', $viewer, json_encode($shopOpenTickets)));
    }

    /**
     * The count may only include the ticket when the team page shows it: the lead and the admin may view the team and
     * see the ticket; the outside reporter sees the ticket but not the team page.
     */
    #[Test]
    public function theTeamPageCountIncludesTheTeamOnlyTicketForThoseWhoSeeIt(): void
    {
        $readBy = static fn (string $viewer): array => self::asViewer($viewer, static fn (TokenInterface $token): array => [
            self::accessDecisionManager()->decide($token, [TeamPermission::VIEW], new TeamId(self::SHOP_TEAM)),
            array_map(static fn (ProjectSummary $project): string => $project->key().' '.$project->openTicketCount(), self::projectQueries()->projectsOf(new TeamId(self::SHOP_TEAM))),
        ]);

        self::assertSame([true, ['SHOP 1']], $readBy('Lena'));
        self::assertSame([true, ['SHOP 1']], $readBy('Sara'));
        self::assertFalse($readBy('Mia')[0]);
        self::assertSame([false, []], $readBy('Ben'));
    }

    /**
     * StepOccupancy guards RemoveStep: a step with open tickets is not removed, whoever can see them, so it counts
     * the team-only ticket. Under another organization as tenant it counts nothing.
     */
    #[Test]
    public function theStepCountIsAGuardThatCountsEveryOpenTicketOfTheTenantOnly(): void
    {
        $stepId = self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): mixed => self::connection()->fetchOne('SELECT step_id FROM tkt_ticket WHERE id = ?', [self::teamOnlyTicketId()]));
        self::assertIsString($stepId);

        $countedByAcmeFoods = self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): int => self::stepOccupancy()->openTicketsIn($stepId));
        $countedByBetaBakery = self::transactions()->rolledBackAs(self::BETA_BAKERY, static fn (): int => self::stepOccupancy()->openTicketsIn($stepId));

        self::assertSame([1, 0], [$countedByAcmeFoods, $countedByBetaBakery]);
    }

    private static function accessDecisionManager(): AccessDecisionManagerInterface
    {
        return self::getContainer()->get('security.access.decision_manager');
    }

    private static function projectQueries(): ProjectQueries
    {
        return self::getContainer()->get(ProjectQueries::class);
    }

    private static function stepOccupancy(): StepOccupancy
    {
        return self::getContainer()->get(StepOccupancy::class);
    }
}
