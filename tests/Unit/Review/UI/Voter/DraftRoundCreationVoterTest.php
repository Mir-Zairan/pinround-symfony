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
use App\Review\UI\Voter\DraftRoundCreationVoter;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
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
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Lena leads Shop; Omar and Mia are members of it, Mia as its tester; Sara is an Admin and a member of Shop; Ben is a
 * member of Acme Foods with no team. All tickets are Shop's and team-only. Omar is the assignee of the open ticket, of
 * the one in testing and of the one in Done; the unassigned ticket is open. The turn read model says it is Omar's turn
 * on the open ticket, Mia's in testing, Lena's in code review, Ben's on the stale row of the open ticket Ben may not
 * see, Mia's on the ticket in Done, and the Customer's (a label) on the ticket waiting on them.
 */
final class DraftRoundCreationVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000019a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000019a2';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000019a3';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000019a4';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000019a5';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000190a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000190b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000019d1';
    private const string OPEN_TICKET = '0197a3c0-0000-7000-8000-0000000019e1';
    private const string DONE_TICKET = '0197a3c0-0000-7000-8000-0000000019e2';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000019e3';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000019e4';
    private const string UNASSIGNED_TICKET = '0197a3c0-0000-7000-8000-0000000019e5';
    private const string TESTING_TICKET = '0197a3c0-0000-7000-8000-0000000019e6';
    private const string REVIEW_TICKET = '0197a3c0-0000-7000-8000-0000000019e7';
    private const string BENS_STALE_TURN_TICKET = '0197a3c0-0000-7000-8000-0000000019e8';
    private const string CUSTOMERS_TURN_TICKET = '0197a3c0-0000-7000-8000-0000000019e9';

    private InMemoryTicketTurnQueries $ticketTurnQueries;
    private InMemoryTurnUserQueries $turnUserQueries;

    protected function setUp(): void
    {
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries(
            [
                self::snapshotOf(self::OPEN_TICKET, 'doing', self::OMAR),
                self::snapshotOf(self::DONE_TICKET, 'done', self::OMAR),
                self::snapshotOf(self::UNASSIGNED_TICKET, 'todo', null),
                self::snapshotOf(self::TESTING_TICKET, 'check', self::OMAR),
                self::snapshotOf(self::REVIEW_TICKET, 'check', self::OMAR),
                self::snapshotOf(self::BENS_STALE_TURN_TICKET, 'doing', self::OMAR),
                self::snapshotOf(self::CUSTOMERS_TURN_TICKET, 'wait', self::OMAR),
            ],
            new TicketId(self::BROKEN_TICKET),
        );
        $this->turnUserQueries = new InMemoryTurnUserQueries([
            self::OPEN_TICKET => self::OMAR,
            self::TESTING_TICKET => self::MIA,
            self::REVIEW_TICKET => self::LENA,
            self::BENS_STALE_TURN_TICKET => self::BEN,
            self::DONE_TICKET => self::MIA,
            self::CUSTOMERS_TURN_TICKET => null,
        ]);
    }

    #[Test]
    public function theAssigneeOfAnOpenTicketMayStartADraftWithoutItsTurnBeingRead(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(self::OMAR, self::OPEN_TICKET));
        self::assertSame([self::OPEN_TICKET], $this->ticketTurnQueries->askedTicketIds);
        self::assertSame([], $this->turnUserQueries->askedTicketIds);
    }

    /**
     * The token's user and the ticket whose turn is theirs.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function turnHolders(): iterable
    {
        yield 'Mia, the tester, on the ticket in testing' => [self::MIA, self::TESTING_TICKET];
        yield 'Lena, the reviewer, on the ticket in code review' => [self::LENA, self::REVIEW_TICKET];
    }

    #[Test]
    #[DataProvider('turnHolders')]
    public function thePersonWhoseTurnItIsMayStartADraftOnATicketTheyMaySee(string $userId, string $ticketId): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($userId, $ticketId));
        self::assertSame([$ticketId], $this->turnUserQueries->askedTicketIds);
    }

    /**
     * The token's user, the ticket, the tickets the voter asked for, and the tickets whose turn it read.
     *
     * @return iterable<string, array{string, string, list<string>, list<string>}>
     */
    public static function refusedDrafts(): iterable
    {
        yield 'Lena, the lead of Shop, on the ticket whose turn is Omar\'s' => [self::LENA, self::OPEN_TICKET, [self::OPEN_TICKET], [self::OPEN_TICKET]];
        yield 'Sara, an Admin and member of Shop, on the ticket whose turn is Omar\'s' => [self::SARA, self::OPEN_TICKET, [self::OPEN_TICKET], [self::OPEN_TICKET]];
        yield 'Mia, the tester, on the ticket in code review, whose turn is Lena\'s' => [self::MIA, self::REVIEW_TICKET, [self::REVIEW_TICKET], [self::REVIEW_TICKET]];
        yield 'Ben, whose stale turn row names him on a team-only ticket he may not see' => [self::BEN, self::BENS_STALE_TURN_TICKET, [self::BENS_STALE_TURN_TICKET], [self::BENS_STALE_TURN_TICKET]];
        yield 'Mia on the ticket in Done that a stale row says is her turn' => [self::MIA, self::DONE_TICKET, [self::DONE_TICKET], []];
        yield 'Omar, the assignee of a ticket in Done' => [self::OMAR, self::DONE_TICKET, [self::DONE_TICKET], []];
        yield 'Mia on the ticket whose turn is the Customer\'s' => [self::MIA, self::CUSTOMERS_TURN_TICKET, [self::CUSTOMERS_TURN_TICKET], [self::CUSTOMERS_TURN_TICKET]];
        yield 'Omar on an open ticket nobody is assigned and that is nobody\'s turn' => [self::OMAR, self::UNASSIGNED_TICKET, [self::UNASSIGNED_TICKET], [self::UNASSIGNED_TICKET]];
        yield 'Omar on a ticket the organization has not' => [self::OMAR, self::NO_TICKET, [self::NO_TICKET], []];
        yield 'Omar on a ticket whose facts cannot be read' => [self::OMAR, self::BROKEN_TICKET, [self::BROKEN_TICKET], []];
    }

    /**
     * @param list<string> $expectedAskedTicketIds
     * @param list<string> $expectedTurnTicketIds
     */
    #[Test]
    #[DataProvider('refusedDrafts')]
    public function itRefuses(string $userId, string $ticketId, array $expectedAskedTicketIds, array $expectedTurnTicketIds): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($userId, $ticketId));
        self::assertSame($expectedAskedTicketIds, $this->ticketTurnQueries->askedTicketIds);
        self::assertSame($expectedTurnTicketIds, $this->turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function aTurnThatCannotBeReadIsRefused(): void
    {
        $this->turnUserQueries = new InMemoryTurnUserQueries([self::TESTING_TICKET => self::MIA], isTransactionOpen: false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(self::MIA, self::TESTING_TICKET));
        self::assertSame([], $this->turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function nobodyLoggedInIsRefusedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new TicketId(self::OPEN_TICKET), [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
        self::assertSame([], $this->turnUserQueries->askedTicketIds);
    }

    /**
     * The queries read the tenant's tickets, so under another tenant the voter refuses before it reads one.
     */
    #[Test]
    public function aTenantOtherThanTheActorsOrganizationIsRefusedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(self::BETA_BAKERY);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), new TicketId(self::TESTING_TICKET), [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
        self::assertSame([], $this->turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function noTenantIsRefusedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), new TicketId(self::OPEN_TICKET), [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
        self::assertSame([], $this->turnUserQueries->askedTicketIds);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $token = self::tokenOf(self::OMAR);
        $omarsDraft = new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), '0197a3c0-0000-7000-8000-0000000019f1', new TicketId(self::OPEN_TICKET), new UserId(self::OMAR), true, false);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TicketId(self::OPEN_TICKET), [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $omarsDraft, [RoundPermission::DRAFT_CREATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
        self::assertSame([], $this->turnUserQueries->askedTicketIds);
    }

    private function vote(string $userId, string $ticketId): int
    {
        return $this->voterUnder(self::ACME_FOODS)->vote(self::tokenOf($userId), new TicketId($ticketId), [RoundPermission::DRAFT_CREATE]);
    }

    private function voterUnder(?string $tenantId): DraftRoundCreationVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);
        $currentActor = new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [$shop], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), $acmeFoods, new Roles(false, false, [], [], [])),
        ]);
        $ticketVoter = new TicketVoter($currentActor, new RoleTicketVisibilities(self::noMemberships(), self::noTriager()));

        return new DraftRoundCreationVoter(
            $currentActor,
            $tenantContext,
            $this->ticketTurnQueries,
            $this->turnUserQueries,
            new AccessDecisionManager([$ticketVoter], new UnanimousStrategy(false)),
        );
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function snapshotOf(string $ticketId, string $groupCode, ?string $assigneeId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-0000000019c1',
            '0197a3c0-0000-7000-8000-0000000019c2',
            null,
            $groupCode,
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::OMAR)],
            [new UserId(self::MIA)],
            TicketVisibilityFacts::TEAM_ONLY,
            'major',
            null,
            new UserId(self::LENA),
            null === $assigneeId ? null : new UserId($assigneeId),
        );
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
