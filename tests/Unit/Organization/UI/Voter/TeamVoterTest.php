<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamPermission;
use App\Organization\Api\TeamQueries;
use App\Organization\UI\Voter\TeamVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Acme Foods (the organization of the request) has the teams Shop, Mobile and Support; Beta Bakery has Checkout.
 */
final class TeamVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000000a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000000a4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000000a5';
    private const string NOOR = '0197a3c0-0000-7000-8000-0000000000a6';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000000d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000000d3';
    private const string SUPPORT = '0197a3c0-0000-7000-8000-0000000000d4';

    private TeamQueries&\Countable $teamQueries;

    protected function setUp(): void
    {
        $this->teamQueries = new class([self::ACME_FOODS => [self::SHOP, self::MOBILE, self::SUPPORT], self::BETA_BAKERY => [self::CHECKOUT]]) implements TeamQueries, \Countable {
            /** @var list<string> the organization id of each teamsOf call */
            private array $teamsOfCalls = [];

            /**
             * @param array<string, list<string>> $teamIdsByOrganization
             */
            public function __construct(private readonly array $teamIdsByOrganization)
            {
            }

            public function summariesOf(OrganizationId $organizationId, array $teamIds): array
            {
                throw new \LogicException('Only teamsOf is expected.');
            }

            public function allSummariesOf(OrganizationId $organizationId): array
            {
                throw new \LogicException('Only teamsOf is expected.');
            }

            public function countOf(OrganizationId $organizationId): int
            {
                throw new \LogicException('Only teamsOf is expected.');
            }

            public function teamsOf(OrganizationId $organizationId): array
            {
                $this->teamsOfCalls[] = $organizationId->toString();

                return array_map(static fn (string $teamId): TeamId => new TeamId($teamId), $this->teamIdsByOrganization[$organizationId->toString()] ?? []);
            }

            /**
             * The number of teamsOf calls so far.
             */
            public function count(): int
            {
                return \count($this->teamsOfCalls);
            }
        };
    }

    #[Test]
    public function aMemberOfOneTeamViewsItButLeadsNothingAndSeesNoOtherTeam(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::MIA, TeamPermission::VIEW, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::MIA, TeamPermission::LEAD, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::MIA, TeamPermission::VIEW, self::MOBILE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::MIA, TeamPermission::LEAD, self::MOBILE));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function aMemberOfTwoTeamsViewsBoth(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::OMAR, TeamPermission::VIEW, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::OMAR, TeamPermission::VIEW, self::MOBILE));
    }

    #[Test]
    public function theLeadLeadsOnlyTheirOwnTeam(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::LENA, TeamPermission::LEAD, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, TeamPermission::LEAD, self::MOBILE));
    }

    #[Test]
    public function aLeadWithNoOrgRoleViewsAndLeadsOnlyTheirOwnTeam(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::NOOR, TeamPermission::VIEW, self::SUPPORT));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::NOOR, TeamPermission::LEAD, self::SUPPORT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::NOOR, TeamPermission::VIEW, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::NOOR, TeamPermission::LEAD, self::SHOP));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function aViewerWithNoOrgRoleViewsTheirTeamButLeadsNothingAndSeesNoOtherTeam(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::MIA, TeamPermission::VIEW, self::SUPPORT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::MIA, TeamPermission::LEAD, self::SUPPORT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::MIA, TeamPermission::VIEW, self::MOBILE));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function anAdminWithNoTeamRoleViewsEveryTeamOfTheOrganizationAndLeadsNone(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::SARA, TeamPermission::VIEW, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::SARA, TeamPermission::VIEW, self::MOBILE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::SARA, TeamPermission::LEAD, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::SARA, TeamPermission::VIEW, self::CHECKOUT));
    }

    #[Test]
    public function aManagerViewsEveryTeamOfTheOrganizationAndLeadsNone(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::RAJ, TeamPermission::VIEW, self::MOBILE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::RAJ, TeamPermission::LEAD, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::RAJ, TeamPermission::VIEW, self::CHECKOUT));
    }

    #[Test]
    public function withoutAnActorBothAreDenied(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new TeamId(self::SHOP), [TeamPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new TeamId(self::SHOP), [TeamPermission::LEAD]));
    }

    #[Test]
    public function theTeamsOfTheOrganizationAreReadOncePerRequestAndAgainAfterTheReset(): void
    {
        $voter = $this->voter();

        $this->decide($voter, self::SARA, TeamPermission::VIEW, self::SHOP);
        $this->decide($voter, self::RAJ, TeamPermission::VIEW, self::MOBILE);
        $this->decide($voter, self::SARA, TeamPermission::VIEW, self::CHECKOUT);
        self::assertCount(1, $this->teamQueries);

        $voter->reset();
        $this->decide($voter, self::SARA, TeamPermission::VIEW, self::SHOP);

        self::assertCount(2, $this->teamQueries);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::SHOP), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [TeamPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::SHOP, [TeamPermission::LEAD]));
    }

    private function decide(TeamVoter $voter, string $userId, string $attribute, string $teamId): int
    {
        return $voter->vote(self::tokenOf($userId), new TeamId($teamId), [$attribute]);
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    /**
     * Lena is an Admin and leads Shop; Omar is a member of Shop and Mobile; Mia is a member of Shop and a viewer of
     * Support; Raj is a Manager and a viewer of Shop; Sara is an Admin with no team role; Noor has no org role and leads
     * Support.
     */
    private function voter(): TeamVoter
    {
        $shop = new TeamId(self::SHOP);
        $mobile = new TeamId(self::MOBILE);
        $support = new TeamId(self::SUPPORT);
        $rolesByUser = [
            self::LENA => new Roles(true, false, [$shop], [], []),
            self::OMAR => new Roles(false, false, [], [$shop, $mobile], []),
            self::MIA => new Roles(false, false, [], [$shop], [$support]),
            self::RAJ => new Roles(false, true, [], [], [$shop]),
            self::SARA => new Roles(true, false, [], [], []),
            self::NOOR => new Roles(false, false, [$support], [], []),
        ];
        $actors = [];
        foreach ($rolesByUser as $userId => $roles) {
            $actors[$userId] = new ActorContext(new UserId($userId), new OrganizationId(self::ACME_FOODS), $roles);
        }

        return new TeamVoter(new FixedCurrentActor($actors), $this->teamQueries);
    }
}
