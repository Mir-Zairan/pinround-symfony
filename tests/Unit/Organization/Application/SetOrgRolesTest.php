<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\OrgRolesChanged;
use App\Organization\Application\SetOrgRoles;
use App\Organization\Domain\Membership;
use App\Organization\Domain\NotAnOrganizationMember;
use App\Organization\Domain\OrganizationNeedsAnAdmin;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetOrgRolesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000556';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000552';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000000555';

    #[Test]
    public function removingTheLastAdminsAdminRoleIsRefusedAndRecordsNothing(): void
    {
        $sara = self::member(self::SARA, OrgRole::Admin);
        $memberships = new InMemoryMemberships($sara, self::member(self::RAJ, OrgRole::Manager));
        $events = new RecordingOrganizationEvents();

        try {
            new SetOrgRoles($memberships, $events)(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new OrgRoles(OrgRole::Manager), new UserId(self::SARA), self::now());
            self::fail('The last admin lost the Admin role.');
        } catch (OrganizationNeedsAnAdmin $refused) {
            self::assertSame('An organization needs at least one admin.', $refused->getMessage());
        }

        self::assertSame([OrgRole::Admin], $sara->orgRoles()->toList());
        self::assertNotContains('save', $memberships->calls);
        self::assertSame([], $events->published);
    }

    #[Test]
    public function anAdminLosesTheAdminRoleWhileAnotherIsLeftAndTheChangeIsRecorded(): void
    {
        $lena = self::member(self::LENA, OrgRole::Admin);
        $memberships = new InMemoryMemberships($lena, self::member(self::SARA, OrgRole::Admin));
        $events = new RecordingOrganizationEvents();

        new SetOrgRoles($memberships, $events)(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(OrgRole::Manager), new UserId(self::SARA), self::now());

        self::assertSame([OrgRole::Manager], $lena->orgRoles()->toList());
        self::assertSame(['lockAdminsOf', 'of', 'save'], $memberships->calls, 'The admins are locked before the membership is loaded.');
        self::assertEquals([new OrgRolesChanged(self::ACME_FOODS, self::LENA, ['admin'], ['manager'], self::SARA, self::now())], $events->published);
    }

    #[Test]
    public function theSameRolesAgainSaveAndRecordNothing(): void
    {
        $memberships = new InMemoryMemberships(self::member(self::RAJ, OrgRole::Manager));
        $events = new RecordingOrganizationEvents();

        new SetOrgRoles($memberships, $events)(new OrganizationId(self::ACME_FOODS), new UserId(self::RAJ), new OrgRoles(OrgRole::Manager), null, self::now());

        self::assertNotContains('save', $memberships->calls);
        self::assertSame([], $events->published);
    }

    #[Test]
    public function aUserWhoIsNoMemberIsRefused(): void
    {
        $events = new RecordingOrganizationEvents();

        $this->expectException(NotAnOrganizationMember::class);
        try {
            new SetOrgRoles(new InMemoryMemberships(), $events)(new OrganizationId(self::ACME_FOODS), new UserId(self::RAJ), new OrgRoles(OrgRole::Manager), null, self::now());
        } finally {
            self::assertSame([], $events->published);
        }
    }

    private static function member(string $userId, OrgRole ...$orgRoles): Membership
    {
        return new Membership(new OrganizationId(self::ACME_FOODS), new UserId($userId), new OrgRoles(...$orgRoles), new \DateTimeImmutable('2027-03-01 09:00:00 UTC'));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }
}
