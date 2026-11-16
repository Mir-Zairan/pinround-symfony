<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Review\UI\RoundsController;
use App\Review\UI\VisibleTicketLookup;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class VisibleTicketLookupTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000001';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000000002';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000003';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000000004';
    private const string KEY = 'SHOP-1';

    #[Test]
    public function aLoggedInPersonWhoMaySeeTheTicketGetsItWithTheirActor(): void
    {
        $visible = $this->lookup(loggedIn: true, granted: [OrganizationPermission::VIEW, TicketPermission::VIEW], page: self::page())->visibleTicketOf(self::KEY);

        self::assertSame(self::KEY, $visible->ticket->key);
        self::assertSame(self::OMAR, $visible->actor->userId()->toString());
    }

    #[Test]
    public function noOneLoggedInIsAccessDenied(): void
    {
        $lookup = $this->lookup(loggedIn: false, granted: [OrganizationPermission::VIEW, TicketPermission::VIEW], page: self::page());

        $this->expectException(AccessDeniedException::class);

        $lookup->visibleTicketOf(self::KEY);
    }

    #[Test]
    public function aDeniedOrganizationViewIsAccessDeniedEvenForAVisibleTicket(): void
    {
        $lookup = $this->lookup(loggedIn: true, granted: [TicketPermission::VIEW], page: self::page());

        $this->expectException(AccessDeniedException::class);

        $lookup->visibleTicketOf(self::KEY);
    }

    #[Test]
    public function anUnknownKeyIsTheTicketPagesNotFound(): void
    {
        $lookup = $this->lookup(loggedIn: true, granted: [OrganizationPermission::VIEW, TicketPermission::VIEW], page: null);

        try {
            $lookup->visibleTicketOf('SHOP-404');
            self::fail('An unknown key must be refused.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame(RoundsController::NOT_FOUND, $refusal->getMessage());
        }
    }

    #[Test]
    public function aTicketThePersonMayNotViewIsTheSameNotFound(): void
    {
        $lookup = $this->lookup(loggedIn: true, granted: [OrganizationPermission::VIEW], page: self::page());

        try {
            $lookup->visibleTicketOf(self::KEY);
            self::fail('A hidden ticket must be refused.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame(RoundsController::NOT_FOUND, $refusal->getMessage());
        }
    }

    /**
     * @param list<string> $granted the permissions the checker grants, and denies all others
     */
    private function lookup(bool $loggedIn, array $granted, ?TicketPageView $page): VisibleTicketLookup
    {
        $organizationId = new OrganizationId(self::ACME_FOODS);
        $actor = new ActorContext(new UserId(self::OMAR), $organizationId, Roles::none());
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set($organizationId);

        $tokenStorage = new TokenStorage();
        if ($loggedIn) {
            $user = new InMemoryUser(self::OMAR, null);
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', []));
        }

        $visibilities = self::createStub(TicketVisibilities::class);
        $visibilities->method('of')->willReturn(TicketVisibility::for($organizationId, new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM)], false, null));
        $ticketQueries = self::createStub(TicketQueries::class);
        $ticketQueries->method('pageOf')->willReturn($page);

        $checker = new class($granted) implements AuthorizationCheckerInterface {
            /**
             * @param list<string> $granted
             */
            public function __construct(private readonly array $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return \in_array($attribute, $this->granted, true);
            }
        };

        return new VisibleTicketLookup($tenantContext, new FixedCurrentActor([self::OMAR => $actor]), $visibilities, $ticketQueries, $checker, $tokenStorage);
    }

    private static function page(): TicketPageView
    {
        $organizationId = new OrganizationId(self::ACME_FOODS);
        $facts = new TicketVisibilityFacts($organizationId, TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::OMAR));
        $at = new \DateTimeImmutable('2027-03-15 09:00:00+00:00');

        return new TicketPageView(
            new TicketId(self::TICKET),
            self::KEY,
            'Checkout button does nothing',
            '',
            'bug',
            'major',
            null,
            null,
            [],
            $at,
            'Shop',
            'In progress',
            'doing',
            'Doing',
            'Omar',
            null,
            $facts,
            new TicketTurnSnapshot(new TicketId(self::TICKET), '0197a3c0-0000-7000-8000-000000005121', '0197a3c0-0000-7000-8000-000000005122', 'doing', 'doing', new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), 'Omar', [], [], TicketVisibilityFacts::TEAM_ONLY, 'major', null, new UserId(self::OMAR)),
            [],
            1,
        );
    }
}
