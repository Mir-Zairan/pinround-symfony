<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\UI\Voter\OrganizationMemberVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class OrganizationMemberVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    #[Test]
    public function aMemberMayEnterTheirOrganizationButNoOther(): void
    {
        $voter = self::voter([self::LENA => [self::ACME_FOODS]]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::LENA), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::LENA), new OrganizationId(self::BETA_BAKERY), [OrganizationPermission::ENTER]));
    }

    #[Test]
    public function itDecidesForTheUserOfTheTokenItIsGiven(): void
    {
        $voter = self::voter([self::LENA => [self::ACME_FOODS], self::OMAR => [self::BETA_BAKERY]]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::OMAR), new OrganizationId(self::BETA_BAKERY), [OrganizationPermission::ENTER]));
    }

    #[Test]
    public function aLoggedInUserMayOpenThePickerEvenInNoOrganization(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter([self::LENA => []])->vote(self::tokenOf(self::LENA), null, [OrganizationPermission::CHOOSE]));
    }

    #[Test]
    public function aTokenWithoutALoggedInUserIsRefusedBoth(): void
    {
        $voter = self::voter([self::LENA => [self::ACME_FOODS]]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [OrganizationPermission::CHOOSE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ENTER]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnSubjectsOfTheWrongKind(): void
    {
        $voter = self::voter([self::LENA => [self::ACME_FOODS]]);
        $token = self::tokenOf(self::LENA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['ACCOUNT_EDIT_OWN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::ACME_FOODS, [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [OrganizationPermission::CHOOSE]));
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    /**
     * @param array<string, list<string>> $organizationIdsByUser
     */
    private static function voter(array $organizationIdsByUser): OrganizationMemberVoter
    {
        // Reads the user from the token only; id() fails, so a voter asking for the request's user fails the test.
        $currentUser = new class implements CurrentUser {
            public function id(): ?UserId
            {
                throw new \LogicException('The voter must decide on the token it is given.');
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                return $tokenUser instanceof InMemoryUser ? new UserId($tokenUser->getUserIdentifier()) : null;
            }
        };
        $membershipQueries = new readonly class($organizationIdsByUser) implements MembershipQueries {
            /**
             * @param array<string, list<string>> $organizationIdsByUser
             */
            public function __construct(private array $organizationIdsByUser)
            {
            }

            public function organizationsOf(UserId $userId): array
            {
                return array_map(
                    static fn (string $organizationId): OrganizationId => new OrganizationId($organizationId),
                    $this->organizationIdsByUser[$userId->toString()] ?? [],
                );
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                throw new \LogicException('The voter decides on memberships only.');
            }

            public function membersAmong(OrganizationId $organizationId, array $userIds): array
            {
                throw new \LogicException('The voter asks for one user\'s organizations.');
            }
        };

        return new OrganizationMemberVoter($currentUser, $membershipQueries);
    }
}
