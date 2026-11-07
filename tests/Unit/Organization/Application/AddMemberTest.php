<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\MemberJoined;
use App\Organization\Application\AddMember;
use App\Organization\Domain\AlreadyAnOrganizationMember;
use App\Organization\Domain\Membership;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddMemberTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000000555';

    #[Test]
    public function theUserBecomesAMemberWithTheRolesAndTheJoiningIsRecorded(): void
    {
        $memberships = new InMemoryMemberships();
        $events = new RecordingOrganizationEvents();

        new AddMember($memberships, $events)(new OrganizationId(self::ACME_FOODS), new UserId(self::RAJ), new OrgRoles(OrgRole::Manager), null, self::now());

        self::assertEquals([new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::RAJ), new OrgRoles(OrgRole::Manager), self::now())], $memberships->all());
        self::assertEquals([new MemberJoined(self::ACME_FOODS, self::RAJ, ['manager'], null, self::now())], $events->published);
    }

    #[Test]
    public function aMemberAlreadyIsRefused(): void
    {
        $existing = new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::RAJ), new OrgRoles(), self::now());
        $memberships = new InMemoryMemberships($existing);
        $events = new RecordingOrganizationEvents();

        try {
            new AddMember($memberships, $events)(new OrganizationId(self::ACME_FOODS), new UserId(self::RAJ), new OrgRoles(OrgRole::Manager), null, self::now());
            self::fail('The user was added twice.');
        } catch (AlreadyAnOrganizationMember) {
        }

        self::assertSame([$existing], $memberships->all());
        self::assertSame([], $events->published);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }
}
