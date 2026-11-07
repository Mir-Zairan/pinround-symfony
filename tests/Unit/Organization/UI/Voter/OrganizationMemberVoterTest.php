<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\MembershipQueries;
use App\Organization\Application\OrganizationPermission;
use App\Organization\UI\Voter\OrganizationMemberVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class OrganizationMemberVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    #[Test]
    public function aMemberMayEnterTheirOrganizationButNoOther(): void
    {
        $voter = self::voter(self::LENA, [self::ACME_FOODS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new OrganizationId(self::BETA_BAKERY), [OrganizationPermission::ENTER]));
    }

    #[Test]
    public function aLoggedInUserMayOpenThePickerEvenInNoOrganization(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter(self::LENA, [])->vote(new NullToken(), null, [OrganizationPermission::CHOOSE]));
    }

    #[Test]
    public function nobodyLoggedInIsRefusedBoth(): void
    {
        $voter = self::voter(null, [self::ACME_FOODS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [OrganizationPermission::CHOOSE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ENTER]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnSubjectsOfTheWrongKind(): void
    {
        $voter = self::voter(self::LENA, [self::ACME_FOODS]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ACCOUNT_EDIT_OWN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), self::ACME_FOODS, [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::CHOOSE]));
    }

    /**
     * @param list<string> $organizationIds
     */
    private static function voter(?string $userId, array $organizationIds): OrganizationMemberVoter
    {
        $currentUser = new class($userId) implements CurrentUser {
            public function __construct(private readonly ?string $userId)
            {
            }

            public function id(): ?UserId
            {
                return null === $this->userId ? null : new UserId($this->userId);
            }
        };
        $membershipQueries = new class($organizationIds) implements MembershipQueries {
            /**
             * @param list<string> $organizationIds
             */
            public function __construct(private readonly array $organizationIds)
            {
            }

            public function organizationsOf(UserId $userId): array
            {
                return array_map(static fn (string $organizationId): OrganizationId => new OrganizationId($organizationId), $this->organizationIds);
            }
        };

        return new OrganizationMemberVoter($currentUser, $membershipQueries);
    }
}
