<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\Conversation\Application\VisibleTickets;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisibleTicketsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e601';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e602';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e603';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e611';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e612';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e613';

    private TenantContext $tenantContext;
    private VisibleTickets $visibleTickets;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->visibleTickets = new VisibleTickets(self::visibilitiesWithShopTeam([self::LENA, self::OMAR]), $this->tenantContext);
    }

    /**
     * @return iterable<string, array{\Closure(): TicketVisibilityFacts, string}>
     */
    public static function visibleTickets(): iterable
    {
        yield 'a team-only ticket, to a member of its team' => [static fn (): TicketVisibilityFacts => self::teamOnlyTicketOf(self::ACME_FOODS), self::OMAR];
        yield 'a ticket for everyone, to someone outside its team' => [static fn (): TicketVisibilityFacts => self::ticketForEveryoneOf(self::ACME_FOODS), self::MIA];
    }

    /**
     * @param \Closure(): TicketVisibilityFacts $ticketOf
     */
    #[Test]
    #[DataProvider('visibleTickets')]
    public function aTicketOfTheTenantThePersonMaySeePasses(\Closure $ticketOf, string $personId): void
    {
        $this->visibleTickets->refuseUnlessVisibleTo($ticketOf(), new UserId($personId));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aTeamOnlyTicketIsNotFoundForSomeoneOutsideItsTeam(): void
    {
        $this->expectExceptionObject(new TicketNotFound('That ticket does not exist.'));

        $this->visibleTickets->refuseUnlessVisibleTo(self::teamOnlyTicketOf(self::ACME_FOODS), new UserId(self::MIA));
    }

    #[Test]
    public function aTicketOfAnotherOrganizationThanTheTenantIsNotFoundThoughItIsForEveryone(): void
    {
        $this->expectExceptionObject(new TicketNotFound('That ticket does not exist.'));

        $this->visibleTickets->refuseUnlessVisibleTo(self::ticketForEveryoneOf(self::BETA_BAKERY), new UserId(self::LENA));
    }

    #[Test]
    public function noTenantIsNotFound(): void
    {
        $this->tenantContext->clear();
        $this->expectExceptionObject(new TicketNotFound('That ticket does not exist.'));

        $this->visibleTickets->refuseUnlessVisibleTo(self::ticketForEveryoneOf(self::ACME_FOODS), new UserId(self::LENA));
    }

    private static function teamOnlyTicketOf(string $organizationId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId($organizationId), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::LENA));
    }

    private static function ticketForEveryoneOf(string $organizationId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId($organizationId), TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::SHOP_TEAM), new UserId(self::LENA));
    }

    /**
     * @param list<string> $teamMemberIds the people in the Shop team; nobody is an admin or the triager
     */
    private static function visibilitiesWithShopTeam(array $teamMemberIds): TicketVisibilities
    {
        return new readonly class(new TeamId(self::SHOP_TEAM), $teamMemberIds) implements TicketVisibilities {
            /**
             * @param list<string> $teamMemberIds
             */
            public function __construct(private TeamId $shopTeamId, private array $teamMemberIds)
            {
            }

            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                $teamIds = \in_array($viewerId->toString(), $this->teamMemberIds, true) ? [$this->shopTeamId] : [];

                return TicketVisibility::for($organizationId, $viewerId, $teamIds, false, null);
            }
        };
    }
}
