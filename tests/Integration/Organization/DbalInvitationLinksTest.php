<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Organization;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Infrastructure\Doctrine\DbalInvitationLinks;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\Application\FixedUserQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalInvitationLinks on real rows as pinround_app: resolve_invitation without a tenant, then the read under the
 * invitation's organization. The database clock decides expiry, so open invitations are dated far ahead.
 */
final class DbalInvitationLinksTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000009a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000009b1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000951';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000952';
    private const string OPEN_AT_ACME = '0197a3c0-0000-7000-8000-0000000009c1';
    private const string OPEN_AT_BETA = '0197a3c0-0000-7000-8000-0000000009c2';
    private const string ACCEPTED = '0197a3c0-0000-7000-8000-0000000009c3';
    private const string EXPIRED = '0197a3c0-0000-7000-8000-0000000009c4';
    private const string REPLACED = '0197a3c0-0000-7000-8000-0000000009c5';
    private const string REPLACEMENT = '0197a3c0-0000-7000-8000-0000000009c6';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $future = new \DateTimeImmutable('2099-01-01 10:00:00 UTC');
        foreach ([self::ACME_FOODS => 'Acme Foods', self::BETA_BAKERY => 'Beta Bakery'] as $organizationId => $name) {
            $this->transactions()->committedAs($organizationId, fn () => $this->organizations()->add(
                new Organization(new OrganizationId($organizationId), $name, 'links-'.$organizationId, $future),
            ));
        }
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($future): void {
            $this->addInvitation(self::OPEN_AT_ACME, self::ACME_FOODS, 'omar@acme-foods.example', $future);
            $accepted = $this->addInvitation(self::ACCEPTED, self::ACME_FOODS, 'raj@acme-foods.example', $future);
            $accepted->accept($future->modify('+1 hour'));
            $this->invitations()->add($accepted);
            $this->addInvitation(self::EXPIRED, self::ACME_FOODS, 'mia@acme-foods.example', new \DateTimeImmutable('2020-01-01 10:00:00 UTC'));
            $this->addInvitation(self::REPLACED, self::ACME_FOODS, 'lena@acme-foods.example', $future);
        });
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($future): void {
            $this->invitations()->removeNotAcceptedFor(new OrganizationId(self::ACME_FOODS), 'lena@acme-foods.example');
            $this->addInvitation(self::REPLACEMENT, self::ACME_FOODS, 'lena@acme-foods.example', $future);
        });
        // The same email at Beta Bakery: Acme's link must still show Acme's invitation only.
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->addInvitation(self::OPEN_AT_BETA, self::BETA_BAKERY, 'omar@acme-foods.example', $future));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function anOpenLinkGivesItsInvitationAndOrganizationAndNoAccountWhenNobodyHasTheEmail(): void
    {
        $link = $this->links()->find(self::tokenOf(self::OPEN_AT_ACME));

        self::assertNotNull($link);
        self::assertSame(self::OPEN_AT_ACME, $link->invitationId);
        self::assertSame(self::ACME_FOODS, $link->organizationId->toString());
        self::assertSame('omar@acme-foods.example', $link->invitedEmail);
        self::assertSame('Acme Foods', $link->organizationName);
        self::assertNull($link->existingAccountId);
        self::assertNull($this->tenantContext()->get());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function anOpenLinkNamesTheAccountThatHasTheInvitedEmail(): void
    {
        $link = $this->links(['omar@acme-foods.example' => self::OMAR])->find(self::tokenOf(self::OPEN_AT_ACME));

        self::assertSame(self::OMAR, $link?->existingAccountId?->toString());
    }

    #[Test]
    public function anotherOrganizationsLinkIsReadUnderItsOwnOrganizationAndTheTenantIsPutBack(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $link = $this->links()->find(self::tokenOf(self::OPEN_AT_BETA));

        self::assertSame([self::OPEN_AT_BETA, self::BETA_BAKERY, 'Beta Bakery'], [$link?->invitationId, $link?->organizationId->toString(), $link?->organizationName]);
        self::assertSame(self::ACME_FOODS, $this->tenantContext()->get()?->toString());
    }

    #[Test]
    public function aLinkThatIsNotOpenGivesNothing(): void
    {
        foreach (['accepted' => self::ACCEPTED, 'expired' => self::EXPIRED, 'replaced' => self::REPLACED, 'unknown' => '0197a3c0-0000-7000-8000-0000000009ff'] as $case => $invitationId) {
            self::assertNull($this->links()->find(self::tokenOf($invitationId)), $case);
        }
        self::assertSame(self::REPLACEMENT, $this->links()->find(self::tokenOf(self::REPLACEMENT))?->invitationId);
    }

    #[Test]
    public function aLinkThatCannotCarryATokenGivesNothing(): void
    {
        self::assertNull($this->links()->find('not-a-token'));
    }

    #[Test]
    public function anOpenTransactionIsRefused(): void
    {
        $this->connection()->beginTransaction();
        try {
            $this->expectException(TenantSwitchRefused::class);
            $this->links()->find(self::tokenOf(self::OPEN_AT_ACME));
        } finally {
            $this->connection()->rollBack();
        }
    }

    /**
     * Each invitation's link token is derived from its id, so the test can name the token of each.
     */
    private static function tokenOf(string $invitationId): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $invitationId, true)), '+/', '-_'), '=');
    }

    private function addInvitation(string $id, string $organizationId, string $email, \DateTimeImmutable $createdAt): Invitation
    {
        $invitation = Invitation::issue(new InvitationId($id), new OrganizationId($organizationId), $email, new OrgRoles(OrgRole::Manager), null, null, new UserId(self::SARA), $createdAt);
        $invitation->attachLinkTokenHash(hash('sha256', self::tokenOf($id), true));
        $this->invitations()->add($invitation);

        return $invitation;
    }

    /**
     * @param array<string, string> $userIdsByEmail
     */
    private function links(array $userIdsByEmail = []): DbalInvitationLinks
    {
        return new DbalInvitationLinks($this->connection(), $this->entityManager(), $this->tenantContext(), new FixedUserQueries($userIdsByEmail));
    }

    private function invitations(): DoctrineInvitations
    {
        return new DoctrineInvitations($this->entityManager());
    }

    private function organizations(): DoctrineOrganizations
    {
        return new DoctrineOrganizations($this->entityManager());
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
