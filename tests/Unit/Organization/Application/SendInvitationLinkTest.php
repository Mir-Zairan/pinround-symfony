<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\EmailInvitationLink;
use App\Organization\Application\SendInvitationLink;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Organizations;
use App\Organization\Domain\OrgRoles;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SendInvitationLinkTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000006f1';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000006f2';
    private const string MIAS_INVITATION = '0197a3c0-0000-7000-8000-0000000006f3';
    private const string ISSUED_AT = '2027-03-15 10:00:00 UTC';
    private const string SOON_AFTER = '2027-03-15 10:00:05 UTC';

    #[Test]
    public function anOpenInvitationIsSavedWithALinkTokenAndThenEmailed(): void
    {
        $invitation = self::miasInvitation();
        $invitations = new InMemoryInvitations($invitation);
        $mailer = new RecordingInvitationMailer($invitations);

        $this->sendInvitationLink($invitations, $mailer)(self::request(), new \DateTimeImmutable(self::SOON_AFTER));

        self::assertSame(
            [['email' => 'mia@acme-foods.example', 'organizationName' => 'Acme Foods', 'hadLinkToken' => true, 'savedBefore' => ['lockById', 'add']]],
            $mailer->sentLinks,
            'The invitation is locked before its link token is checked, and the hash is saved before the email is sent, so a failed send rolls it back.',
        );
    }

    #[Test]
    public function anInvitationReplacedByANewOneGetsNoEmail(): void
    {
        $invitations = new InMemoryInvitations();
        $mailer = new RecordingInvitationMailer($invitations);

        $this->sendInvitationLink($invitations, $mailer)(self::request(), new \DateTimeImmutable(self::SOON_AFTER));

        self::assertSame([], $mailer->sentLinks);
        self::assertSame(['lockById'], $invitations->calls);
    }

    #[Test]
    public function anAcceptedInvitationGetsNoEmail(): void
    {
        $invitation = self::miasInvitation();
        $invitation->accept(new \DateTimeImmutable('2027-03-15 11:00:00 UTC'));
        $invitations = new InMemoryInvitations($invitation);
        $mailer = new RecordingInvitationMailer($invitations);

        $this->sendInvitationLink($invitations, $mailer)(self::request(), new \DateTimeImmutable('2027-03-15 12:00:00 UTC'));

        self::assertSame([], $mailer->sentLinks);
        self::assertFalse($invitation->hasLinkToken());
    }

    #[Test]
    public function anInvitationAtItsExpiryGetsNoEmail(): void
    {
        $invitation = self::miasInvitation();
        $invitations = new InMemoryInvitations($invitation);
        $mailer = new RecordingInvitationMailer($invitations);

        $this->sendInvitationLink($invitations, $mailer)(self::request(), $invitation->expiresAt());

        self::assertSame([], $mailer->sentLinks);
        self::assertFalse($invitation->hasLinkToken());
    }

    #[Test]
    public function anInvitationJustBeforeItsExpiryIsEmailed(): void
    {
        $invitation = self::miasInvitation();
        $invitations = new InMemoryInvitations($invitation);
        $mailer = new RecordingInvitationMailer($invitations);

        $this->sendInvitationLink($invitations, $mailer)(self::request(), $invitation->expiresAt()->modify('-1 second'));

        self::assertCount(1, $mailer->sentLinks);
    }

    #[Test]
    public function anInvitationWithALinkTokenAlreadyGetsNoSecondEmail(): void
    {
        $invitation = self::miasInvitation();
        $invitation->attachLinkTokenHash(hash('sha256', 'the link emailed first', true));
        $invitations = new InMemoryInvitations($invitation);
        $mailer = new RecordingInvitationMailer($invitations);

        $this->sendInvitationLink($invitations, $mailer)(self::request(), new \DateTimeImmutable(self::SOON_AFTER));

        self::assertSame([], $mailer->sentLinks);
        self::assertSame(['lockById'], $invitations->calls, 'The link token is checked on the locked invitation.');
    }

    #[Test]
    public function theLinkIsMadeInsideTheOnceOnlyEffectUnderItsOwnHandlerName(): void
    {
        $invitation = self::miasInvitation();
        $invitations = new InMemoryInvitations($invitation);
        $mailer = new RecordingInvitationMailer($invitations);
        $skippingOnceOnlyHandling = new class implements OnceOnlyHandling {
            /** @var list<string> */
            public array $handlerNames = [];

            public function runOnce(string $handlerName, \Closure $effect): bool
            {
                $this->handlerNames[] = $handlerName;

                return false;
            }
        };

        new SendInvitationLink($skippingOnceOnlyHandling, $invitations, self::organizations(self::acmeFoods()), $mailer)(self::request(), new \DateTimeImmutable(self::SOON_AFTER));

        self::assertSame(['organization.send_invitation_link'], $skippingOnceOnlyHandling->handlerNames);
        self::assertSame([], $mailer->sentLinks);
        self::assertFalse($invitation->hasLinkToken());
    }

    #[Test]
    public function anInvitationWhoseOrganizationIsNotFoundFailsInsteadOfSendingAnEmail(): void
    {
        $invitations = new InMemoryInvitations(self::miasInvitation());
        $mailer = new RecordingInvitationMailer($invitations);

        try {
            new SendInvitationLink(self::runningOnceOnlyHandling(), $invitations, self::organizations(null), $mailer)(self::request(), new \DateTimeImmutable(self::SOON_AFTER));
            self::fail('An invitation was emailed without its organization.');
        } catch (\LogicException $refused) {
            self::assertSame('The organization of the invitation is not found.', $refused->getMessage());
        }
        self::assertSame([], $mailer->sentLinks);
    }

    private function sendInvitationLink(InMemoryInvitations $invitations, RecordingInvitationMailer $mailer): SendInvitationLink
    {
        return new SendInvitationLink(self::runningOnceOnlyHandling(), $invitations, self::organizations(self::acmeFoods()), $mailer);
    }

    private static function miasInvitation(): Invitation
    {
        return Invitation::issue(new InvitationId(self::MIAS_INVITATION), new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example', new OrgRoles(), null, null, new UserId(self::SARA), new \DateTimeImmutable(self::ISSUED_AT));
    }

    private static function request(): EmailInvitationLink
    {
        return new EmailInvitationLink(self::ACME_FOODS, self::MIAS_INVITATION);
    }

    private static function acmeFoods(): Organization
    {
        return new Organization(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods', new \DateTimeImmutable(self::ISSUED_AT));
    }

    private static function organizations(?Organization $organization): Organizations
    {
        return new readonly class($organization) implements Organizations {
            public function __construct(private ?Organization $organization)
            {
            }

            public function nextIdentity(): OrganizationId
            {
                throw new \LogicException('Not used by SendInvitationLink.');
            }

            public function byId(OrganizationId $id): ?Organization
            {
                return null !== $this->organization && $this->organization->id()->equals($id) ? $this->organization : null;
            }

            public function add(Organization $organization): void
            {
                throw new \LogicException('Not used by SendInvitationLink.');
            }
        };
    }

    private static function runningOnceOnlyHandling(): OnceOnlyHandling
    {
        return new class implements OnceOnlyHandling {
            public function runOnce(string $handlerName, \Closure $effect): bool
            {
                $effect();

                return true;
            }
        };
    }
}
