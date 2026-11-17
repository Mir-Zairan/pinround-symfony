<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Lena leads Shop, Omar is a member of it, Sara is an Admin and a member of Shop; all of Acme Foods. Omar is the
 * assignee of the open ticket and of the one in Done; the unassigned ticket is open.
 */
final class DraftRoundCreationVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000019a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000019a2';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000019a3';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000190a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000190b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000019d1';
    private const string OPEN_TICKET = '0197a3c0-0000-7000-8000-0000000019e1';
    private const string DONE_TICKET = '0197a3c0-0000-7000-8000-0000000019e2';
    /** A ticket whose facts the queries cannot read, as when its step is not the organization's. */
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000019e3';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000019e4';
    private const string UNASSIGNED_TICKET = '0197a3c0-0000-7000-8000-0000000019e5';

    private InMemoryTicketTurnQueries $ticketTurnQueries;

    protected function setUp(): void
    {
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries(
            [
                self::snapshotOf(self::OPEN_TICKET, 'doing', self::OMAR),
                self::snapshotOf(self::DONE_TICKET, 'done', self::OMAR),
                self::snapshotOf(self::UNASSIGNED_TICKET, 'todo', null),
            ],
            new TicketId(self::BROKEN_TICKET),
        );
    }

    #[Test]
    public function theAssigneeOfAnOpenTicketMayStartADraft(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(self::OMAR, self::OPEN_TICKET));
        self::assertSame([self::OPEN_TICKET], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * The token's user, the ticket, and the tickets the voter asked for.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function refusedDrafts(): iterable
    {
        yield 'Lena, the lead of Shop, who is not the assignee' => [self::LENA, self::OPEN_TICKET, [self::OPEN_TICKET]];
        yield 'Sara, an Admin and member of Shop, who is not the assignee' => [self::SARA, self::OPEN_TICKET, [self::OPEN_TICKET]];
        yield 'Omar, the assignee of a ticket in Done' => [self::OMAR, self::DONE_TICKET, [self::DONE_TICKET]];
        yield 'Omar on an open ticket nobody is assigned' => [self::OMAR, self::UNASSIGNED_TICKET, [self::UNASSIGNED_TICKET]];
        yield 'Omar on a ticket the organization has not' => [self::OMAR, self::NO_TICKET, [self::NO_TICKET]];
        yield 'Omar on a ticket whose facts cannot be read' => [self::OMAR, self::BROKEN_TICKET, [self::BROKEN_TICKET]];
    }

    /**
     * @param list<string> $expectedAskedTicketIds
     */
    #[Test]
    #[DataProvider('refusedDrafts')]
    public function itRefuses(string $userId, string $ticketId, array $expectedAskedTicketIds): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($userId, $ticketId));
        self::assertSame($expectedAskedTicketIds, $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function nobodyLoggedInIsRefusedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new TicketId(self::OPEN_TICKET), [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * The queries read the tenant's tickets, so under another tenant the voter refuses before it reads one.
     */
    #[Test]
    public function aTenantOtherThanTheActorsOrganizationIsRefusedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(self::BETA_BAKERY);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), new TicketId(self::OPEN_TICKET), [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    #[Test]
    public function noTenantIsRefusedBeforeTheTicketIsRead(): void
    {
        $voter = $this->voterUnder(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), new TicketId(self::OPEN_TICKET), [RoundPermission::DRAFT_CREATE]));
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
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

        return new DraftRoundCreationVoter(new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [$shop], [])),
        ]), $tenantContext, $this->ticketTurnQueries);
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
            [],
            TicketVisibilityFacts::FOR_EVERYONE,
            'major',
            null,
            new UserId(self::LENA),
            null === $assigneeId ? null : new UserId($assigneeId),
        );
    }
}
