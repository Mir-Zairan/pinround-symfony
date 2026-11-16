<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistPermission;
use App\Review\Api\VerdictPermission;
use App\Review\UI\Voter\CheckItemVoter;
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
 * CheckItemVoter with the real TicketVoter behind Symfony's AccessDecisionManager on the unanimous strategy the app uses
 * (security.yaml). Lena leads Shop; Omar, another developer, Mia and another tester are members of Shop, and Mia and
 * the other tester are its testers; Raj is a Manager with no team role; Sara is an Admin and a member of Shop; all of
 * Acme Foods. The Beta Bakery admin is an Admin of Beta Bakery who also holds Shop's id as its lead.
 * SHOP-1 is Omar's with Mia as its tester, SHOP-2 Omar's with no tester stored, SHOP-3 the other developer's with the
 * other tester, SHOP-4 is team-only and Raj's, with Raj as its tester.
 */
final class CheckItemVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-000000001ba1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000001ba2';
    private const string MIA = '0197a3c0-0000-7000-8000-000000001ba3';
    private const string ANOTHER_TESTER = '0197a3c0-0000-7000-8000-000000001ba4';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000001ba5';
    private const string SARA = '0197a3c0-0000-7000-8000-000000001ba6';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-000000001ba7';
    private const string ANOTHER_DEVELOPER = '0197a3c0-0000-7000-8000-000000001ba8';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001b0a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001b0b';
    private const string SHOP = '0197a3c0-0000-7000-8000-000000001bd1';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-000000001be1';
    private const string SHOP_2_NO_TESTER = '0197a3c0-0000-7000-8000-000000001be2';
    private const string SHOP_3_OF_OTHERS = '0197a3c0-0000-7000-8000-000000001be3';
    private const string SHOP_4_RAJS = '0197a3c0-0000-7000-8000-000000001be4';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000001be6';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-000000001be7';
    private const string ITEM = '0197a3c0-0000-7000-8000-000000001bf1';
    private const string ROUND = '0197a3c0-0000-7000-8000-000000001bf2';

    private InMemoryTicketTurnQueries $ticketTurnQueries;

    protected function setUp(): void
    {
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries([
            self::snapshotOf(self::SHOP_1, TicketVisibilityFacts::FOR_EVERYONE, self::OMAR, self::MIA),
            self::snapshotOf(self::SHOP_2_NO_TESTER, TicketVisibilityFacts::FOR_EVERYONE, self::OMAR, null),
            self::snapshotOf(self::SHOP_3_OF_OTHERS, TicketVisibilityFacts::FOR_EVERYONE, self::ANOTHER_DEVELOPER, self::ANOTHER_TESTER),
            self::snapshotOf(self::SHOP_4_RAJS, TicketVisibilityFacts::TEAM_ONLY, self::RAJ, self::RAJ),
        ], new TicketId(self::BROKEN_TICKET));
    }

    /**
     * The token's user, the item's ticket, and whether MARK_FIXED and CHECK are granted.
     *
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function peopleOnAnItem(): iterable
    {
        yield 'Omar, the ticket\'s assignee' => [self::OMAR, self::SHOP_1, true, false];
        yield 'Mia, the ticket\'s tester' => [self::MIA, self::SHOP_1, false, true];
        yield 'Lena, the lead of its team' => [self::LENA, self::SHOP_1, false, false];
        yield 'Raj, a Manager with no team role' => [self::RAJ, self::SHOP_1, false, false];
        yield 'Sara, an Admin and member of its team' => [self::SARA, self::SHOP_1, false, false];
        yield 'another tester of the team, while Mia is the ticket\'s tester' => [self::ANOTHER_TESTER, self::SHOP_1, false, false];
        yield 'another developer of the team' => [self::ANOTHER_DEVELOPER, self::SHOP_1, false, false];
        yield 'Mia, a tester of the team, on a ticket with no tester stored' => [self::MIA, self::SHOP_2_NO_TESTER, false, true];
        yield 'another tester of the team, on a ticket with no tester stored' => [self::ANOTHER_TESTER, self::SHOP_2_NO_TESTER, false, true];
        yield 'Omar, its assignee, on a ticket with no tester stored' => [self::OMAR, self::SHOP_2_NO_TESTER, true, false];
        yield 'Omar on a ticket another developer has' => [self::OMAR, self::SHOP_3_OF_OTHERS, false, false];
        yield 'Mia when another tester is the ticket\'s' => [self::MIA, self::SHOP_3_OF_OTHERS, false, false];
        // TicketVoter lets Raj see the team-only ticket he has but not edit it, so only the edit rule denies him.
        yield 'Raj as assignee and tester, who may see but not edit the ticket' => [self::RAJ, self::SHOP_4_RAJS, false, false];
        yield 'the Beta Bakery admin, who holds Shop\'s id' => [self::BETA_ADMIN, self::SHOP_1, false, false];
    }

    #[Test]
    #[DataProvider('peopleOnAnItem')]
    public function theAssigneeMarksFixedAndTheTesterChecksAndNobodyElse(string $userId, string $ticketId, bool $mayMarkFixed, bool $mayCheck): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $token = self::tokenOf($userId);
        $item = self::itemOf(self::ACME_FOODS, $ticketId);

        self::assertSame(
            [self::voteFor($mayMarkFixed), self::voteFor($mayCheck)],
            [$voter->vote($token, $item, [ChecklistPermission::MARK_FIXED]), $voter->vote($token, $item, [ChecklistPermission::CHECK])],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ticketPermissionsAnotherVoterDenies(): iterable
    {
        yield 'a ticket another voter hides' => [TicketPermission::VIEW];
        yield 'a ticket another voter locks' => [TicketPermission::EDIT];
    }

    #[Test]
    #[DataProvider('ticketPermissionsAnotherVoterDenies')]
    public function aTicketAnotherVoterDeniesIsDeniedToTheAssigneeAndTheTester(string $deniedAttribute): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterDenying($deniedAttribute));
        $item = self::itemOf(self::ACME_FOODS, self::SHOP_1);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $item, [ChecklistPermission::MARK_FIXED]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), $item, [ChecklistPermission::CHECK]));
    }

    /**
     * Omar is the assignee and Mia the tester of SHOP-1, which the queries answer whatever the item's organization, so
     * only the organization check denies them before the ticket is read.
     */
    #[Test]
    public function anItemOfAnotherOrganizationIsDeniedBeforeItsTicketIsRead(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $item = self::itemOf(self::BETA_BAKERY, self::SHOP_1);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $item, [ChecklistPermission::MARK_FIXED]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), $item, [ChecklistPermission::CHECK]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function aTenantOtherThanTheActorsOrganizationIsDeniedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(self::BETA_BAKERY);
        $item = self::itemOf(self::ACME_FOODS, self::SHOP_1);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $item, [ChecklistPermission::MARK_FIXED]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), $item, [ChecklistPermission::CHECK]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function noTenantIsDenied(): void
    {
        $voter = $this->voterUnder(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::itemOf(self::ACME_FOODS, self::SHOP_1), [ChecklistPermission::MARK_FIXED]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function nobodyLoggedInIsDenied(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), self::itemOf(self::ACME_FOODS, self::SHOP_1), [ChecklistPermission::CHECK]));
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
    public function anItemWhoseTicketCannotBeReadIsDenied(string $ticketId): void
    {
        $vote = $this->voterUnder(self::ACME_FOODS)->vote(self::tokenOf(self::OMAR), self::itemOf(self::ACME_FOODS, $ticketId), [ChecklistPermission::MARK_FIXED]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
        self::assertSame([$ticketId], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $token = self::tokenOf(self::OMAR);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::itemOf(self::ACME_FOODS, self::SHOP_1), [VerdictPermission::SET]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TicketId(self::SHOP_1), [ChecklistPermission::MARK_FIXED]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [ChecklistPermission::CHECK]));
    }

    private static function voteFor(bool $isGranted): int
    {
        return $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED;
    }

    private static function itemOf(string $organizationId, string $ticketId): CheckItemAccessFacts
    {
        return new CheckItemAccessFacts(new OrganizationId($organizationId), self::ITEM, self::ROUND, new TicketId($ticketId), 1);
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voterUnder(?string $tenantId, ?VoterInterface $otherVoter = null): CheckItemVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }
        $currentActor = self::currentActor();
        $voters = [new TicketVoter($currentActor, new RoleTicketVisibilities(self::noMemberships(), self::noTriager())), ...(null === $otherVoter ? [] : [$otherVoter])];

        return new CheckItemVoter($currentActor, $tenantContext, $this->ticketTurnQueries, new AccessDecisionManager($voters, new UnanimousStrategy(false)));
    }

    private static function currentActor(): FixedCurrentActor
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);

        return new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::ANOTHER_DEVELOPER => new ActorContext(new UserId(self::ANOTHER_DEVELOPER), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::ANOTHER_TESTER => new ActorContext(new UserId(self::ANOTHER_TESTER), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [$shop], [])),
            self::BETA_ADMIN => new ActorContext(new UserId(self::BETA_ADMIN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [$shop], [], [])),
        ]);
    }

    private static function snapshotOf(string $ticketId, string $visibility, string $assigneeId, ?string $testerId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-000000001bc1',
            '0197a3c0-0000-7000-8000-000000001bc2',
            'progress',
            'doing',
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::OMAR), new UserId(self::ANOTHER_DEVELOPER)],
            [new UserId(self::MIA), new UserId(self::ANOTHER_TESTER)],
            $visibility,
            'major',
            null,
            new UserId(self::LENA),
            new UserId($assigneeId),
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
