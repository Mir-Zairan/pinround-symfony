<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\ActorTicketVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActorTicketVisibilityTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a2a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a2a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a2c1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-00000000a2c2';
    private const string WEB_TEAM = '0197a3c0-0000-7000-8000-00000000a2c3';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a2b1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a2b3';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a2b5';

    /** @var list<string> the organizations whose triager was read, in order */
    private array $triagerReads = [];

    #[Test]
    public function theActorsTeamsOfEveryTeamRoleAreTheirTeams(): void
    {
        $lena = $this->visibilityOf(self::LENA, new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [new TeamId(self::MOBILE_TEAM)], [new TeamId(self::WEB_TEAM)]));

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
    public function anAdminActorIsAnAdminAndAManagerIsNot(): void
    {
        self::assertSame(
            [true, false],
            [
                $this->visibilityOf(self::LENA, new Roles(true, false, [], [], []))->isAdmin(),
                $this->visibilityOf(self::LENA, new Roles(false, true, [], [], []))->isAdmin(),
            ],
        );
    }

    #[Test]
    public function theTriagerIsTheOneOfTheActorsOrganization(): void
    {
        $sara = $this->visibilityOf(self::SARA, Roles::none());
        $lena = $this->visibilityOf(self::LENA, Roles::none());

        self::assertSame([true, false], [$sara->isTriager(), $lena->isTriager()]);
        self::assertSame([self::ACME_FOODS, self::ACME_FOODS], $this->triagerReads);
    }

    #[Test]
    public function anOrganizationWithNoTriagerMakesNobodyTheTriager(): void
    {
        $sara = $this->visibilityOf(self::SARA, Roles::none(), self::BETA_BAKERY);

        self::assertFalse($sara->isTriager());
        self::assertSame([self::BETA_BAKERY], $this->triagerReads);
    }

    #[Test]
    public function theActorSeesOnlyTicketsOfTheirOwnOrganization(): void
    {
        $admin = $this->visibilityOf(self::SARA, new Roles(true, false, [], [], []));

        self::assertSame(self::ACME_FOODS, $admin->organizationId()->toString());
        self::assertSame(
            [true, false],
            [$admin->isVisible(self::restrictedOf(self::ACME_FOODS, self::SHOP_TEAM)), $admin->isVisible(self::restrictedOf(self::BETA_BAKERY, self::SHOP_TEAM))],
        );
    }

    #[Test]
    public function theViewerIsTheActor(): void
    {
        self::assertSame(self::LENA, $this->visibilityOf(self::LENA, Roles::none())->viewerId()->toString());
    }

    public function readTriagerOf(OrganizationId $organizationId): ?UserId
    {
        $this->triagerReads[] = $organizationId->toString();

        // Sara triages Acme Foods; Beta Bakery has no triager.
        return self::ACME_FOODS === $organizationId->toString() ? new UserId(self::SARA) : null;
    }

    private function visibilityOf(string $userId, Roles $roles, string $organizationId = self::ACME_FOODS): TicketVisibility
    {
        $settings = new readonly class($this) implements SettingsQueries {
            public function __construct(private ActorTicketVisibilityTest $test)
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

        return new ActorTicketVisibility($settings)->for(new ActorContext(new UserId($userId), new OrganizationId($organizationId), $roles));
    }

    /**
     * A restricted ticket that Mia reported.
     */
    private static function restrictedOf(string $organizationId, string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId($organizationId), 'team', new TeamId($teamId), new UserId(self::MIA));
    }
}
