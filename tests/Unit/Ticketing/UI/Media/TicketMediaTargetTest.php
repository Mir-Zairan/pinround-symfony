<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Media;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\RoleTicketVisibilities;
use App\Ticketing\UI\Media\TicketMediaTarget;
use App\Ticketing\UI\Voter\TicketVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * TicketMediaTarget with the real TicketVoter behind Symfony's AuthorizationChecker on the unanimous strategy the app
 * uses (security.yaml). Lena leads Shop, Omar is a member and Mia a viewer of Shop, Raj is a Manager of no team; all of
 * Acme Foods. Noel is a plain member of Acme Foods in no team. Ben is an Admin of Beta Bakery. SHOP-1 is a Shop ticket
 * for everyone, MOBILE-1 one of the Mobile team; SHOP-2 and MOBILE-2 are team-only tickets of Shop and Mobile.
 */
final class TicketMediaTargetTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000016a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000016a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000016a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000016a4';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000016a5';
    private const string NOEL = '0197a3c0-0000-7000-8000-0000000016a6';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000160a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000160b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000016d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000016d2';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000016e1';
    private const string MOBILE_1 = '0197a3c0-0000-7000-8000-0000000016e2';
    private const string SHOP_2 = '0197a3c0-0000-7000-8000-0000000016e5';
    private const string MOBILE_2 = '0197a3c0-0000-7000-8000-0000000016e6';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000016e3';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000016e4';

    #[Test]
    public function aLeadAndAMemberOfTheTicketsTeamMayAttachToIt(): void
    {
        self::assertTrue(self::targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_1));
        self::assertTrue(self::targetFor(self::tokenOf(self::LENA))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::SHOP_1));
    }

    #[Test]
    public function aMemberOfTheTeamOfATeamOnlyTicketMayAttachToIt(): void
    {
        self::assertTrue(self::targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_2));
    }

    #[Test]
    public function aTicketIdInCapitalsIsTheSameTicket(): void
    {
        self::assertTrue(self::targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), strtoupper(self::SHOP_1)));
    }

    /**
     * The token's user, who is asked about, and the ticket.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedAttachments(): iterable
    {
        yield 'Mia, a viewer of Shop, who may see but not edit' => [self::MIA, self::MIA, self::SHOP_1];
        yield 'Raj, a Manager with no team role' => [self::RAJ, self::RAJ, self::SHOP_1];
        yield 'Omar, on a ticket of a team he is not in' => [self::OMAR, self::OMAR, self::MOBILE_1];
        yield 'Omar asked about for Lena' => [self::OMAR, self::LENA, self::SHOP_1];
        yield 'Lena asked about for Omar' => [self::LENA, self::OMAR, self::SHOP_1];
        yield 'a ticket id that is no uuid' => [self::OMAR, self::OMAR, 'SHOP-1'];
        yield 'a ticket id with a trailing newline' => [self::OMAR, self::OMAR, self::SHOP_1."\n"];
        yield 'an empty ticket id' => [self::OMAR, self::OMAR, ''];
        yield 'a ticket the organization has not' => [self::OMAR, self::OMAR, self::NO_TICKET];
        yield 'a ticket whose facts cannot be read' => [self::OMAR, self::OMAR, self::BROKEN_TICKET];
    }

    #[Test]
    #[DataProvider('refusedAttachments')]
    public function itRefuses(string $tokenUserId, string $askedUserId, string $ticketId): void
    {
        self::assertFalse(self::targetFor(self::tokenOf($tokenUserId))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($askedUserId), $ticketId));
    }

    #[Test]
    public function anOrganizationOtherThanTheActorsIsRefused(): void
    {
        self::assertFalse(self::targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::BETA_BAKERY), new UserId(self::OMAR), self::SHOP_1));
        self::assertFalse(self::targetFor(self::tokenOf(self::BEN))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::BEN), self::SHOP_1));
    }

    #[Test]
    public function nobodyLoggedInOrNoTokenAtAllIsRefused(): void
    {
        self::assertFalse(self::targetFor(new NullToken())->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_1));
        self::assertFalse(self::targetFor(null)->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_1));
        self::assertFalse(self::targetFor(self::tokenOf('0197a3c0-0000-7000-8000-0000000016ff'))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId('0197a3c0-0000-7000-8000-0000000016ff'), self::SHOP_1));
    }

    #[Test]
    public function aTicketAnotherVoterHidesIsRefusedEvenToAMember(): void
    {
        $target = self::targetFor(self::tokenOf(self::OMAR), self::voterDenying(TicketPermission::VIEW));

        self::assertFalse($target->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_1));
    }

    #[Test]
    public function aTicketAnotherVoterLocksIsRefusedEvenToAMember(): void
    {
        $target = self::targetFor(self::tokenOf(self::OMAR), self::voterDenying(TicketPermission::EDIT));

        self::assertFalse($target->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_1));
    }

    #[Test]
    public function itDecidesTheTicketKind(): void
    {
        self::assertSame('ticket', self::targetFor(null)->targetKind());
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function targetFor(?TokenInterface $token, ?VoterInterface $otherVoter = null): TicketMediaTarget
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);
        $currentActor = self::currentActor();
        $voters = [new TicketVoter($currentActor, new RoleTicketVisibilities(self::noMemberships(), self::noTriager())), ...(null === $otherVoter ? [] : [$otherVoter])];

        return new TicketMediaTarget(
            $tokenStorage,
            $currentActor,
            self::ticketTurnQueries(),
            new AuthorizationChecker($tokenStorage, new AccessDecisionManager($voters, new UnanimousStrategy(false))),
        );
    }

    private static function currentActor(): FixedCurrentActor
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);

        return new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop, new TeamId(self::MOBILE)], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [], [$shop])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::NOEL => new ActorContext(new UserId(self::NOEL), $acmeFoods, new Roles(false, false, [], [], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [], [], [])),
        ]);
    }

    /**
     * Like the real queries: a ticket of the tenant by id, null for one it has not, and a LogicException for one whose
     * facts are inconsistent.
     */
    private static function ticketTurnQueries(): TicketTurnQueries
    {
        $snapshotOf = static fn (string $ticketId, string $teamId, string $visibility = TicketVisibilityFacts::FOR_EVERYONE): TicketTurnSnapshot => new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-0000000016c1',
            '0197a3c0-0000-7000-8000-0000000016c2',
            'backlog',
            'todo',
            new TeamId($teamId),
            new UserId(self::LENA),
            'Lena',
            [],
            [],
            $visibility,
            'major',
            null,
            new UserId(self::LENA),
        );

        return new readonly class([$snapshotOf(self::SHOP_1, self::SHOP), $snapshotOf(self::MOBILE_1, self::MOBILE), $snapshotOf(self::SHOP_2, self::SHOP, TicketVisibilityFacts::TEAM_ONLY), $snapshotOf(self::MOBILE_2, self::MOBILE, TicketVisibilityFacts::TEAM_ONLY)], new TicketId(self::BROKEN_TICKET)) implements TicketTurnQueries {
            /**
             * @param list<TicketTurnSnapshot> $snapshots
             */
            public function __construct(private array $snapshots, private TicketId $brokenTicketId)
            {
            }

            public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
            {
                if ($ticketId->equals($this->brokenTicketId)) {
                    throw new \LogicException('The ticket\'s step is not the organization\'s.');
                }
                foreach ($this->snapshots as $snapshot) {
                    if ($snapshot->ticketId->equals($ticketId)) {
                        return $snapshot;
                    }
                }

                return null;
            }
        };
    }

    /**
     * Another voter on tickets that denies $deniedAttribute, which the unanimous strategy lets outweigh TicketVoter.
     */
    private static function voterDenying(string $deniedAttribute): VoterInterface
    {
        return new class($deniedAttribute) extends Voter {
            public function __construct(private readonly string $deniedAttribute)
            {
            }

            protected function supports(string $attribute, mixed $subject): bool
            {
                return $attribute === $this->deniedAttribute && $subject instanceof TicketVisibilityFacts;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return false;
            }
        };
    }

    private static function noMemberships(): MembershipQueries
    {
        return new readonly class implements MembershipQueries {
            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('The voter decides with the actor\'s roles and reads no membership.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                throw new \LogicException('The voter decides with the actor\'s roles and reads no membership.');
            }

            public function membersAmong(OrganizationId $organizationId, array $userIds): array
            {
                throw new \LogicException('The voter decides with the actor\'s roles and reads no membership.');
            }
        };
    }

    private static function noTriager(): SettingsQueries
    {
        return new readonly class implements SettingsQueries {
            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                throw new \LogicException('The voter reads no step policy.');
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return null;
            }
        };
    }
}
