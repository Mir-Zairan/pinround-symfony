<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * resolve_invitation on real org_invitation rows, called as pinround_app before any tenant is known, as accepting an
 * invite does (data-model.md › Lookups before the tenant is known). The database clock decides expiry, so the open
 * invitations are dated far ahead and the expired one far back.
 */
final class ResolveInvitationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000008a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000008b1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000851';
    private const string OPEN_AT_ACME = '0197a3c0-0000-7000-8000-0000000008c1';
    private const string OPEN_AT_BETA = '0197a3c0-0000-7000-8000-0000000008c2';
    private const string ACCEPTED = '0197a3c0-0000-7000-8000-0000000008c3';
    private const string EXPIRED = '0197a3c0-0000-7000-8000-0000000008c4';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $future = new \DateTimeImmutable('2099-01-01 10:00:00 UTC');
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($future): void {
            $this->addInvitation(self::OPEN_AT_ACME, self::ACME_FOODS, 'lena@acme-foods.example', $future);
            $accepted = $this->addInvitation(self::ACCEPTED, self::ACME_FOODS, 'omar@acme-foods.example', $future);
            $accepted->accept($future->modify('+1 hour'));
            $this->invitations()->add($accepted);
            $this->addInvitation(self::EXPIRED, self::ACME_FOODS, 'mia@acme-foods.example', new \DateTimeImmutable('2020-01-01 10:00:00 UTC'));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->addInvitation(self::OPEN_AT_BETA, self::BETA_BAKERY, 'raj@beta-bakery.example', $future));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function anOpenInvitationIsFoundByItsTokenHashInEveryOrganization(): void
    {
        self::assertSame([['invitation_id' => self::OPEN_AT_ACME, 'organization_id' => self::ACME_FOODS]], $this->resolve(self::OPEN_AT_ACME));
        self::assertSame([['invitation_id' => self::OPEN_AT_BETA, 'organization_id' => self::BETA_BAKERY]], $this->resolve(self::OPEN_AT_BETA));
    }

    #[Test]
    public function anUnknownTokenHashGetsNoRow(): void
    {
        self::assertSame([], $this->resolve('no invitation has this token'));
    }

    #[Test]
    public function anAcceptedInvitationGetsNoRow(): void
    {
        self::assertSame([], $this->resolve(self::ACCEPTED));
    }

    #[Test]
    public function anExpiredInvitationGetsNoRow(): void
    {
        self::assertSame([], $this->resolve(self::EXPIRED));
    }

    #[Test]
    public function theAppRoleCannotReadTheInvitationsWithoutATenant(): void
    {
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM org_invitation'));
    }

    /**
     * Each invitation's link token is its own id, so the test can name the token of each.
     */
    private function addInvitation(string $id, string $organizationId, string $email, \DateTimeImmutable $createdAt): Invitation
    {
        $invitation = Invitation::issue(new InvitationId($id), new OrganizationId($organizationId), $email, new OrgRoles(OrgRole::Manager), null, null, new UserId(self::SARA), $createdAt);
        $invitation->attachLinkTokenHash(hash('sha256', $id, true));
        $this->invitations()->add($invitation);

        return $invitation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolve(string $token): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT invitation_id, organization_id FROM resolve_invitation(sha256(?::bytea))',
            [$token],
        );
    }

    private function invitations(): DoctrineInvitations
    {
        return new DoctrineInvitations(self::getContainer()->get('doctrine.orm.default_entity_manager'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
