<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\Organization\Api\Event\MemberJoined;
use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Application\AcceptInvitation;
use App\Organization\Application\AddMember;
use App\Organization\Application\AlreadyJoined;
use App\Organization\Application\InvitationForAnotherPerson;
use App\Organization\Application\InvitationNoLongerValid;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\InvitationLinkToken;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamMemberView;
use App\Organization\Domain\TeamRole;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptInvitationTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000006a0';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000006c1';
    private const string INVITATION = '0197a3c0-0000-7000-8000-0000000006b1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000652';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000653';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000654';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000656';
    private const string OMARS_EMAIL = 'omar@acme-foods.example';
    private const string LONG_ENOUGH_PASSWORD = 'correct horse battery';

    private InMemoryInvitations $invitations;
    private InMemoryMemberships $memberships;
    private InMemoryTeams $teams;
    private RecordingOrganizationEvents $events;
    private RecordingInvitedPersonRegistration $registration;
    private InvitationLinkToken $linkToken;

    protected function setUp(): void
    {
        $this->linkToken = InvitationLinkToken::generate();
        $this->memberships = new InMemoryMemberships(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(), self::sentAt()));
        $this->teams = new InMemoryTeams(new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, self::sentAt()));
        $this->events = new RecordingOrganizationEvents();
        $this->registration = new RecordingInvitedPersonRegistration();
    }

    #[Test]
    public function theAccountWithTheInvitedEmailJoinsWithTheInvitedOrgRolesAndUsesTheInvitationUp(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles(OrgRole::Admin, OrgRole::Manager));

        $this->acceptInvitation()->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::OMAR), self::now());

        self::assertEquals(self::now(), $invitation->acceptedAt());
        self::assertSame(['lockById', 'save'], $this->invitations->calls);
        self::assertSame(['admin', 'manager'], $this->memberships->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR))?->orgRoles()->values());
        self::assertEquals([new MemberJoined(self::ACME_FOODS, self::OMAR, ['admin', 'manager'], self::OMAR, self::now())], $this->events->published);
        self::assertSame([], $this->teams->calls);
        self::assertSame([], $this->registration->registered);
    }

    #[Test]
    public function aTeamInvitationAlsoPutsThePersonInTheTeamWithItsTeamRoleAndJobOther(): void
    {
        $this->invitationOfOmar(new OrgRoles(), new TeamId(self::SHOP_TEAM), TeamRole::Viewer);

        $this->acceptInvitation()->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::OMAR), self::now());

        self::assertEquals(
            new TeamMemberView(new UserId(self::OMAR), TeamRole::Viewer, Job::Other, self::now()),
            $this->teams->byId(new TeamId(self::SHOP_TEAM))?->memberOf(new UserId(self::OMAR)),
        );
        self::assertEquals(
            [
                new MemberJoined(self::ACME_FOODS, self::OMAR, [], self::OMAR, self::now()),
                new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::OMAR, null, 'viewer', self::OMAR, self::now()),
            ],
            $this->events->published,
        );
    }

    #[Test]
    public function aNewPersonGetsAnAccountWithTheInvitedEmailAndJoins(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles(OrgRole::Manager));

        $userId = $this->acceptInvitation()->acceptAsNewPerson(self::link(), $this->linkToken->reveal(), 'Omar', self::LONG_ENOUGH_PASSWORD, self::now());

        self::assertSame(RecordingInvitedPersonRegistration::NEW_USER, $userId->toString());
        self::assertSame([[self::OMARS_EMAIL, 'Omar']], $this->registration->registered);
        self::assertEquals(self::now(), $invitation->acceptedAt());
        self::assertSame(['manager'], $this->memberships->of(new OrganizationId(self::ACME_FOODS), $userId)?->orgRoles()->values());
        self::assertEquals([new MemberJoined(self::ACME_FOODS, RecordingInvitedPersonRegistration::NEW_USER, ['manager'], RecordingInvitedPersonRegistration::NEW_USER, self::now())], $this->events->published);
    }

    #[Test]
    public function aRefusedRegistrationLeavesTheInvitationOpenAndNobodyJoins(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());

        try {
            $this->acceptInvitation()->acceptAsNewPerson(self::link(), $this->linkToken->reveal(), 'Omar', 'too short', self::now());
            self::fail('A short password was taken.');
        } catch (RegistrationRefused) {
        }

        $this->assertNothingChanged($invitation);
    }

    #[Test]
    public function anAlreadyUsedInvitationIsRefused(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());
        $invitation->accept(self::sentAt()->modify('+1 hour'));

        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::OMAR), self::now()));
        self::assertSame([], $this->events->published);
    }

    #[Test]
    public function anInvitationIsRefusedFromTheMomentItExpires(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());

        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAsNewPerson(self::link(), $this->linkToken->reveal(), 'Omar', self::LONG_ENOUGH_PASSWORD, $invitation->expiresAt()));
        $this->assertNothingChanged($invitation);
    }

    #[Test]
    public function aReplacedInvitationIsRefused(): void
    {
        $this->invitations = new InMemoryInvitations();

        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAsNewPerson(self::link(), $this->linkToken->reveal(), 'Omar', self::LONG_ENOUGH_PASSWORD, self::now()));
        self::assertSame([], $this->registration->registered);
    }

    #[Test]
    public function aLoggedInPersonWhoIsNotTheInvitedAccountIsRefused(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());

        $this->expectRefusal(InvitationForAnotherPerson::class, fn () => $this->acceptInvitation()->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::MIA), self::now()));
        $this->assertNothingChanged($invitation);
    }

    #[Test]
    public function aLoggedInPersonIsRefusedWhenNobodyHasTheInvitedEmail(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());

        $this->expectRefusal(InvitationForAnotherPerson::class, fn () => $this->acceptInvitation(new FixedUserQueries())->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::MIA), self::now()));
        $this->assertNothingChanged($invitation);
    }

    #[Test]
    public function aWrongTokenForARealInvitationIsRefusedAndNobodyJoins(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles(OrgRole::Admin));
        $anotherToken = InvitationLinkToken::generate()->reveal();

        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAs(self::link(), $anotherToken, new UserId(self::OMAR), self::now()));
        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAsNewPerson(self::link(), $anotherToken, 'Omar', self::LONG_ENOUGH_PASSWORD, self::now()));

        self::assertNull($invitation->acceptedAt());
        self::assertSame(['lockById', 'lockById'], $this->invitations->calls);
        self::assertCount(1, $this->memberships->all());
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->registration->registered);
    }

    #[Test]
    public function aTokenThatIsNotALinkTokenIsRefused(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());

        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAs(self::link(), self::INVITATION, new UserId(self::OMAR), self::now()));
        $this->assertNothingChanged($invitation);
    }

    #[Test]
    public function anInvitationWhoseLinkWasNotEmailedYetIsRefused(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles(), isEmailed: false);

        $this->expectRefusal(InvitationNoLongerValid::class, fn () => $this->acceptInvitation()->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::OMAR), self::now()));
        $this->assertNothingChanged($invitation);
    }

    #[Test]
    public function aPersonWhoJoinedSinceTheInvitationWasSentIsToldSoAndTheInvitationStaysOpen(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles(OrgRole::Admin), new TeamId(self::SHOP_TEAM), TeamRole::Member);
        $this->memberships->add(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), new OrgRoles(), self::sentAt()));

        $this->expectRefusal(AlreadyJoined::class, fn () => $this->acceptInvitation()->acceptAs(self::link(), $this->linkToken->reveal(), new UserId(self::OMAR), self::now()));

        self::assertNull($invitation->acceptedAt());
        self::assertSame(['lockById'], $this->invitations->calls);
        self::assertSame([], $this->memberships->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR))?->orgRoles()->values());
        self::assertSame([], $this->teams->calls);
        self::assertSame([], $this->events->published);
    }

    #[Test]
    public function aNewPersonWhoseEmailHasAnAccountIsRefusedAndNobodyJoins(): void
    {
        $invitation = $this->invitationOfOmar(new OrgRoles());
        $this->registration = new RecordingInvitedPersonRegistration([self::OMARS_EMAIL]);

        try {
            $this->acceptInvitation()->acceptAsNewPerson(self::link(), $this->linkToken->reveal(), 'Omar', self::LONG_ENOUGH_PASSWORD, self::now());
            self::fail('A second account with the invited email was made.');
        } catch (RegistrationRefused $refused) {
            self::assertSame([RegistrationRefusal::EmailTaken], $refused->refusals);
        }

        $this->assertNothingChanged($invitation);
        self::assertSame([], $this->registration->registered);
    }

    private function acceptInvitation(?FixedUserQueries $userQueries = null): AcceptInvitation
    {
        return new AcceptInvitation(
            $this->invitations,
            $userQueries ?? new FixedUserQueries([self::OMARS_EMAIL => self::OMAR]),
            $this->registration,
            new AddMember($this->memberships, $this->events),
            new SetTeamRole($this->teams, $this->memberships, $this->events),
        );
    }

    /**
     * @param bool $isEmailed whether the email worker has attached the hash of $this->linkToken
     */
    private function invitationOfOmar(OrgRoles $orgRoles, ?TeamId $teamId = null, ?TeamRole $teamRole = null, bool $isEmailed = true): Invitation
    {
        $invitation = Invitation::issue(new InvitationId(self::INVITATION), new OrganizationId(self::ACME_FOODS), self::OMARS_EMAIL, $orgRoles, $teamId, $teamRole, new UserId(self::SARA), self::sentAt());
        if ($isEmailed) {
            $invitation->attachLinkTokenHash($this->linkToken->hash());
        }
        $this->invitations = new InMemoryInvitations($invitation);

        return $invitation;
    }

    /**
     * @param class-string<\Throwable> $refusal
     */
    private function expectRefusal(string $refusal, \Closure $accept): void
    {
        try {
            $accept();
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown);

            return;
        }
        self::fail('The invitation was accepted.');
    }

    private function assertNothingChanged(Invitation $invitation): void
    {
        self::assertNull($invitation->acceptedAt());
        self::assertSame(['lockById'], $this->invitations->calls);
        self::assertCount(1, $this->memberships->all());
        self::assertSame([], $this->events->published);
    }

    private static function link(): OpenInvitationLink
    {
        return new OpenInvitationLink(self::INVITATION, new OrganizationId(self::ACME_FOODS), self::OMARS_EMAIL, 'Acme Foods', null);
    }

    private static function sentAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-14 10:00:00 UTC');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }
}
