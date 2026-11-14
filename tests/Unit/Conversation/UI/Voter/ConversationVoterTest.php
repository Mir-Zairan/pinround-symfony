<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\UI\Voter;

use App\Conversation\Api\ConversationPermission;
use App\Conversation\UI\Voter\ConversationVoter;
use App\Identity\Api\CurrentUser;
use App\Notification\Api\PingPermission;
use App\Notification\UI\Voter\PingVoter;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The role rule of ASK with TicketPermission VIEW granted, also in the cases the voter matrix (VoterMatrixTest) cannot
 * reach: its Admin and Manager may not see its ticket with no team, and nobody without a role reports one. PingVoter
 * repeats the rule for SEND, so each case also runs through it and must decide the same.
 */
final class ConversationVoterTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000250a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000250b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000025d1';
    private const string SUPPORT = '0197a3c0-0000-7000-8000-0000000025d2';
    private const string REPORTER = '0197a3c0-0000-7000-8000-0000000025a1';
    private const string OTHER_USER = '0197a3c0-0000-7000-8000-0000000025a2';

    /**
     * @return iterable<string, array{Roles, ?string, bool, bool}> roles, ticket team, is reporter, granted
     */
    public static function roleCases(): iterable
    {
        $shop = new TeamId(self::SHOP);
        $support = new TeamId(self::SUPPORT);

        yield 'an Admin on a ticket with no team' => [new Roles(true, false, [], [], []), null, false, true];
        yield 'a Manager on a ticket with no team' => [new Roles(false, true, [], [], []), null, false, true];
        yield 'a Manager with no team role on a team ticket' => [new Roles(false, true, [], [], []), self::SHOP, false, true];
        yield 'a Manager who views the team' => [new Roles(false, true, [], [], [$shop]), self::SHOP, false, true];
        yield 'a Lead of the team' => [new Roles(false, false, [$shop], [], []), self::SHOP, false, true];
        yield 'a Member of the team' => [new Roles(false, false, [], [$shop], []), self::SHOP, false, true];
        yield 'a Lead of another team' => [new Roles(false, false, [$support], [], []), self::SHOP, false, false];
        yield 'a Lead on a ticket with no team' => [new Roles(false, false, [$shop], [], []), null, false, false];
        yield 'a Viewer of the team' => [new Roles(false, false, [], [], [$shop]), self::SHOP, false, false];
        yield 'a Viewer of the team who reported it' => [new Roles(false, false, [], [], [$shop]), self::SHOP, true, false];
        yield 'a Viewer of another team who reported it' => [new Roles(false, false, [], [], [$support]), self::SHOP, true, true];
        yield 'no role, not the reporter' => [Roles::none(), self::SHOP, false, false];
        yield 'no role, the reporter' => [Roles::none(), self::SHOP, true, true];
        yield 'no role on a ticket with no team, not the reporter' => [Roles::none(), null, false, false];
        yield 'no role on a ticket with no team, the reporter' => [Roles::none(), null, true, true];
    }

    #[Test]
    #[DataProvider('roleCases')]
    public function askFollowsTheRoleRule(Roles $roles, ?string $teamId, bool $isReporter, bool $isGranted): void
    {
        $actorId = $isReporter ? self::REPORTER : self::OTHER_USER;

        self::assertSame(
            $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $this->conversationVoter($actorId, $roles, true)->vote(self::tokenOf($actorId), self::ticket($teamId), [ConversationPermission::ASK]),
        );
    }

    #[Test]
    #[DataProvider('roleCases')]
    public function pingVoterDecidesSendAsAskIsDecided(Roles $roles, ?string $teamId, bool $isReporter, bool $isGranted): void
    {
        $actorId = $isReporter ? self::REPORTER : self::OTHER_USER;

        self::assertSame(
            $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $this->pingVoter($actorId, $roles, true)->vote(self::tokenOf($actorId), self::ticket($teamId), [PingPermission::SEND]),
        );
    }

    #[Test]
    public function anAdminWhoMayNotSeeTheTicketMayNotAskOrPing(): void
    {
        $admin = new Roles(true, false, [], [], []);
        $token = self::tokenOf(self::OTHER_USER);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->conversationVoter(self::OTHER_USER, $admin, false)->vote($token, self::ticket(self::SHOP), [ConversationPermission::ASK]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->pingVoter(self::OTHER_USER, $admin, false)->vote($token, self::ticket(self::SHOP), [PingPermission::SEND]));
    }

    #[Test]
    public function anAdminOfAnotherOrganizationOrNobodyMayNotAsk(): void
    {
        $admin = new Roles(true, false, [], [], []);
        $ticketOfBetaBakery = new TicketVisibilityFacts(new OrganizationId(self::BETA_BAKERY), TicketVisibilityFacts::TEAM_ONLY, null, new UserId(self::REPORTER));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->conversationVoter(self::OTHER_USER, $admin, true)->vote(self::tokenOf(self::OTHER_USER), $ticketOfBetaBakery, [ConversationPermission::ASK]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->conversationVoter(self::OTHER_USER, $admin, true)->vote(new NullToken(), self::ticket(null), [ConversationPermission::ASK]));
    }

    private static function ticket(?string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(
            new OrganizationId(self::ACME_FOODS),
            TicketVisibilityFacts::TEAM_ONLY,
            null === $teamId ? null : new TeamId($teamId),
            new UserId(self::REPORTER),
        );
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function conversationVoter(string $actorId, Roles $roles, bool $maySeeTicket): ConversationVoter
    {
        return new ConversationVoter(
            self::unusedCurrentUser(),
            new TenantContext(new RecordingTenantSwitchGuard()),
            self::unusedTicketVisibilities(),
            self::currentActor($actorId, $roles),
            self::ticketViewDecision($maySeeTicket),
        );
    }

    private function pingVoter(string $actorId, Roles $roles, bool $maySeeTicket): PingVoter
    {
        return new PingVoter(self::currentActor($actorId, $roles), self::ticketViewDecision($maySeeTicket));
    }

    private static function currentActor(string $actorId, Roles $roles): FixedCurrentActor
    {
        return new FixedCurrentActor([$actorId => new ActorContext(new UserId($actorId), new OrganizationId(self::ACME_FOODS), $roles)]);
    }

    /**
     * Decides only TicketPermission VIEW, the one attribute the voters ask about; any other ask is a test bug.
     */
    private static function ticketViewDecision(bool $maySeeTicket): AccessDecisionManagerInterface
    {
        return new readonly class($maySeeTicket) implements AccessDecisionManagerInterface {
            public function __construct(private bool $maySeeTicket)
            {
            }

            /**
             * @param array<mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, ?AccessDecision $accessDecision = null): bool
            {
                if ([TicketPermission::VIEW] !== $attributes || !$object instanceof TicketVisibilityFacts) {
                    throw new \LogicException('The voter asks only whether the ticket may be seen.');
                }

                return $this->maySeeTicket;
            }
        };
    }

    private static function unusedCurrentUser(): CurrentUser
    {
        return new readonly class implements CurrentUser {
            public function id(): ?UserId
            {
                throw new \LogicException('ASK reads the actor, not the current user.');
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                throw new \LogicException('ASK reads the actor, not the current user.');
            }
        };
    }

    private static function unusedTicketVisibilities(): TicketVisibilities
    {
        return new readonly class implements TicketVisibilities {
            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                throw new \LogicException('ASK asks TicketPermission VIEW, not the visibility predicate.');
            }
        };
    }
}
