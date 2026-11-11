<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\UI\Voter\TicketCreationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TicketCreationVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000016a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000016a2';
    private const string SHOP_VIEWER = '0197a3c0-0000-7000-8000-0000000016a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000016a4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000016a5';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000016a6';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-0000000016a7';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000160a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000160b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000016d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000016d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000016d3';

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

    /**
     * Lena leads Shop; Omar is a member of Shop; the Shop viewer is its viewer; Raj is a Manager and Sara an Admin,
     * neither with a team role; Ben has no role; the Beta Bakery admin is an Admin of Beta Bakery.
     *
     * @return iterable<string, array{string, string, bool}> actor, team, granted
     */
    public static function matrix(): iterable
    {
        foreach (['Lena' => self::LENA, 'Omar' => self::OMAR, 'the Shop viewer' => self::SHOP_VIEWER, 'Raj' => self::RAJ, 'Sara' => self::SARA, 'Ben' => self::BEN] as $name => $userId) {
            yield $name.' for Shop' => [$userId, self::SHOP, true];
            yield $name.' for Mobile, a team of theirs with no role in it' => [$userId, self::MOBILE, true];
            yield $name.' for Checkout of Beta Bakery' => [$userId, self::CHECKOUT, false];
        }
        yield 'the Beta Bakery admin for Checkout' => [self::BETA_ADMIN, self::CHECKOUT, true];
        yield 'the Beta Bakery admin for Shop of Acme Foods' => [self::BETA_ADMIN, self::SHOP, false];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function everyUserOfTheTeamsOrganizationReportsAProblemToIt(string $actor, string $teamId, bool $isGranted): void
    {
        self::assertSame(
            $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $this->voter()->vote(self::tokenOf($actor), new TeamId($teamId), [TicketPermission::CREATE]),
        );
    }

    #[Test]
    public function withoutAnActorItIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), new TeamId(self::SHOP), [TicketPermission::CREATE]));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function theTeamsOfAnOrganizationAreReadOncePerRequestAndAgainAfterTheReset(): void
    {
        $voter = $this->voter();

        $voter->vote(self::tokenOf(self::OMAR), new TeamId(self::SHOP), [TicketPermission::CREATE]);
        $voter->vote(self::tokenOf(self::SARA), new TeamId(self::MOBILE), [TicketPermission::CREATE]);
        self::assertCount(1, $this->teamQueries);
        $voter->vote(self::tokenOf(self::BETA_ADMIN), new TeamId(self::CHECKOUT), [TicketPermission::CREATE]);
        self::assertCount(2, $this->teamQueries);

        $voter->reset();
        $voter->vote(self::tokenOf(self::OMAR), new TeamId(self::SHOP), [TicketPermission::CREATE]);

        self::assertCount(3, $this->teamQueries);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::SHOP), [TicketPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [TicketPermission::CREATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::SHOP, [TicketPermission::CREATE]));
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voter(): TicketCreationVoter
    {
        $shop = new TeamId(self::SHOP);
        $actor = static fn (string $userId, Roles $roles, string $organizationId = self::ACME_FOODS): ActorContext => new ActorContext(new UserId($userId), new OrganizationId($organizationId), $roles);

        return new TicketCreationVoter(new FixedCurrentActor([
            self::LENA => $actor(self::LENA, new Roles(false, false, [$shop], [], [])),
            self::OMAR => $actor(self::OMAR, new Roles(false, false, [], [$shop], [])),
            self::SHOP_VIEWER => $actor(self::SHOP_VIEWER, new Roles(false, false, [], [], [$shop])),
            self::RAJ => $actor(self::RAJ, new Roles(false, true, [], [], [])),
            self::SARA => $actor(self::SARA, new Roles(true, false, [], [], [])),
            self::BEN => $actor(self::BEN, Roles::none()),
            self::BETA_ADMIN => $actor(self::BETA_ADMIN, new Roles(true, false, [new TeamId(self::CHECKOUT)], [], []), self::BETA_BAKERY),
        ]), $this->teamQueries);
    }
}
