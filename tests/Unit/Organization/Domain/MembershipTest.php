<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Membership;
use App\Organization\Domain\OrganizationNeedsAnAdmin;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000051';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000052';

    #[Test]
    public function itKeepsItsOrganizationUserRolesAndJoinDate(): void
    {
        $joinedAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $membership = new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new OrgRoles(OrgRole::Admin), $joinedAt);

        self::assertSame(self::ACME_FOODS, $membership->organizationId()->toString());
        self::assertSame(self::SARA, $membership->userId()->toString());
        self::assertTrue($membership->orgRoles()->equals(new OrgRoles(OrgRole::Admin)));
        self::assertSame($joinedAt, $membership->joinedAt());
    }

    #[Test]
    public function theLastAdminCannotLoseAdmin(): void
    {
        $sara = $this->membershipOf(self::ACME_FOODS, self::SARA, OrgRole::Admin);

        try {
            $sara->changeRoles(new OrgRoles(OrgRole::Manager), [$sara]);
            self::fail('The last admin lost the Admin role.');
        } catch (OrganizationNeedsAnAdmin $refusal) {
            self::assertSame('An organization needs at least one admin.', $refusal->getMessage());
        }

        self::assertTrue($sara->orgRoles()->isAdmin());
    }

    #[Test]
    public function theLastAdminCannotLoseAdminWhenNoAdminListIsGiven(): void
    {
        $sara = $this->membershipOf(self::ACME_FOODS, self::SARA, OrgRole::Admin);

        $this->expectException(OrganizationNeedsAnAdmin::class);
        $sara->changeRoles(new OrgRoles(), []);
    }

    #[Test]
    public function anAdminOfAnotherOrganizationDoesNotCountAsASecondAdmin(): void
    {
        $sara = $this->membershipOf(self::ACME_FOODS, self::SARA, OrgRole::Admin);
        $lenaAtBetaBakery = $this->membershipOf(self::BETA_BAKERY, self::LENA, OrgRole::Admin);

        $this->expectException(OrganizationNeedsAnAdmin::class);
        $sara->changeRoles(new OrgRoles(), [$sara, $lenaAtBetaBakery]);
    }

    #[Test]
    public function aListedMemberWhoIsNoLongerAdminDoesNotCountAsASecondAdmin(): void
    {
        $sara = $this->membershipOf(self::ACME_FOODS, self::SARA, OrgRole::Admin);
        $lenaAsManager = $this->membershipOf(self::ACME_FOODS, self::LENA, OrgRole::Manager);

        $this->expectException(OrganizationNeedsAnAdmin::class);
        $sara->changeRoles(new OrgRoles(), [$sara, $lenaAsManager]);
    }

    #[Test]
    public function anAdminCanLoseAdminWhileASecondAdminStays(): void
    {
        $sara = $this->membershipOf(self::ACME_FOODS, self::SARA, OrgRole::Admin);
        $lena = $this->membershipOf(self::ACME_FOODS, self::LENA, OrgRole::Admin);

        $sara->changeRoles(new OrgRoles(OrgRole::Manager), [$sara, $lena]);

        self::assertSame(['manager'], $sara->orgRoles()->values());
    }

    #[Test]
    public function theLastAdminMayGainOtherRolesAndKeepAdmin(): void
    {
        $sara = $this->membershipOf(self::ACME_FOODS, self::SARA, OrgRole::Admin);

        $sara->changeRoles(new OrgRoles(OrgRole::Admin, OrgRole::Manager), [$sara]);

        self::assertSame(['admin', 'manager'], $sara->orgRoles()->values());
    }

    #[Test]
    public function aMemberWhoIsNoAdminMayLoseEveryRoleWithoutAnyAdminListed(): void
    {
        $lena = $this->membershipOf(self::ACME_FOODS, self::LENA, OrgRole::Manager);

        $lena->changeRoles(new OrgRoles(), []);

        self::assertSame([], $lena->orgRoles()->values());
    }

    private function membershipOf(string $organizationId, string $userId, OrgRole ...$roles): Membership
    {
        return new Membership(new OrganizationId($organizationId), new UserId($userId), new OrgRoles(...$roles), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }
}
