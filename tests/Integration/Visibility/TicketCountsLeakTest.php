<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Organization\Api\TeamPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ProjectSummary;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Infrastructure\Doctrine\TicketOpenTicketCounts;
use App\Workflow\Api\StepOccupancy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * The ticket counts that exist today. The team page shows each project's open tickets to whoever may view the team,
 * counted per viewer, so a viewer who may read SHOP's count sees every ticket it counts. The unlink and step counts are
 * guards and count every open ticket on purpose; the tenant alone bounds them.
 */
final class TicketCountsLeakTest extends VisibilityLeakCast
{
    #[Test]
    #[DataProvider('viewers')]
    public function aViewerWhoReadsTheTeamPageCountSeesEveryTicketItCounts(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        [$mayViewTeam, $shopOpenTickets] = self::readTeamPageAs($viewer);

        self::assertFalse($mayViewTeam && ['SHOP 1'] === $shopOpenTickets && !$seesTeamOnly, \sprintf('%s reads SHOP\'s open count of %s with a ticket they may not see.', $viewer, json_encode($shopOpenTickets)));
    }

    /**
     * SHOP's one open ticket is team-only: the lead, the admin (ADR-0013) and its outside reporter count it; the
     * manager who may view every team page, the triager and an outsider count none; Beta Bakery's admin finds no SHOP
     * team, so no project and no count.
     */
    #[Test]
    public function theTeamPageCountHasOnlyTheTicketsTheViewerMaySee(): void
    {
        self::assertSame([true, ['SHOP 1']], self::readTeamPageAs('Lena'));
        self::assertSame([true, ['SHOP 1']], self::readTeamPageAs('Sara'));
        self::assertSame(['SHOP 1'], self::readTeamPageAs('Mia')[1]);
        self::assertSame([true, ['SHOP 0']], self::readTeamPageAs('Raj'));
        self::assertSame(['SHOP 0'], self::readTeamPageAs('Omar')[1]);
        self::assertSame(['SHOP 0'], self::readTeamPageAs('Noor')[1]);
        self::assertSame([false, []], self::readTeamPageAs('Ben'));
    }

    /**
     * OpenTicketCounts::openTicketsIn guards UnlinkProject: a project with open tickets stays linked, whoever can see
     * them, so it counts the team-only ticket. Under another organization as tenant it counts nothing.
     */
    #[Test]
    public function theUnlinkCountIsAGuardThatCountsEveryOpenTicketOfTheTenantOnly(): void
    {
        $guardCount = static fn (): array => new TicketOpenTicketCounts(self::connection(), self::getContainer()->get(TenantContext::class))->openTicketsIn(new ProjectId(self::SHOP_PROJECT));

        self::assertSame(
            [[self::SHOP_PROJECT => 1], [self::SHOP_PROJECT => 0]],
            [self::transactions()->rolledBackAs(self::ACME_FOODS, $guardCount), self::transactions()->rolledBackAs(self::BETA_BAKERY, $guardCount)],
        );
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

    /**
     * What the team page reads for the viewer in a request: whether they may view the Shop team, and each of its
     * projects' key and open count as projectsOf counts them for that viewer.
     *
     * @return array{bool, list<string>}
     */
    private static function readTeamPageAs(string $viewer): array
    {
        return self::asViewer($viewer, static fn (TokenInterface $token): array => [
            self::accessDecisionManager()->decide($token, [TeamPermission::VIEW], new TeamId(self::SHOP_TEAM)),
            array_map(
                static fn (ProjectSummary $project): string => $project->key().' '.$project->openTicketCount(),
                self::projectQueries()->projectsOf(new TeamId(self::SHOP_TEAM), new UserId(self::VIEWERS[$viewer][0])),
            ),
        ]);
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
