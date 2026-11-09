<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\MemberInvited;
use App\Organization\Application\AlreadyInTheOrganization;
use App\Organization\Application\InvalidEmailAddress;
use App\Organization\Application\InvitationSentMeanwhile;
use App\Organization\Application\InviteMember;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationAlreadyOpen;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamHasOneLead;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\UnknownTeam;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InviteMemberTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000006b1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000006b2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000006c1';
    private const string CHECKOUT_TEAM = '0197a3c0-0000-7000-8000-0000000006c2';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000006d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000006d2';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000006d3';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000006d4';
    private const string OLD_INVITATION = '0197a3c0-0000-7000-8000-0000000006e1';
    private const string ACCEPTED_INVITATION = '0197a3c0-0000-7000-8000-0000000006e2';
    private const string BETA_INVITATION = '0197a3c0-0000-7000-8000-0000000006e3';

    private InMemoryInvitations $invitations;
    private InMemoryMemberships $memberships;
    private RecordingOrganizationEvents $events;

    protected function setUp(): void
    {
        $this->invitations = new InMemoryInvitations();
        $this->memberships = new InMemoryMemberships(
            new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new OrgRoles(OrgRole::Admin), self::now()),
            new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(), self::now()),
            new Membership(new OrganizationId(self::BETA_BAKERY), new UserId(self::BEN), new OrgRoles(OrgRole::Admin), self::now()),
        );
        $this->events = new RecordingOrganizationEvents();
    }

    #[Test]
    public function anEmailIsInvitedWithNoRolesAndNoTeam(): void
    {
        $invitationId = $this->inviteMember()(new OrganizationId(self::ACME_FOODS), '  Mia@Acme-Foods.example ', new OrgRoles(), null, null, new UserId(self::SARA), self::now());

        self::assertSame(InMemoryInvitations::NEXT_ID, $invitationId->toString());
        $stored = $this->invitations->all();
        self::assertCount(1, $stored);
        self::assertSame(
            [InMemoryInvitations::NEXT_ID, self::ACME_FOODS, 'mia@acme-foods.example', [], null, null, self::SARA, false],
            [$stored[0]->id()->toString(), $stored[0]->organizationId()->toString(), $stored[0]->email(), $stored[0]->orgRoles()->values(), $stored[0]->teamId(), $stored[0]->teamRole(), $stored[0]->invitedBy()->toString(), $stored[0]->hasLinkToken()],
        );
        self::assertEquals(self::now(), $stored[0]->createdAt());
    }

    #[Test]
    public function anEmailIsInvitedWithOrgRolesAndATeamRole(): void
    {
        $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(OrgRole::Manager), new TeamId(self::SHOP_TEAM), TeamRole::Viewer, new UserId(self::SARA), self::now());

        $stored = $this->invitations->all();
        self::assertCount(1, $stored);
        self::assertSame(
            [['manager'], self::SHOP_TEAM, TeamRole::Viewer],
            [$stored[0]->orgRoles()->values(), $stored[0]->teamId()?->toString(), $stored[0]->teamRole()],
        );
    }

    #[Test]
    public function theInvitationExpiresSeventyTwoHoursAfterNow(): void
    {
        $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now());

        self::assertEquals(new \DateTimeImmutable('2027-03-18 10:00:00 UTC'), $this->invitations->all()[0]->expiresAt());
    }

    #[Test]
    public function aMemberOfTheOrganizationIsRefusedAndNothingIsSaved(): void
    {
        $userQueries = new FixedUserQueries(['lena@acme-foods.example' => self::LENA]);

        try {
            $this->inviteMember($userQueries)(new OrganizationId(self::ACME_FOODS), ' LENA@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now());
            self::fail('A member was invited.');
        } catch (AlreadyInTheOrganization) {
        }

        self::assertSame([[], [], []], [$this->invitations->all(), $this->invitations->calls, $this->events->published]);
    }

    #[Test]
    public function aUserWhoIsNoMemberOfThisOrganizationIsInvited(): void
    {
        $userQueries = new FixedUserQueries(['ben@beta-bakery.example' => self::BEN, 'omar@acme-foods.example' => self::OMAR]);

        $this->inviteMember($userQueries)(new OrganizationId(self::ACME_FOODS), 'ben@beta-bakery.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now());
        $this->inviteMember($userQueries)(new OrganizationId(self::ACME_FOODS), 'omar@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now());

        self::assertSame(
            ['ben@beta-bakery.example', 'omar@acme-foods.example'],
            array_map(static fn (Invitation $invitation): string => $invitation->email(), $this->invitations->all()),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnEmailAddress(): iterable
    {
        yield 'blank' => [''];
        yield 'only spaces' => ['   '];
        yield 'no at sign' => ['mia.acme-foods.example'];
        yield 'no domain' => ['mia@'];
        yield 'two at signs' => ['mia@@acme-foods.example'];
    }

    #[Test]
    #[DataProvider('notAnEmailAddress')]
    public function aValueThatIsNoEmailAddressIsRefusedAndNothingIsSaved(string $email): void
    {
        try {
            $this->inviteMember()(new OrganizationId(self::ACME_FOODS), $email, new OrgRoles(), null, null, new UserId(self::SARA), self::now());
            self::fail('Something that is no email address was invited.');
        } catch (InvalidEmailAddress) {
        }

        self::assertSame([[], [], []], [$this->invitations->all(), $this->invitations->calls, $this->events->published]);
    }

    #[Test]
    public function anEmailAddressWithUnicodeIsInvitedAsAnAccountWouldTakeIt(): void
    {
        $this->inviteMember()(new OrganizationId(self::ACME_FOODS), ' Mía@Acme-Foods.example ', new OrgRoles(), null, null, new UserId(self::SARA), self::now());

        self::assertSame(['mía@acme-foods.example'], array_map(static fn (Invitation $invitation): string => $invitation->email(), $this->invitations->all()));
    }

    #[Test]
    public function aLeadTeamRoleIsRefusedAndNothingIsSaved(): void
    {
        try {
            $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), new TeamId(self::SHOP_TEAM), TeamRole::Lead, new UserId(self::SARA), self::now());
            self::fail('An invitation made a lead.');
        } catch (TeamHasOneLead) {
        }

        self::assertSame([[], [], []], [$this->invitations->all(), $this->invitations->calls, $this->events->published]);
    }

    #[Test]
    public function anUnknownTeamIsRefusedAndNothingIsSaved(): void
    {
        $this->assertTeamIsRefused('0197a3c0-0000-7000-8000-0000000006cf');
    }

    #[Test]
    public function aTeamOfAnotherOrganizationIsRefusedAndNothingIsSaved(): void
    {
        $this->assertTeamIsRefused(self::CHECKOUT_TEAM);
    }

    #[Test]
    public function aNewInvitationReplacesTheNotAcceptedOneOfTheSameEmailOnly(): void
    {
        $old = Invitation::issue(new InvitationId(self::OLD_INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(OrgRole::Admin), null, null, new UserId(self::SARA), self::now()->modify('-100 hours'));
        $accepted = Invitation::issue(new InvitationId(self::ACCEPTED_INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now()->modify('-200 hours'));
        $accepted->accept(self::now()->modify('-199 hours'));
        $atBeta = Invitation::issue(new InvitationId(self::BETA_INVITATION), new OrganizationId(self::BETA_BAKERY), 'mia@acme-foods.example', new OrgRoles(), null, null, new UserId(self::BEN), self::now()->modify('-1 hour'));
        $this->invitations = new InMemoryInvitations($old, $accepted, $atBeta);

        $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'MIA@acme-foods.example', new OrgRoles(OrgRole::Manager), null, null, new UserId(self::SARA), self::now());

        self::assertSame(['removeNotAcceptedFor', 'add'], $this->invitations->calls);
        self::assertEqualsCanonicalizing(
            [self::ACCEPTED_INVITATION, self::BETA_INVITATION, InMemoryInvitations::NEXT_ID],
            array_map(static fn (Invitation $invitation): string => $invitation->id()->toString(), $this->invitations->all()),
        );
        self::assertSame(['manager'], $this->invitations->byId(new InvitationId(InMemoryInvitations::NEXT_ID))?->orgRoles()->values());
    }

    #[Test]
    public function anInvitationOfTheSameEmailSavedAtTheSameTimeIsRefusedAndNothingIsRecorded(): void
    {
        $otherRequests = Invitation::issue(new InvitationId(self::OLD_INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now());
        $this->invitations->letAnotherRequestAddFirst($otherRequests);

        try {
            $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'Mia@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), self::now());
            self::fail('A second open invitation of Mia was saved.');
        } catch (InvitationSentMeanwhile $refused) {
            self::assertInstanceOf(InvitationAlreadyOpen::class, $refused->getPrevious());
        }

        self::assertSame([self::OLD_INVITATION], array_map(static fn (Invitation $invitation): string => $invitation->id()->toString(), $this->invitations->all()));
        self::assertSame([], $this->events->published);
    }

    #[Test]
    public function memberInvitedIsRecordedWithoutTheEmail(): void
    {
        $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), new TeamId(self::SHOP_TEAM), TeamRole::Member, new UserId(self::SARA), self::now());

        self::assertEquals([new MemberInvited(self::ACME_FOODS, InMemoryInvitations::NEXT_ID, self::SARA, self::now())], $this->events->published);
        self::assertStringNotContainsString('mia', serialize($this->events->published[0]));
    }

    private function assertTeamIsRefused(string $teamId): void
    {
        try {
            $this->inviteMember()(new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), new TeamId($teamId), TeamRole::Member, new UserId(self::SARA), self::now());
            self::fail('An invitation named a team the organization does not have.');
        } catch (UnknownTeam) {
        }

        self::assertSame([[], [], []], [$this->invitations->all(), $this->invitations->calls, $this->events->published]);
    }

    /**
     * Acme Foods has the team Shop; Beta Bakery has Checkout, which the fake hands out to any organization, as only
     * row-level security would hide it.
     */
    private function inviteMember(?FixedUserQueries $userQueries = null): InviteMember
    {
        $teams = new InMemoryTeams(
            new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, self::now()),
            new Team(new TeamId(self::CHECKOUT_TEAM), new OrganizationId(self::BETA_BAKERY), 'Checkout', new UserId(self::BEN), Job::Developer, self::now()),
        );

        return new InviteMember($this->invitations, $this->memberships, $teams, $userQueries ?? new FixedUserQueries(), new RecordingInvitedPersonRegistration(), $this->events);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }
}
