<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\RoleTicketVisibilities;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoleTicketVisibilitiesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a2a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a2a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a2c1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-00000000a2c2';
    private const string WEB_TEAM = '0197a3c0-0000-7000-8000-00000000a2c3';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a2b1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a2b3';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a2b5';

    /** @var list<string> `user@organization` of each roles read, in order */
    private array $roleReads = [];

    /** @var list<string> the organizations whose triager was read, in order */
    private array $triagerReads = [];

    /** @var array<string, Roles> by user id; a user left out has no roles */
    private array $storedRoles = [];

    #[Test]
    public function theViewersTeamsOfEveryTeamRoleAreTheirTeams(): void
    {
        $this->storedRoles[self::LENA] = new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [new TeamId(self::MOBILE_TEAM)], [new TeamId(self::WEB_TEAM)]);

        $lena = $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA));

        self::assertSame([self::LENA.'@'.self::ACME_FOODS], $this->roleReads);
        self::assertSame(
            [self::SHOP_TEAM, self::MOBILE_TEAM, self::WEB_TEAM],
            array_map(static fn (TeamId $teamId): string => $teamId->toString(), $lena->teamIds()),
        );
        self::assertSame(
            [true, true, true],
            array_map(static fn (string $teamId): bool => $lena->isVisible(self::restrictedOf(self::ACME_FOODS, $teamId)), [self::SHOP_TEAM, self::MOBILE_TEAM, self::WEB_TEAM]),
        );
    }

    #[Test]
    public function anAdminViewerIsAnAdminAndAManagerIsNot(): void
    {
        $this->storedRoles[self::SARA] = new Roles(true, false, [], [], []);
        $this->storedRoles[self::LENA] = new Roles(false, true, [], [], []);

        self::assertSame(
            [true, false],
            [
                $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA))->isAdmin(),
                $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA))->isAdmin(),
            ],
        );
    }

    #[Test]
    public function aViewerWithNoRolesSeesOnlyTicketsForEveryoneAndTheirOwn(): void
    {
        $mia = $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA));

        self::assertSame([[], false], [$mia->teamIds(), $mia->isAdmin()]);
        self::assertSame(
            [false, true],
            [
                $mia->isVisible(new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::LENA))),
                $mia->isVisible(self::restrictedOf(self::ACME_FOODS, self::SHOP_TEAM)),
            ],
        );
    }

    #[Test]
    public function theTriagerIsTheOneOfTheViewersOrganization(): void
    {
        $sara = $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA));
        $lena = $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA));

        self::assertSame([true, false], [$sara->isTriager(), $lena->isTriager()]);
        self::assertSame([self::ACME_FOODS, self::ACME_FOODS], $this->triagerReads);
    }

    #[Test]
    public function anOrganizationWithNoTriagerMakesNobodyTheTriager(): void
    {
        $sara = $this->visibilities()->of(new OrganizationId(self::BETA_BAKERY), new UserId(self::SARA));

        self::assertFalse($sara->isTriager());
        self::assertSame([self::BETA_BAKERY], $this->triagerReads);
    }

    #[Test]
    public function theViewerSeesOnlyTicketsOfTheirOwnOrganization(): void
    {
        $this->storedRoles[self::SARA] = new Roles(true, false, [], [], []);

        $admin = $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA));

        self::assertSame([self::ACME_FOODS, self::SARA], [$admin->organizationId()->toString(), $admin->viewerId()->toString()]);
        self::assertSame(
            [true, false],
            [$admin->isVisible(self::restrictedOf(self::ACME_FOODS, self::SHOP_TEAM)), $admin->isVisible(self::restrictedOf(self::BETA_BAKERY, self::SHOP_TEAM))],
        );
    }

    #[Test]
    public function theActorsPredicateUsesTheRolesTheActorHoldsAndReadsNoneAgain(): void
    {
        $this->storedRoles[self::LENA] = Roles::none();
        $roles = new Roles(true, false, [new TeamId(self::WEB_TEAM)], [], []);

        $lena = $this->visibilities()->ofActor(new ActorContext(new UserId(self::LENA), new OrganizationId(self::BETA_BAKERY), $roles));

        self::assertSame([], $this->roleReads);
        self::assertSame([self::BETA_BAKERY], $this->triagerReads);
        self::assertSame(
            [self::BETA_BAKERY, self::LENA, [self::WEB_TEAM], true, false],
            [
                $lena->organizationId()->toString(),
                $lena->viewerId()->toString(),
                array_map(static fn (TeamId $teamId): string => $teamId->toString(), $lena->teamIds()),
                $lena->isAdmin(),
                $lena->isTriager(),
            ],
        );
    }

    #[Test]
    public function theActorsPredicateIsTheViewersPredicateForTheSameRoles(): void
    {
        $this->storedRoles[self::SARA] = new Roles(false, true, [new TeamId(self::SHOP_TEAM)], [], [new TeamId(self::WEB_TEAM)]);

        self::assertEquals(
            $this->visibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA)),
            $this->visibilities()->ofActor(new ActorContext(new UserId(self::SARA), new OrganizationId(self::ACME_FOODS), $this->storedRoles[self::SARA])),
        );
    }

    public function readRolesOf(UserId $userId, OrganizationId $organizationId): Roles
    {
        $this->roleReads[] = $userId->toString().'@'.$organizationId->toString();

        return $this->storedRoles[$userId->toString()] ?? Roles::none();
    }

    public function readTriagerOf(OrganizationId $organizationId): ?UserId
    {
        $this->triagerReads[] = $organizationId->toString();

        // Sara triages Acme Foods; Beta Bakery has no triager.
        return self::ACME_FOODS === $organizationId->toString() ? new UserId(self::SARA) : null;
    }

    private function visibilities(): RoleTicketVisibilities
    {
        $memberships = new readonly class($this) implements MembershipQueries {
            public function __construct(private RoleTicketVisibilitiesTest $test)
            {
            }

            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('The ticket visibility reads no organization list.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                return $this->test->readRolesOf($userId, $organizationId);
            }
        };
        $settings = new readonly class($this) implements SettingsQueries {
            public function __construct(private RoleTicketVisibilitiesTest $test)
            {
            }

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                throw new \LogicException('The ticket visibility reads no step policy.');
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return $this->test->readTriagerOf($organizationId);
            }
        };

        return new RoleTicketVisibilities($memberships, $settings);
    }

    /**
     * A restricted ticket that Mia reported.
     */
    private static function restrictedOf(string $organizationId, string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId($organizationId), TicketVisibilityFacts::TEAM_ONLY, new TeamId($teamId), new UserId(self::MIA));
    }
}
