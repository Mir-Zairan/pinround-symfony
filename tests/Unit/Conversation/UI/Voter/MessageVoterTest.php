<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\UI\Voter;

use App\Conversation\Api\ConversationPermission;
use App\Conversation\UI\Voter\MessageOnTicket;
use App\Conversation\UI\Voter\MessageVoter;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
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
 * RESOLVE on a team-only ticket of Acme Foods's Shop team, which Lena leads, also in the cases the voter matrix
 * (VoterMatrixTest) cannot reach: a ticket with no team, nobody logged in, no tenant, another organization.
 */
final class MessageVoterTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000260a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000260b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000026d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000026a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000026a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000026a3';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000026a4';

    /**
     * @return iterable<string, array{string, string, string|null, list<string>, bool, bool}> voter, author, team lead,
     *                                                                                        teams the voter is in,
     *                                                                                        is admin, granted
     */
    public static function cases(): iterable
    {
        yield 'the author, in the team' => [self::OMAR, self::OMAR, self::LENA, [self::SHOP], false, true];
        yield 'the lead, on someone else\'s comment' => [self::LENA, self::OMAR, self::LENA, [self::SHOP], false, true];
        yield 'a member, on someone else\'s comment' => [self::MIA, self::OMAR, self::LENA, [self::SHOP], false, false];
        yield 'an admin, on someone else\'s comment' => [self::SARA, self::OMAR, self::LENA, [], true, false];
        yield 'an admin, on their own comment' => [self::SARA, self::SARA, self::LENA, [], true, true];
        yield 'the author, who no longer sees the ticket' => [self::OMAR, self::OMAR, self::LENA, [], false, false];
        yield 'the lead, who no longer sees the ticket' => [self::LENA, self::OMAR, self::LENA, [], false, false];
        yield 'the author, on a ticket with no team' => [self::OMAR, self::OMAR, null, [self::SHOP], false, true];
        yield 'someone else, on a ticket with no team' => [self::LENA, self::OMAR, null, [self::SHOP], false, false];
    }

    /**
     * @param list<string> $teamIds
     */
    #[Test]
    #[DataProvider('cases')]
    public function resolveIsGrantedToTheAuthorOrTheTeamsLeadWhoSeeTheTicket(string $voterId, string $authorId, ?string $teamLeadId, array $teamIds, bool $isAdmin, bool $isGranted): void
    {
        $ticket = self::ticketOf(self::ACME_FOODS, null === $teamLeadId ? null : self::SHOP);

        self::assertSame(
            $isGranted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $this->messageVoter(self::ACME_FOODS, $teamIds, $isAdmin)->vote(self::tokenOf($voterId), self::messageOn($ticket, $authorId, $teamLeadId), [ConversationPermission::RESOLVE]),
        );
    }

    #[Test]
    public function nobodyLoggedInMayNotResolve(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->messageVoter(self::ACME_FOODS, [self::SHOP], false)->vote(new NullToken(), self::messageOn(self::ticketOf(self::ACME_FOODS, self::SHOP), self::OMAR, self::LENA), [ConversationPermission::RESOLVE]),
        );
    }

    #[Test]
    public function theAuthorWithNoTenantMayNotResolve(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->messageVoter(null, [self::SHOP], false)->vote(self::tokenOf(self::OMAR), self::messageOn(self::ticketOf(self::ACME_FOODS, self::SHOP), self::OMAR, self::LENA), [ConversationPermission::RESOLVE]),
        );
    }

    #[Test]
    public function theAuthorAndTheLeadMayNotResolveACommentOfAnotherOrganization(): void
    {
        $messageOfBetaBakery = self::messageOn(self::ticketOf(self::BETA_BAKERY, self::SHOP), self::OMAR, self::LENA);
        $voter = $this->messageVoter(self::ACME_FOODS, [self::SHOP], true);

        self::assertSame(
            [VoterInterface::ACCESS_DENIED, VoterInterface::ACCESS_DENIED],
            [$voter->vote(self::tokenOf(self::OMAR), $messageOfBetaBakery, [ConversationPermission::RESOLVE]), $voter->vote(self::tokenOf(self::LENA), $messageOfBetaBakery, [ConversationPermission::RESOLVE])],
        );
    }

    #[Test]
    public function itAbstainsOnATicketSubjectAndOnTheTicketLevelAttributes(): void
    {
        $voter = $this->messageVoter(self::ACME_FOODS, [self::SHOP], false);
        $ticket = self::ticketOf(self::ACME_FOODS, self::SHOP);

        self::assertSame(
            [VoterInterface::ACCESS_ABSTAIN, VoterInterface::ACCESS_ABSTAIN],
            [$voter->vote(self::tokenOf(self::OMAR), $ticket, [ConversationPermission::RESOLVE]), $voter->vote(self::tokenOf(self::OMAR), self::messageOn($ticket, self::OMAR, self::LENA), [ConversationPermission::COMMENT])],
        );
    }

    /**
     * A team-only ticket of the team, or a ticket for everyone with no team, so both are visible to the team's people.
     */
    private static function ticketOf(string $organizationId, ?string $teamId): TicketVisibilityFacts
    {
        if (null === $teamId) {
            return new TicketVisibilityFacts(new OrganizationId($organizationId), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::MIA));
        }

        return new TicketVisibilityFacts(new OrganizationId($organizationId), TicketVisibilityFacts::TEAM_ONLY, new TeamId($teamId), new UserId(self::MIA));
    }

    private static function messageOn(TicketVisibilityFacts $ticket, string $authorId, ?string $teamLeadId): MessageOnTicket
    {
        return new MessageOnTicket($ticket, new UserId($authorId), null === $teamLeadId ? null : new UserId($teamLeadId));
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    /**
     * @param list<string> $teamIds the teams every voter is in
     */
    private function messageVoter(?string $tenantId, array $teamIds, bool $isAdmin): MessageVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }

        return new MessageVoter(self::currentUserFromToken(), $tenantContext, self::visibilitiesOf($teamIds, $isAdmin));
    }

    /**
     * Reads the user id from the InMemoryUser's identifier, as the app's CurrentUser reads it from its user; no user is
     * no id.
     */
    private static function currentUserFromToken(): CurrentUser
    {
        return new readonly class implements CurrentUser {
            public function id(): ?UserId
            {
                throw new \LogicException('The voter reads the token\'s user.');
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                return $tokenUser instanceof InMemoryUser ? new UserId($tokenUser->getUserIdentifier()) : null;
            }
        };
    }

    /**
     * @param list<string> $teamIds
     */
    private static function visibilitiesOf(array $teamIds, bool $isAdmin): TicketVisibilities
    {
        return new readonly class($teamIds, $isAdmin) implements TicketVisibilities {
            /**
             * @param list<string> $teamIds
             */
            public function __construct(private array $teamIds, private bool $isAdmin)
            {
            }

            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                return TicketVisibility::for($organizationId, $viewerId, array_map(static fn (string $teamId): TeamId => new TeamId($teamId), $this->teamIds), $this->isAdmin, null);
            }
        };
    }
}
