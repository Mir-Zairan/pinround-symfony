<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\UI\Voter\InvitationVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class InvitationVoterTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000000a3';
    /** A token user Identity does not know, such as one another firewall made. */
    public const string UNKNOWN_TO_IDENTITY = 'unknown-to-identity';

    #[Test]
    public function nobodyLoggedInMayAcceptAnInvitationOfAnEmailWithoutAnAccount(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter()->vote(new NullToken(), self::invitationOf(null), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    #[Test]
    public function nobodyLoggedInIsDeniedAnInvitationOfAnEmailWithAnAccount(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(new NullToken(), self::invitationOf(self::OMAR), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    #[Test]
    public function theAccountWithTheInvitedEmailMayAcceptIt(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter()->vote(self::tokenOf(self::OMAR), self::invitationOf(self::OMAR), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    #[Test]
    public function anotherLoggedInPersonIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(self::tokenOf(self::MIA), self::invitationOf(self::OMAR), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    #[Test]
    public function aLoggedInPersonIsDeniedAnInvitationOfAnEmailWithoutAnAccount(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(self::tokenOf(self::MIA), self::invitationOf(null), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    #[Test]
    public function aTokenUserIdentityDoesNotKnowIsNotTakenForNobodyLoggedIn(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(self::tokenOf(self::UNKNOWN_TO_IDENTITY), self::invitationOf(null), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndSubjects(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote(new NullToken(), self::invitationOf(null), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ACCEPT_INVITATION]));
    }

    private static function invitationOf(?string $existingAccountId): OpenInvitationLink
    {
        return new OpenInvitationLink(
            '0197a3c0-0000-7000-8000-0000000000b1',
            new OrganizationId(self::ACME_FOODS),
            'omar@acme-foods.example',
            'Acme Foods',
            null === $existingAccountId ? null : new UserId($existingAccountId),
        );
    }

    private static function tokenOf(string $userIdentifier): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userIdentifier, null), 'main');
    }

    private static function voter(): InvitationVoter
    {
        // Reads the user from the token only; id() fails, so a voter asking for the request's user fails the test.
        return new InvitationVoter(new class implements CurrentUser {
            public function id(): ?UserId
            {
                throw new \LogicException('The voter must decide on the token it is given.');
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                if (!$tokenUser instanceof InMemoryUser || InvitationVoterTest::UNKNOWN_TO_IDENTITY === $tokenUser->getUserIdentifier()) {
                    return null;
                }

                return new UserId($tokenUser->getUserIdentifier());
            }
        });
    }
}
