<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\Api\VerdictPermission;
use App\Review\UI\Voter\VerdictVoter;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\RoleTicketVisibilities;
use App\Ticketing\UI\Voter\TicketVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * VerdictVoter with the real TicketVoter behind Symfony's AccessDecisionManager on the unanimous strategy the app uses
 * (security.yaml). Lena leads Shop; Omar (the assignee), Mia and another tester are members of Shop, and Mia and the
 * other tester are its testers; Raj is a Manager with no team role; Sara is an Admin and a member of Shop; all of Acme
 * Foods. The Beta Bakery admin is an Admin of Beta Bakery who also holds Shop's id as its lead.
 * SHOP-1 has Mia as its tester, SHOP-2 no tester stored, SHOP-3 the other tester, SHOP-4 is team-only with Raj as its
 * tester; every round is shared unless a test says otherwise.
 */
final class VerdictVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000019a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000019a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000019a3';
    private const string ANOTHER_TESTER = '0197a3c0-0000-7000-8000-0000000019a4';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000019a5';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000019a6';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-0000000019a7';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000190a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000190b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000019d1';
    private const string SHOP_1_TESTED_BY_MIA = '0197a3c0-0000-7000-8000-0000000019e1';
    private const string SHOP_2_NO_TESTER = '0197a3c0-0000-7000-8000-0000000019e2';
    private const string SHOP_3_TESTED_BY_ANOTHER = '0197a3c0-0000-7000-8000-0000000019e3';
    private const string SHOP_4_TESTED_BY_RAJ = '0197a3c0-0000-7000-8000-0000000019e4';
    /** A ticket no team owns, with Mia as its tester. */
    private const string NO_TEAM_TICKET = '0197a3c0-0000-7000-8000-0000000019e5';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000019e6';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000019e7';
    private const string ROUND = '0197a3c0-0000-7000-8000-0000000019f1';

    private InMemoryTicketTurnQueries $ticketTurnQueries;

    protected function setUp(): void
    {
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries([
            self::snapshotOf(self::SHOP_1_TESTED_BY_MIA, TicketVisibilityFacts::FOR_EVERYONE, self::MIA),
            self::snapshotOf(self::SHOP_2_NO_TESTER, TicketVisibilityFacts::FOR_EVERYONE, null),
            self::snapshotOf(self::SHOP_3_TESTED_BY_ANOTHER, TicketVisibilityFacts::FOR_EVERYONE, self::ANOTHER_TESTER),
            self::snapshotOf(self::SHOP_4_TESTED_BY_RAJ, TicketVisibilityFacts::TEAM_ONLY, self::RAJ),
            new TicketTurnSnapshot(new TicketId(self::NO_TEAM_TICKET), '0197a3c0-0000-7000-8000-0000000019c1', '0197a3c0-0000-7000-8000-0000000019c2', null, 'doing', null, null, null, [], [], TicketVisibilityFacts::FOR_EVERYONE, 'major', null, new UserId(self::LENA), testerId: new UserId(self::MIA)),
        ], new TicketId(self::BROKEN_TICKET));
    }

    /**
     * The token's user, the round's ticket, and whether SET is granted.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function peopleOnASharedRound(): iterable
    {
        yield 'Mia, the ticket\'s tester' => [self::MIA, self::SHOP_1_TESTED_BY_MIA, true];
        yield 'Lena, the lead of its team' => [self::LENA, self::SHOP_1_TESTED_BY_MIA, true];
        yield 'Omar, its assignee and a member of its team' => [self::OMAR, self::SHOP_1_TESTED_BY_MIA, false];
        yield 'Raj, a Manager with no team role' => [self::RAJ, self::SHOP_1_TESTED_BY_MIA, false];
        yield 'Sara, an Admin and member of its team' => [self::SARA, self::SHOP_1_TESTED_BY_MIA, false];
        yield 'another tester of the team, while Mia is the ticket\'s tester' => [self::ANOTHER_TESTER, self::SHOP_1_TESTED_BY_MIA, false];
        yield 'Mia, a tester of the team, on a ticket with no tester stored' => [self::MIA, self::SHOP_2_NO_TESTER, true];
        yield 'another tester of the team, on a ticket with no tester stored' => [self::ANOTHER_TESTER, self::SHOP_2_NO_TESTER, true];
        yield 'Omar on a ticket with no tester stored' => [self::OMAR, self::SHOP_2_NO_TESTER, false];
        yield 'Mia, a tester of the team, when another tester is the ticket\'s' => [self::MIA, self::SHOP_3_TESTED_BY_ANOTHER, false];
        yield 'Lena, the lead, when another tester is the ticket\'s' => [self::LENA, self::SHOP_3_TESTED_BY_ANOTHER, true];
        // TicketVoter lets Raj see the team-only ticket he tests but not edit it, so only the edit rule denies him.
        yield 'Raj as the ticket\'s tester, who may see but not edit it' => [self::RAJ, self::SHOP_4_TESTED_BY_RAJ, false];
        yield 'Mia as the tester of a ticket no team owns' => [self::MIA, self::NO_TEAM_TICKET, false];
        yield 'the Beta Bakery admin, who holds Shop\'s id' => [self::BETA_ADMIN, self::SHOP_1_TESTED_BY_MIA, false];
    }

    #[Test]
    #[DataProvider('peopleOnASharedRound')]
    public function theTicketsTesterAndTheTeamLeadMayGiveVerdictsAndNobodyElse(string $userId, string $ticketId, bool $mayGiveVerdicts): void
    {
        $vote = $this->voterUnder(self::ACME_FOODS)->vote(self::tokenOf($userId), self::sharedRound($ticketId), [VerdictPermission::SET]);

        self::assertSame($mayGiveVerdicts ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $vote);
    }

    #[Test]
    public function aTicketAnotherVoterHidesDeniesTheTesterAndTheLead(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterDenying(TicketPermission::VIEW));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]));
    }

    #[Test]
    public function aTicketAnotherVoterLocksDeniesTheTesterAndTheLead(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterDenying(TicketPermission::EDIT));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]));
    }

    /**
     * Omar owns the drafts; neither the tester nor the lead gets a verdict on them, and the ticket is not read.
     *
     * @return iterable<string, array{bool, bool}> whether the draft is unshared, whether it is deleted
     */
    public static function draftsNobodyJudges(): iterable
    {
        yield 'an unshared draft' => [true, false];
        yield 'a deleted draft' => [true, true];
        yield 'a deleted round that is shared, which the database never holds' => [false, true];
    }

    #[Test]
    #[DataProvider('draftsNobodyJudges')]
    public function nobodyGivesVerdictsOnAnUnsharedOrDeletedDraft(bool $isUnsharedDraft, bool $isDeleted): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $draft = self::round(self::ACME_FOODS, self::SHOP_1_TESTED_BY_MIA, $isUnsharedDraft, $isDeleted);

        foreach ([self::MIA, self::LENA, self::OMAR] as $userId) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf($userId), $draft, [VerdictPermission::SET]), $userId);
        }
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * Mia is the tester of SHOP-1, which the queries answer whatever the round's organization, so only the organization
     * check denies her before the ticket is read.
     */
    #[Test]
    public function aRoundOfAnotherOrganizationIsDeniedBeforeItsTicketIsRead(): void
    {
        $vote = $this->voterUnder(self::ACME_FOODS)->vote(self::tokenOf(self::MIA), self::round(self::BETA_BAKERY, self::SHOP_1_TESTED_BY_MIA, false, false), [VerdictPermission::SET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function aTenantOtherThanTheActorsOrganizationIsDeniedBeforeTheTicketIsRead(): void
    {
        $vote = $this->voterUnder(self::BETA_BAKERY)->vote(self::tokenOf(self::MIA), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function noTenantIsDenied(): void
    {
        $vote = $this->voterUnder(null)->vote(self::tokenOf(self::MIA), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function nobodyLoggedInIsDenied(): void
    {
        $vote = $this->voterUnder(self::ACME_FOODS)->vote(new NullToken(), self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTickets(): iterable
    {
        yield 'a ticket whose facts cannot be read' => [self::BROKEN_TICKET];
        yield 'a ticket the organization has not' => [self::NO_TICKET];
    }

    #[Test]
    #[DataProvider('unreadableTickets')]
    public function aRoundWhoseTicketCannotBeReadIsDeniedToTheLead(string $ticketId): void
    {
        $vote = $this->voterUnder(self::ACME_FOODS)->vote(self::tokenOf(self::LENA), self::sharedRound($ticketId), [VerdictPermission::SET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
        self::assertSame([$ticketId], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $token = self::tokenOf(self::MIA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::sharedRound(self::SHOP_1_TESTED_BY_MIA), [RoundPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TicketId(self::SHOP_1_TESTED_BY_MIA), [VerdictPermission::SET]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [VerdictPermission::SET]));
    }

    private static function sharedRound(string $ticketId): RoundAccessFacts
    {
        return self::round(self::ACME_FOODS, $ticketId, false, false);
    }

    private static function round(string $organizationId, string $ticketId, bool $isUnsharedDraft, bool $isDeleted): RoundAccessFacts
    {
        return new RoundAccessFacts(new OrganizationId($organizationId), self::ROUND, new TicketId($ticketId), new UserId(self::OMAR), $isUnsharedDraft, $isDeleted);
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voterUnder(?string $tenantId, ?VoterInterface $otherVoter = null): VerdictVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }
        $currentActor = self::currentActor();
        $voters = [new TicketVoter($currentActor, new RoleTicketVisibilities(self::noMemberships(), self::noTriager())), ...(null === $otherVoter ? [] : [$otherVoter])];

        return new VerdictVoter($currentActor, $tenantContext, $this->ticketTurnQueries, new AccessDecisionManager($voters, new UnanimousStrategy(false)));
    }

    private static function currentActor(): FixedCurrentActor
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);

        return new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::ANOTHER_TESTER => new ActorContext(new UserId(self::ANOTHER_TESTER), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [$shop], [])),
            self::BETA_ADMIN => new ActorContext(new UserId(self::BETA_ADMIN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [$shop], [], [])),
        ]);
    }

    private static function snapshotOf(string $ticketId, string $visibility, ?string $testerId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-0000000019c1',
            '0197a3c0-0000-7000-8000-0000000019c2',
            'test',
            'check',
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::OMAR)],
            [new UserId(self::MIA), new UserId(self::ANOTHER_TESTER)],
            $visibility,
            'major',
            null,
            new UserId(self::LENA),
            new UserId(self::OMAR),
            testerId: null === $testerId ? null : new UserId($testerId),
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
