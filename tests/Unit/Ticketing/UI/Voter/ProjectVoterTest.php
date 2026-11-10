<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamPermission;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\ProjectPermission;
use App\Ticketing\UI\Voter\ProjectVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ProjectVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000014a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000014a2';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000014a4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000014a5';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-0000000014a6';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000140a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000140b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000014d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000014d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000014d3';

    private TeamQueries&\Countable $teamQueries;

    protected function setUp(): void
    {
        $this->teamQueries = new class([self::ACME_FOODS => [self::SHOP, self::MOBILE], self::BETA_BAKERY => [self::CHECKOUT]]) implements TeamQueries, \Countable {
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

            public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
            {
                throw new \LogicException('Only teamsOf is expected.');
            }

            public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
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
    public function theLeadCreatesAProjectOnlyForTheirOwnTeamWithoutReadingTheTeams(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::LENA, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::LENA, self::MOBILE));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function anAdminCreatesAProjectForEveryTeamOfTheirOrganizationAndNoneOfAnother(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::SARA, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::SARA, self::MOBILE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::SARA, self::CHECKOUT));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->decide($voter, self::BETA_ADMIN, self::CHECKOUT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::BETA_ADMIN, self::SHOP));
    }

    #[Test]
    public function aManagerOrAMemberCreatesNoProject(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::RAJ, self::SHOP));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->decide($voter, self::OMAR, self::SHOP));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function withoutAnActorItIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), new TeamId(self::SHOP), [ProjectPermission::CREATE]));
    }

    #[Test]
    public function theTeamsOfAnOrganizationAreReadOncePerRequestAndAgainAfterTheReset(): void
    {
        $voter = $this->voter();

        $this->decide($voter, self::SARA, self::SHOP);
        $this->decide($voter, self::SARA, self::MOBILE);
        $this->decide($voter, self::SARA, self::CHECKOUT);
        self::assertCount(1, $this->teamQueries);
        $this->decide($voter, self::BETA_ADMIN, self::CHECKOUT);
        $this->decide($voter, self::BETA_ADMIN, self::SHOP);
        self::assertCount(2, $this->teamQueries);

        $voter->reset();
        $this->decide($voter, self::SARA, self::SHOP);

        self::assertCount(3, $this->teamQueries);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::SHOP), [TeamPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [ProjectPermission::CREATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::SHOP, [ProjectPermission::CREATE]));
    }

    private function decide(ProjectVoter $voter, string $userId, string $teamId): int
    {
        return $voter->vote(self::tokenOf($userId), new TeamId($teamId), [ProjectPermission::CREATE]);
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    /**
     * Lena leads Shop and has no org role; Omar is a member of Shop; Raj is a Manager; Sara is an Admin of Acme Foods
     * with no team role; the Beta Bakery admin is an Admin of Beta Bakery.
     */
    private function voter(): ProjectVoter
    {
        $shop = new TeamId(self::SHOP);
        $actor = static fn (string $userId, string $organizationId, Roles $roles): ActorContext => new ActorContext(new UserId($userId), new OrganizationId($organizationId), $roles);

        return new ProjectVoter(new FixedCurrentActor([
            self::LENA => $actor(self::LENA, self::ACME_FOODS, new Roles(false, false, [$shop], [], [])),
            self::OMAR => $actor(self::OMAR, self::ACME_FOODS, new Roles(false, false, [], [$shop], [])),
            self::RAJ => $actor(self::RAJ, self::ACME_FOODS, new Roles(false, true, [], [], [$shop])),
            self::SARA => $actor(self::SARA, self::ACME_FOODS, new Roles(true, false, [], [], [])),
            self::BETA_ADMIN => $actor(self::BETA_ADMIN, self::BETA_BAKERY, new Roles(true, false, [], [], [])),
        ]), $this->teamQueries);
    }
}
