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
use App\Review\UI\Voter\RoundVoter;
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
 * RoundVoter with the real TicketVoter behind Symfony's AccessDecisionManager on the unanimous strategy the app uses
 * (security.yaml). Lena leads Shop; Omar, another developer and Mia (a tester) are members of Shop; the Shop viewer is
 * its viewer; Raj is a Manager with no team role; Sara is an Admin and a member of Shop; all of Acme Foods. The Beta
 * Bakery admin is an Admin of Beta Bakery who also holds Shop's id as a member, so only the organization denies them.
 * SHOP-1 is a Shop ticket for everyone, SHOP-2 a team-only one of Shop. Omar's draft is his and unshared, on SHOP-1.
 */
final class RoundVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000018a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000018a2';
    private const string ANOTHER_DEVELOPER = '0197a3c0-0000-7000-8000-0000000018a3';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000018a4';
    private const string SHOP_VIEWER = '0197a3c0-0000-7000-8000-0000000018a5';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000018a6';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000018a7';
    private const string BETA_ADMIN = '0197a3c0-0000-7000-8000-0000000018a8';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000180a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000180b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000018d1';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000018e1';
    private const string SHOP_2 = '0197a3c0-0000-7000-8000-0000000018e2';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000018e3';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000018e4';
    /** Team-only Shop tickets that each hold Raj in one column of the people on it. */
    private const array SHOP_2_WITH_RAJ_AS = [
        'reporter' => '0197a3c0-0000-7000-8000-0000000018e5',
        'assignee' => '0197a3c0-0000-7000-8000-0000000018e6',
        'pending assignee' => '0197a3c0-0000-7000-8000-0000000018e7',
        'reviewer' => '0197a3c0-0000-7000-8000-0000000018e8',
        'tester' => '0197a3c0-0000-7000-8000-0000000018e9',
        'waiting-on person' => '0197a3c0-0000-7000-8000-0000000018ea',
    ];
    private const string ROUND = '0197a3c0-0000-7000-8000-0000000018f1';

    private InMemoryTicketTurnQueries $ticketTurnQueries;

    protected function setUp(): void
    {
        $snapshots = [self::snapshotOf(self::SHOP_1, TicketVisibilityFacts::FOR_EVERYONE), self::snapshotOf(self::SHOP_2, TicketVisibilityFacts::TEAM_ONLY)];
        foreach (self::SHOP_2_WITH_RAJ_AS as $column => $ticketId) {
            $snapshots[] = self::snapshotOf($ticketId, TicketVisibilityFacts::TEAM_ONLY, $column);
        }
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries($snapshots, new TicketId(self::BROKEN_TICKET));
    }

    /**
     * Their own unshared draft is the owner's alone to decide: the ticket is not read.
     */
    #[Test]
    public function theOwnerMaySeeAndChangeTheirOwnUnsharedDraft(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::MOVE_TO_REPORT_DRAFT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::ADD_GENERAL_NOTE]), 'General notes are for shared rounds only.');
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * Everyone here but the Beta Bakery admin may see and edit SHOP-1 or at least see it, so only the draft rule
     * denies them.
     *
     * @return iterable<string, array{string, string}> the token's user, the attribute
     */
    public static function othersOnAnUnsharedDraft(): iterable
    {
        $others = [
            'another developer of Shop' => self::ANOTHER_DEVELOPER,
            'Lena, the lead of Shop' => self::LENA,
            'Mia, a tester of Shop' => self::MIA,
            'the Shop viewer' => self::SHOP_VIEWER,
            'Raj, a Manager' => self::RAJ,
            'Sara, an Admin and member of Shop' => self::SARA,
            'the Beta Bakery admin' => self::BETA_ADMIN,
        ];
        foreach ($others as $case => $userId) {
            foreach ([RoundPermission::VIEW, RoundPermission::EDIT, RoundPermission::MOVE_TO_REPORT_DRAFT, RoundPermission::ADD_GENERAL_NOTE] as $attribute) {
                yield $case.' '.$attribute => [$userId, $attribute];
            }
        }
    }

    #[Test]
    #[DataProvider('othersOnAnUnsharedDraft')]
    public function nobodyButTheOwnerMaySeeOrChangeAnUnsharedDraft(string $userId, string $attribute): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::ACME_FOODS)->vote(self::tokenOf($userId), self::omarsDraft(), [$attribute]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function aDeletedDraftIsDeniedEvenToItsOwner(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $deletedDraft = self::round(self::ACME_FOODS, self::SHOP_1, self::OMAR, true, true);
        $deletedSharedRound = self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, true);

        foreach ([RoundPermission::VIEW, RoundPermission::EDIT, RoundPermission::MOVE_TO_REPORT_DRAFT, RoundPermission::COPY_TO_OWN_DRAFT, RoundPermission::ADD_GENERAL_NOTE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $deletedDraft, [$attribute]), $attribute);
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), $deletedSharedRound, [$attribute]), $attribute);
        }
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * The token's user, the ticket of a round Lena shared, and whether VIEW and EDIT are granted.
     *
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function sharedRounds(): iterable
    {
        yield 'Lena, the lead of Shop, on SHOP-1' => [self::LENA, self::SHOP_1, true, true];
        yield 'Omar, a member of Shop, on SHOP-1' => [self::OMAR, self::SHOP_1, true, true];
        yield 'Sara, an Admin and member of Shop, on SHOP-1' => [self::SARA, self::SHOP_1, true, true];
        yield 'Omar on the team-only SHOP-2' => [self::OMAR, self::SHOP_2, true, true];
        yield 'the Shop viewer, who may see but not edit SHOP-1' => [self::SHOP_VIEWER, self::SHOP_1, true, false];
        yield 'the Shop viewer on the team-only SHOP-2' => [self::SHOP_VIEWER, self::SHOP_2, true, false];
        yield 'Raj, a Manager with no team role, on SHOP-1' => [self::RAJ, self::SHOP_1, true, false];
        yield 'Raj on the team-only SHOP-2' => [self::RAJ, self::SHOP_2, false, false];
        foreach (self::SHOP_2_WITH_RAJ_AS as $column => $ticketId) {
            yield 'Raj as the '.$column.' of a team-only Shop ticket, who may see but not edit it' => [self::RAJ, $ticketId, true, false];
        }
        yield 'the Beta Bakery admin on SHOP-1' => [self::BETA_ADMIN, self::SHOP_1, false, false];
    }

    #[Test]
    #[DataProvider('sharedRounds')]
    public function aSharedRoundFollowsWhoMaySeeAndEditItsTicket(string $userId, string $ticketId, bool $mayView, bool $mayEdit): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $sharedRound = self::round(self::ACME_FOODS, $ticketId, self::LENA, false, false);

        self::assertSame($mayView ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf($userId), $sharedRound, [RoundPermission::VIEW]));
        self::assertSame($mayEdit ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf($userId), $sharedRound, [RoundPermission::EDIT]));
        // A general note follows who may see the ticket, as a comment does, not who may edit it.
        self::assertSame($mayView ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf($userId), $sharedRound, [RoundPermission::ADD_GENERAL_NOTE]));
    }

    /**
     * Lena may see and edit the round she shared on SHOP-1, so only the "unshared" rule denies moving it.
     */
    #[Test]
    public function nobodyMayMoveASharedRoundToAReportDraftNotEvenItsOwner(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $lenasSharedRound = self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, false);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::LENA), $lenasSharedRound, [RoundPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), $lenasSharedRound, [RoundPermission::MOVE_TO_REPORT_DRAFT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::SARA), $lenasSharedRound, [RoundPermission::MOVE_TO_REPORT_DRAFT]));
    }

    /**
     * DRAFT_CREATE is decided by a voter that grants it to Mia and Omar on SHOP-1 alone, so each denial below is
     * the rule it names. The token's user, the round's owner, the ticket, whether COPY_TO_OWN_DRAFT is granted.
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function copiesToOwnDraft(): iterable
    {
        yield 'Mia, whose turn it is, on Lena\'s round' => [self::MIA, self::LENA, self::SHOP_1, true];
        yield 'Omar, the assignee, on Lena\'s round' => [self::OMAR, self::LENA, self::SHOP_1, true];
        yield 'Omar on his own round, though he may start a draft' => [self::OMAR, self::OMAR, self::SHOP_1, false];
        yield 'Lena, who may not start a draft, on Omar\'s round' => [self::LENA, self::OMAR, self::SHOP_1, false];
        yield 'Raj, a Manager who may see it, on Omar\'s round' => [self::RAJ, self::OMAR, self::SHOP_1, false];
        yield 'Mia on a ticket where she may not start a draft' => [self::MIA, self::LENA, self::SHOP_2, false];
        yield 'the Beta Bakery admin, who may not see SHOP-1' => [self::BETA_ADMIN, self::LENA, self::SHOP_1, false];
    }

    #[Test]
    #[DataProvider('copiesToOwnDraft')]
    public function copyingToOneOwnDraftNeedsTheRoundsTicketAndADraftStartAndIsNotTheOwners(string $userId, string $ownerId, string $ticketId, bool $isGranted): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterGrantingDraftCreate([self::MIA, self::OMAR], self::SHOP_1));
        $sharedRound = self::round(self::ACME_FOODS, $ticketId, $ownerId, false, false);

        self::assertSame($isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf($userId), $sharedRound, [RoundPermission::COPY_TO_OWN_DRAFT]));
    }

    /**
     * Mia may start a draft on SHOP-1, so only the draft's being unshared or deleted denies her.
     */
    #[Test]
    public function nobodyCopiesFromAnUnsharedOrDeletedDraftNotEvenItsOwner(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterGrantingDraftCreate([self::MIA, self::OMAR], self::SHOP_1));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::COPY_TO_OWN_DRAFT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), self::omarsDraft(), [RoundPermission::COPY_TO_OWN_DRAFT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, true), [RoundPermission::COPY_TO_OWN_DRAFT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::MIA), self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, false), [RoundPermission::COPY_TO_OWN_DRAFT]));
    }

    #[Test]
    public function aTicketAnotherVoterHidesDeniesSeeingAndChangingItsRound(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterDenying(TicketPermission::VIEW));
        $sharedRound = self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $sharedRound, [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $sharedRound, [RoundPermission::EDIT]));
    }

    #[Test]
    public function aTicketAnotherVoterLocksDeniesOnlyChangingItsRound(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS, self::voterDenying(TicketPermission::EDIT));
        $sharedRound = self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, false);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::OMAR), $sharedRound, [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $sharedRound, [RoundPermission::EDIT]));
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
    public function aSharedRoundWhoseTicketCannotBeReadIsDenied(string $ticketId): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $sharedRound = self::round(self::ACME_FOODS, $ticketId, self::LENA, false, false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), $sharedRound, [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), $sharedRound, [RoundPermission::EDIT]));
        self::assertSame([$ticketId, $ticketId], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * Omar is the owner, so only the organization denies him.
     */
    #[Test]
    public function aRoundOfAnotherOrganizationIsDeniedEvenToItsOwner(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $betaBakerysDraft = self::round(self::BETA_BAKERY, self::SHOP_1, self::OMAR, true, false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $betaBakerysDraft, [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $betaBakerysDraft, [RoundPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), $betaBakerysDraft, [RoundPermission::MOVE_TO_REPORT_DRAFT]));
    }

    #[Test]
    public function aTenantOtherThanTheActorsOrganizationIsDeniedEvenToTheOwner(): void
    {
        $voter = $this->voterUnder(self::BETA_BAKERY);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::MOVE_TO_REPORT_DRAFT]));
    }

    #[Test]
    public function noTenantIsDeniedEvenToTheOwner(): void
    {
        $voter = $this->voterUnder(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::omarsDraft(), [RoundPermission::MOVE_TO_REPORT_DRAFT]));
    }

    #[Test]
    public function nobodyLoggedInIsDenied(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $sharedRound = self::round(self::ACME_FOODS, self::SHOP_1, self::LENA, false, false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), self::omarsDraft(), [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), self::omarsDraft(), [RoundPermission::MOVE_TO_REPORT_DRAFT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $sharedRound, [RoundPermission::VIEW]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);
        $token = self::tokenOf(self::OMAR);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::omarsDraft(), [RoundPermission::DRAFT_CREATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::omarsDraft(), [TicketPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TicketId(self::SHOP_1), [RoundPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [RoundPermission::EDIT]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TicketId(self::SHOP_1), [RoundPermission::MOVE_TO_REPORT_DRAFT]));
    }

    private static function omarsDraft(): RoundAccessFacts
    {
        return self::round(self::ACME_FOODS, self::SHOP_1, self::OMAR, true, false);
    }

    private static function round(string $organizationId, string $ticketId, string $ownerId, bool $isUnsharedDraft, bool $isDeleted): RoundAccessFacts
    {
        return new RoundAccessFacts(new OrganizationId($organizationId), self::ROUND, new TicketId($ticketId), new UserId($ownerId), $isUnsharedDraft, $isDeleted);
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voterUnder(?string $tenantId, ?VoterInterface $otherVoter = null): RoundVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }
        $currentActor = self::currentActor();
        $voters = [new TicketVoter($currentActor, new RoleTicketVisibilities(self::noMemberships(), self::noTriager())), ...(null === $otherVoter ? [] : [$otherVoter])];

        return new RoundVoter($currentActor, $tenantContext, $this->ticketTurnQueries, new AccessDecisionManager($voters, new UnanimousStrategy(false)));
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
            self::SHOP_VIEWER => new ActorContext(new UserId(self::SHOP_VIEWER), $acmeFoods, new Roles(false, false, [], [], [$shop])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [$shop], [])),
            self::BETA_ADMIN => new ActorContext(new UserId(self::BETA_ADMIN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [], [$shop], [])),
        ]);
    }

    /**
     * Lena reported the ticket and Omar is its assignee, unless $rajsColumn puts Raj in that column or another.
     *
     * @param key-of<self::SHOP_2_WITH_RAJ_AS>|null $rajsColumn
     */
    private static function snapshotOf(string $ticketId, string $visibility, ?string $rajsColumn = null): TicketTurnSnapshot
    {
        $raj = new UserId(self::RAJ);

        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-0000000018c1',
            '0197a3c0-0000-7000-8000-0000000018c2',
            'progress',
            'doing',
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::OMAR), new UserId(self::ANOTHER_DEVELOPER)],
            [new UserId(self::MIA)],
            $visibility,
            'major',
            null,
            'reporter' === $rajsColumn ? $raj : new UserId(self::LENA),
            'assignee' === $rajsColumn ? $raj : new UserId(self::OMAR),
            'pending assignee' === $rajsColumn ? $raj : null,
            'reviewer' === $rajsColumn ? $raj : null,
            'tester' === $rajsColumn ? $raj : null,
            'waiting-on person' === $rajsColumn ? $raj : null,
        );
    }

    /**
     * Stands in for DraftRoundCreationVoter: grants DRAFT_CREATE on $ticketId to $userIds and denies it otherwise.
     *
     * @param list<string> $userIds
     */
    private static function voterGrantingDraftCreate(array $userIds, string $ticketId): VoterInterface
    {
        return new class($userIds, $ticketId) extends Voter {
            /**
             * @param list<string> $userIds
             */
            public function __construct(private readonly array $userIds, private readonly string $ticketId)
            {
            }

            protected function supports(string $attribute, mixed $subject): bool
            {
                return RoundPermission::DRAFT_CREATE === $attribute && $subject instanceof TicketId;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return $subject instanceof TicketId && $subject->toString() === $this->ticketId && \in_array($token->getUserIdentifier(), $this->userIds, true);
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
