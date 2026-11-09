<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Application\AcceptInvitation;
use App\Organization\Application\AddMember;
use App\Organization\Application\InvitationNoLongerValid;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Job;
use App\Organization\Domain\Organization;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamRole;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\Application\FixedUserQueries;
use App\Tests\Unit\Organization\Application\RecordingInvitedPersonRegistration;
use App\Tests\Unit\Organization\Application\RecordingOrganizationEvents;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AcceptInvitation on the real tables as pinround_app: the accepted_at of the locked row stops a second use.
 */
final class AcceptInvitationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000aa1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000000ac1';
    private const string INVITATION = '0197a3c0-0000-7000-8000-000000000ab1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000a52';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000a53';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000a56';
    private const string OMARS_EMAIL = 'omar@acme-foods.example';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            new DoctrineOrganizations($this->entityManager())->add(new Organization(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'accept-'.self::ACME_FOODS, self::sentAt()));
            new DoctrineTeams($this->entityManager())->add(new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, self::sentAt()));
            new DoctrineInvitations($this->entityManager())->add(Invitation::issue(
                new InvitationId(self::INVITATION),
                new OrganizationId(self::ACME_FOODS),
                self::OMARS_EMAIL,
                new OrgRoles(OrgRole::Manager),
                new TeamId(self::SHOP_TEAM),
                TeamRole::Member,
                new UserId(self::SARA),
                self::sentAt(),
            ));
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function aSecondAcceptOfTheSameInvitationIsRefusedAndThePersonJoinsOnce(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->acceptInvitation()->acceptAs(self::link(), new UserId(self::OMAR), self::now()));

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->acceptInvitation()->acceptAs(self::link(), new UserId(self::OMAR), self::now()->modify('+1 minute')));
            self::fail('The invitation was accepted twice.');
        } catch (InvitationNoLongerValid) {
        }
        self::getContainer()->get('doctrine')->resetManager();

        $counts = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            $this->connection()->fetchOne('SELECT count(*) FROM org_membership WHERE organization_id = ? AND user_id = ?', [self::ACME_FOODS, self::OMAR]),
            $this->connection()->fetchOne('SELECT count(*) FROM org_team_member WHERE organization_id = ? AND user_id = ?', [self::ACME_FOODS, self::OMAR]),
            $this->connection()->fetchOne('SELECT accepted_at IS NOT NULL FROM org_invitation WHERE organization_id = ? AND id = ?', [self::ACME_FOODS, self::INVITATION]),
        ]);
        self::assertSame([1, 1, true], $counts);
    }

    private function acceptInvitation(): AcceptInvitation
    {
        $memberships = new DoctrineMemberships($this->entityManager());
        $events = new RecordingOrganizationEvents();

        return new AcceptInvitation(
            new DoctrineInvitations($this->entityManager()),
            new FixedUserQueries([self::OMARS_EMAIL => self::OMAR]),
            new RecordingInvitedPersonRegistration(),
            new AddMember($memberships, $events),
            new SetTeamRole(new DoctrineTeams($this->entityManager()), $memberships, $events),
        );
    }

    private static function link(): OpenInvitationLink
    {
        return new OpenInvitationLink(self::INVITATION, new OrganizationId(self::ACME_FOODS), self::OMARS_EMAIL, 'Acme Foods', new UserId(self::OMAR));
    }

    private static function sentAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-14 10:00:00 UTC');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
