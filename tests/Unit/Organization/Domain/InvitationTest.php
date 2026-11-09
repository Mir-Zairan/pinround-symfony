<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\TeamHasOneLead;
use App\Organization\Domain\TeamRole;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvitationTest extends TestCase
{
    private const string INVITATION = '0197a3c0-0000-7000-8000-000000000901';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000009a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000009d1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000951';

    #[Test]
    public function itExpiresSeventyTwoHoursAfterItWasIssued(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        self::assertEquals(new \DateTimeImmutable('2027-03-18 10:30:00 UTC'), $invitation->expiresAt());
    }

    #[Test]
    public function itIsOpenUntilJustBeforeItsExpiry(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        self::assertTrue($invitation->isOpenAt(new \DateTimeImmutable('2027-03-15 10:30:00 UTC')));
        self::assertTrue($invitation->isOpenAt(new \DateTimeImmutable('2027-03-18 10:29:59 UTC')));
        self::assertFalse($invitation->isOpenAt(new \DateTimeImmutable('2027-03-18 10:30:00 UTC')));
        self::assertFalse($invitation->isOpenAt(new \DateTimeImmutable('2027-03-19 08:00:00 UTC')));
    }

    #[Test]
    public function anAcceptedInvitationIsNoLongerOpen(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        $invitation->accept(new \DateTimeImmutable('2027-03-16 09:00:00 UTC'));

        self::assertEquals(new \DateTimeImmutable('2027-03-16 09:00:00 UTC'), $invitation->acceptedAt());
        self::assertFalse($invitation->isOpenAt(new \DateTimeImmutable('2027-03-16 09:00:01 UTC')));
    }

    #[Test]
    public function anExpiredInvitationCannotBeAccepted(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        $this->expectException(\LogicException::class);
        $invitation->accept(new \DateTimeImmutable('2027-03-18 10:30:00 UTC'));
    }

    #[Test]
    public function anAcceptedInvitationCannotBeAcceptedAgain(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));
        $invitation->accept(new \DateTimeImmutable('2027-03-16 09:00:00 UTC'));

        try {
            $invitation->accept(new \DateTimeImmutable('2027-03-16 10:00:00 UTC'));
            self::fail('An accepted invitation was accepted again.');
        } catch (\LogicException) {
            self::assertEquals(new \DateTimeImmutable('2027-03-16 09:00:00 UTC'), $invitation->acceptedAt());
        }
    }

    #[Test]
    public function theEmailIsTrimmedAndKeptInLowerCase(): void
    {
        $invitation = Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), '  Omar@Acme-Foods.Example ', new OrgRoles(), null, null, new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        self::assertSame('omar@acme-foods.example', $invitation->email());
    }

    #[Test]
    public function aBlankEmailIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), '   ', new OrgRoles(), null, null, new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));
    }

    #[Test]
    public function itKeepsTheTeamTheTeamRoleAndTheOrgRoles(): void
    {
        $invitation = Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(OrgRole::Manager), new TeamId(self::SHOP_TEAM), TeamRole::Viewer, new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        self::assertSame(self::SHOP_TEAM, $invitation->teamId()?->toString());
        self::assertSame(TeamRole::Viewer, $invitation->teamRole());
        self::assertSame(['manager'], $invitation->orgRoles()->values());
        self::assertSame(self::SARA, $invitation->invitedBy()->toString());
    }

    #[Test]
    public function aTeamWithoutATeamRoleIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), new TeamId(self::SHOP_TEAM), null, new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));
    }

    #[Test]
    public function aTeamRoleWithoutATeamIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), null, TeamRole::Member, new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));
    }

    #[Test]
    public function anInvitationAsTeamLeadIsRefused(): void
    {
        $this->expectException(TeamHasOneLead::class);
        Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), new TeamId(self::SHOP_TEAM), TeamRole::Lead, new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));
    }

    #[Test]
    public function itHasNoLinkTokenUntilAHashIsAttached(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));
        self::assertFalse($invitation->hasLinkToken());

        $invitation->attachLinkTokenHash(hash('sha256', 'a link token', true));

        self::assertTrue($invitation->hasLinkToken());
    }

    #[Test]
    public function aHashThatIsNotThirtyTwoBytesIsRefused(): void
    {
        $invitation = $this->issue(new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        try {
            $invitation->attachLinkTokenHash(hash('sha256', 'a link token'));
            self::fail('A hex hash was attached.');
        } catch (\InvalidArgumentException) {
            self::assertFalse($invitation->hasLinkToken());
        }
    }

    private function issue(\DateTimeImmutable $createdAt): Invitation
    {
        return Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), 'omar@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), $createdAt);
    }
}
