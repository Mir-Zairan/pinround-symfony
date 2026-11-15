<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Review\Application\RoundUploadTarget;
use App\Review\UI\Media\RoundMediaTarget;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\RoleTicketVisibilities;
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
 * RoundMediaTarget with the real TicketVoter behind Symfony's AuthorizationChecker on the unanimous strategy the app
 * uses (security.yaml). Lena leads Shop, Omar is a member and Mia a viewer of Shop, Sara is an Admin and a member of
 * Shop, Raj is a Manager of no team and Noel a member of Mobile only; all of Acme Foods. Ben is an Admin of Beta Bakery.
 * SHOP-1 is a Shop ticket for everyone, SHOP-2 a team-only one of Shop. Omar's draft and closed draft are his and
 * unshared; Lena's shared draft, the review and the closed review are on SHOP-1, the team-only review on SHOP-2.
 */
final class RoundMediaTargetTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000017a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000017a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000017a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000017a4';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000017a5';
    private const string NOEL = '0197a3c0-0000-7000-8000-0000000017a6';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000017a7';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000170a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000170b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000017d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000017d2';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000017e1';
    private const string SHOP_2 = '0197a3c0-0000-7000-8000-0000000017e2';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000017e3';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000017e4';
    private const string OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000017f1';
    private const string OMAR_CLOSED_DRAFT = '0197a3c0-0000-7000-8000-0000000017f2';
    private const string LENA_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000017f3';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000017f4';
    private const string CLOSED_REVIEW = '0197a3c0-0000-7000-8000-0000000017f5';
    private const string TEAM_ONLY_REVIEW = '0197a3c0-0000-7000-8000-0000000017f6';
    private const string BROKEN_TICKET_REVIEW = '0197a3c0-0000-7000-8000-0000000017f7';
    private const string NO_TICKET_REVIEW = '0197a3c0-0000-7000-8000-0000000017f8';
    /** A round the queries cannot read, as with no transaction open. */
    private const string UNREADABLE_ROUND = '0197a3c0-0000-7000-8000-0000000017f9';
    private const string NO_ROUND = '0197a3c0-0000-7000-8000-0000000017fa';

    private InMemoryRoundUploadTargets $roundUploadTargets;
    private InMemoryTicketTurnQueries $ticketTurnQueries;

    protected function setUp(): void
    {
        $this->roundUploadTargets = new InMemoryRoundUploadTargets([
            self::OMAR_DRAFT => self::round(self::SHOP_1, self::OMAR, true, true),
            self::OMAR_CLOSED_DRAFT => self::round(self::SHOP_1, self::OMAR, true, false),
            self::LENA_SHARED_DRAFT => self::round(self::SHOP_1, self::LENA, false, true),
            self::REVIEW => self::round(self::SHOP_1, self::LENA, false, true),
            self::CLOSED_REVIEW => self::round(self::SHOP_1, self::LENA, false, false),
            self::TEAM_ONLY_REVIEW => self::round(self::SHOP_2, self::LENA, false, true),
            self::BROKEN_TICKET_REVIEW => self::round(self::BROKEN_TICKET, self::LENA, false, true),
            self::NO_TICKET_REVIEW => self::round(self::NO_TICKET, self::LENA, false, true),
        ], self::UNREADABLE_ROUND);
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries(
            [self::snapshotOf(self::SHOP_1, TicketVisibilityFacts::FOR_EVERYONE), self::snapshotOf(self::SHOP_2, TicketVisibilityFacts::TEAM_ONLY)],
            new TicketId(self::BROKEN_TICKET),
        );
    }

    /**
     * Their own unshared draft is the owner's alone to decide: the ticket is not read.
     */
    #[Test]
    public function theOwnerMayAddToTheirOwnOpenUnsharedDraftInAnyCase(): void
    {
        self::assertTrue($this->targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::OMAR_DRAFT));
        self::assertTrue($this->targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), strtoupper(self::OMAR_DRAFT)));
        self::assertSame([[self::OMAR_DRAFT, strtoupper(self::OMAR_DRAFT)], []], $this->askedPorts());
    }

    /**
     * The token's user, and the round.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function allowedUploads(): iterable
    {
        yield 'Omar, a member of Shop, on a review of a Shop ticket' => [self::OMAR, self::REVIEW];
        yield 'Lena, the lead of Shop, on a review of a Shop ticket' => [self::LENA, self::REVIEW];
        yield 'Sara, an Admin and member of Shop, on a review of a Shop ticket' => [self::SARA, self::REVIEW];
        yield 'Omar on a draft Lena shared, which is no longer hers alone' => [self::OMAR, self::LENA_SHARED_DRAFT];
        yield 'Omar on a review of a team-only ticket of his team' => [self::OMAR, self::TEAM_ONLY_REVIEW];
    }

    #[Test]
    #[DataProvider('allowedUploads')]
    public function itAllowsWhoMaySeeAndEditTheTicketOfAnOpenSharedRound(string $userId, string $roundId): void
    {
        self::assertTrue($this->targetFor(self::tokenOf($userId))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($userId), $roundId));
    }

    /**
     * The token's user, who is asked about, the round, and what was asked of the ports: the round ids, then the
     * ticket ids.
     *
     * @return iterable<string, array{string, string, string, array{list<string>, list<string>}}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'Lena, the lead of Shop, on Omar\'s unshared draft' => [self::LENA, self::LENA, self::OMAR_DRAFT, [[self::OMAR_DRAFT], []]];
        yield 'Sara, an Admin who may edit the ticket, on Omar\'s unshared draft' => [self::SARA, self::SARA, self::OMAR_DRAFT, [[self::OMAR_DRAFT], []]];
        yield 'Mia, a viewer of Shop, on Omar\'s unshared draft' => [self::MIA, self::MIA, self::OMAR_DRAFT, [[self::OMAR_DRAFT], []]];
        yield 'Omar on his own closed draft' => [self::OMAR, self::OMAR, self::OMAR_CLOSED_DRAFT, [[self::OMAR_CLOSED_DRAFT], []]];
        yield 'Lena, the lead of Shop, on a closed review of a Shop ticket' => [self::LENA, self::LENA, self::CLOSED_REVIEW, [[self::CLOSED_REVIEW], []]];
        yield 'Mia, a viewer of Shop, who may see but not edit the ticket' => [self::MIA, self::MIA, self::REVIEW, [[self::REVIEW], [self::SHOP_1]]];
        yield 'Raj, a Manager with no team role' => [self::RAJ, self::RAJ, self::REVIEW, [[self::REVIEW], [self::SHOP_1]]];
        yield 'Noel, outside Shop, on a review of a team-only ticket of Shop' => [self::NOEL, self::NOEL, self::TEAM_ONLY_REVIEW, [[self::TEAM_ONLY_REVIEW], [self::SHOP_2]]];
        yield 'Lena asked about for Omar, on his draft' => [self::LENA, self::OMAR, self::OMAR_DRAFT, [[], []]];
        yield 'Omar asked about for Lena' => [self::OMAR, self::LENA, self::REVIEW, [[], []]];
        yield 'a round id that is no uuid' => [self::OMAR, self::OMAR, 'Round 2', [['Round 2'], []]];
        yield 'a round id with a trailing newline' => [self::OMAR, self::OMAR, self::OMAR_DRAFT."\n", [[self::OMAR_DRAFT."\n"], []]];
        yield 'an empty round id' => [self::OMAR, self::OMAR, '', [[''], []]];
        yield 'a round the organization has not' => [self::OMAR, self::OMAR, self::NO_ROUND, [[self::NO_ROUND], []]];
        yield 'a round that cannot be read' => [self::OMAR, self::OMAR, self::UNREADABLE_ROUND, [[self::UNREADABLE_ROUND], []]];
        yield 'a round whose ticket\'s facts cannot be read' => [self::OMAR, self::OMAR, self::BROKEN_TICKET_REVIEW, [[self::BROKEN_TICKET_REVIEW], [self::BROKEN_TICKET]]];
        yield 'a round whose ticket the organization has not' => [self::OMAR, self::OMAR, self::NO_TICKET_REVIEW, [[self::NO_TICKET_REVIEW], [self::NO_TICKET]]];
    }

    /**
     * @param array{list<string>, list<string>} $expectedAskedPorts
     */
    #[Test]
    #[DataProvider('refusedUploads')]
    public function itRefuses(string $tokenUserId, string $askedUserId, string $roundId, array $expectedAskedPorts): void
    {
        self::assertFalse($this->targetFor(self::tokenOf($tokenUserId))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($askedUserId), $roundId));
        self::assertSame($expectedAskedPorts, $this->askedPorts());
    }

    #[Test]
    public function anOrganizationOtherThanTheActorsIsRefusedBeforeAnyRoundIsRead(): void
    {
        self::assertFalse($this->targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::BETA_BAKERY), new UserId(self::OMAR), self::OMAR_DRAFT));
        self::assertFalse($this->targetFor(self::tokenOf(self::BEN))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::BEN), self::REVIEW));
        self::assertSame([[], []], $this->askedPorts());
    }

    #[Test]
    public function nobodyLoggedInOrNoTokenAtAllIsRefusedBeforeAnyRoundIsRead(): void
    {
        $unknownUser = '0197a3c0-0000-7000-8000-0000000017ff';

        self::assertFalse($this->targetFor(new NullToken())->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::OMAR_DRAFT));
        self::assertFalse($this->targetFor(null)->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::OMAR_DRAFT));
        self::assertFalse($this->targetFor(self::tokenOf($unknownUser))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($unknownUser), self::REVIEW));
        self::assertSame([[], []], $this->askedPorts());
    }

    #[Test]
    public function aTicketAnotherVoterHidesIsRefusedEvenToAMember(): void
    {
        $target = $this->targetFor(self::tokenOf(self::OMAR), self::voterDenying(TicketPermission::VIEW));

        self::assertFalse($target->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::REVIEW));
    }

    #[Test]
    public function aTicketAnotherVoterLocksIsRefusedEvenToAMember(): void
    {
        $target = $this->targetFor(self::tokenOf(self::OMAR), self::voterDenying(TicketPermission::EDIT));

        self::assertFalse($target->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::REVIEW));
    }

    #[Test]
    public function itDecidesTheRoundKind(): void
    {
        self::assertSame('round', $this->targetFor(null)->targetKind());
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function askedPorts(): array
    {
        return [$this->roundUploadTargets->askedRoundIds, $this->ticketTurnQueries->askedTicketIds];
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function targetFor(?TokenInterface $token, ?VoterInterface $otherVoter = null): RoundMediaTarget
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);
        $currentActor = self::currentActor();
        $voters = [new TicketVoter($currentActor, new RoleTicketVisibilities(self::noMemberships(), self::noTriager())), ...(null === $otherVoter ? [] : [$otherVoter])];

        return new RoundMediaTarget(
            $tokenStorage,
            $currentActor,
            $this->roundUploadTargets,
            $this->ticketTurnQueries,
            new AuthorizationChecker($tokenStorage, new AccessDecisionManager($voters, new UnanimousStrategy(false))),
        );
    }

    private static function currentActor(): FixedCurrentActor
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);

        return new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [], [$shop])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [$shop], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::NOEL => new ActorContext(new UserId(self::NOEL), $acmeFoods, new Roles(false, false, [], [new TeamId(self::MOBILE)], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [], [$shop], [])),
        ]);
    }

    private static function round(string $ticketId, string $ownerId, bool $isUnsharedDraft, bool $isOpen): RoundUploadTarget
    {
        return new RoundUploadTarget(new TicketId($ticketId), new UserId($ownerId), $isUnsharedDraft, $isOpen);
    }

    private static function snapshotOf(string $ticketId, string $visibility): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-0000000017c1',
            '0197a3c0-0000-7000-8000-0000000017c2',
            'backlog',
            'todo',
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [],
            [],
            $visibility,
            'major',
            null,
            new UserId(self::LENA),
        );
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
