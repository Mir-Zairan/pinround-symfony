<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationAlreadyOpen;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Job;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamRole;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineInvitations on the real org_invitation table as pinround_app, under row-level security.
 */
final class DoctrineInvitationsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000ba1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000bb1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000000bd1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000b51';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b52';
    // Ids rise in the order the fixture adds them, so idsOf() lists them in that order.
    private const string OMAR_EXPIRED = '0197a3c0-0000-7000-8000-000000000bc1';
    private const string OMAR_ACCEPTED = '0197a3c0-0000-7000-8000-000000000bc2';
    private const string MIA_AT_ACME = '0197a3c0-0000-7000-8000-000000000bc3';
    private const string OMAR_AT_BETA = '0197a3c0-0000-7000-8000-000000000bc4';
    private const string NEW_FOR_ACME = '0197a3c0-0000-7000-8000-000000000bc5';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function anInvitationIsSavedWithItsTokenHashAndReadBack(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        [$found, $storedHash, $storedRoles] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($createdAt): array {
            new DoctrineTeams($this->entityManager())->add(new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, $createdAt));
            $invitation = Invitation::issue(new InvitationId(self::NEW_FOR_ACME), new OrganizationId(self::ACME_FOODS), 'omar@acme-foods.example', new OrgRoles(OrgRole::Manager), new TeamId(self::SHOP_TEAM), TeamRole::Member, new UserId(self::SARA), $createdAt);
            $invitation->attachLinkTokenHash(hash('sha256', 'the link token', true));
            $this->invitations()->add($invitation);
            $this->entityManager()->clear();

            return [
                $this->invitations()->byId(new InvitationId(self::NEW_FOR_ACME)),
                $this->entityManager()->getConnection()->fetchOne("SELECT encode(token_hash, 'hex') FROM org_invitation WHERE id = ?", [self::NEW_FOR_ACME]),
                $this->entityManager()->getConnection()->fetchOne('SELECT org_roles::text FROM org_invitation WHERE id = ?', [self::NEW_FOR_ACME]),
            ];
        });

        self::assertInstanceOf(Invitation::class, $found);
        self::assertSame('omar@acme-foods.example', $found->email());
        self::assertSame(['manager'], $found->orgRoles()->values());
        self::assertSame(self::SHOP_TEAM, $found->teamId()?->toString());
        self::assertSame(TeamRole::Member, $found->teamRole());
        self::assertSame(self::SARA, $found->invitedBy()->toString());
        self::assertEquals($createdAt, $found->createdAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-18 10:00:00 UTC'), $found->expiresAt());
        self::assertNull($found->acceptedAt());
        self::assertTrue($found->hasLinkToken());
        self::assertSame(hash('sha256', 'the link token'), $storedHash);
        self::assertSame('{manager}', $storedRoles);
    }

    /**
     * A loaded token_hash comes back as a stream, which must still count as a link token, so the emailed link stays
     * the only one. 2099 keeps the invitation open for resolve_invitation's now().
     */
    #[Test]
    public function aLoadedInvitationWithALinkTokenRefusesASecondOneAndKeepsTheFirstLink(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $invitation = $this->invitationOf(self::NEW_FOR_ACME, self::ACME_FOODS, 'omar@acme-foods.example', new \DateTimeImmutable('2099-03-15 10:00:00 UTC'));
            $invitation->attachLinkTokenHash(hash('sha256', 'the first link token', true));
            $this->invitations()->add($invitation);
        });
        $this->entityManager()->clear();

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $loaded = $this->invitations()->byId(new InvitationId(self::NEW_FOR_ACME));
            self::assertNotNull($loaded);
            try {
                $loaded->attachLinkTokenHash(hash('sha256', 'the second link token', true));
                self::fail('A second link token hash was attached.');
            } catch (\LogicException $refused) {
                self::assertSame('An invitation keeps the link token it was emailed with.', $refused->getMessage());
            }
            $this->invitations()->add($loaded);
        });

        $resolve = fn (string $token): array => $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT invitation_id FROM resolve_invitation(sha256(?::bytea))',
            [$token],
        );
        self::assertSame([self::NEW_FOR_ACME], $resolve('the first link token'));
        self::assertSame([], $resolve('the second link token'));
    }

    #[Test]
    public function anInvitationWithoutATeamOrALinkTokenIsSavedWithNulls(): void
    {
        $stored = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array|false {
            $this->invitations()->add($this->invitationOf(self::NEW_FOR_ACME, self::ACME_FOODS, 'omar@acme-foods.example', new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
            $this->entityManager()->clear();
            $found = $this->invitations()->byId(new InvitationId(self::NEW_FOR_ACME));
            self::assertNotNull($found);
            self::assertFalse($found->hasLinkToken());

            return $this->entityManager()->getConnection()->fetchAssociative('SELECT team_id, team_role, token_hash, accepted_at FROM org_invitation WHERE id = ?', [self::NEW_FOR_ACME]);
        });

        self::assertSame(['team_id' => null, 'team_role' => null, 'token_hash' => null, 'accepted_at' => null], $stored);
    }

    #[Test]
    public function anInvitationOfAnotherOrganizationOrAnUnknownIdIsNotFound(): void
    {
        $this->addInvitationsOfBothOrganizations();

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->invitations()->byId(new InvitationId(self::MIA_AT_ACME))?->email(),
            $this->invitations()->byId(new InvitationId(self::OMAR_AT_BETA)),
            $this->invitations()->byId(new InvitationId(self::NEW_FOR_ACME)),
        ]);

        self::assertSame(['mia@acme-foods.example', null, null], $found);
    }

    #[Test]
    public function lockByIdHoldsTheInvitationRowUntilTheTransactionEnds(): void
    {
        $this->addInvitationsOfBothOrganizations();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $connection = $this->entityManager()->getConnection();
        $otherSession = new CloseOnFailedBeginConnection(
            $connection->getParams(),
            new TenantMiddleware($tenantContext)->wrap(new PgSQLDriver()),
        );
        $lockInvitationRowNowait = static fn (): array => $otherSession->transactional(static fn (Connection $session): array => $session->fetchFirstColumn(
            'SELECT id FROM org_invitation WHERE id = ? FOR UPDATE NOWAIT',
            [self::MIA_AT_ACME],
        ));

        $connection->beginTransaction();
        try {
            self::assertSame('mia@acme-foods.example', $this->invitations()->lockById(new InvitationId(self::MIA_AT_ACME))?->email());
            self::assertNull($this->invitations()->lockById(new InvitationId(self::OMAR_AT_BETA)), 'The invitation of another organization is not found.');
            try {
                $lockInvitationRowNowait();
                self::fail('The invitation row was not locked.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $connection->rollBack();
        }

        self::assertSame([self::MIA_AT_ACME], $lockInvitationRowNowait());
        $otherSession->close();
        $this->entityManager()->clear();
        $tenantContext->clear();
    }

    #[Test]
    public function lockByIdRefusesToRunOutsideATransaction(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Locking an invitation needs an open transaction.');
        $this->invitations()->lockById(new InvitationId(self::MIA_AT_ACME));
    }

    #[Test]
    public function aSecondInvitationNotYetAcceptedOfTheSameEmailInAnyLetterCaseIsRefused(): void
    {
        $this->addInvitationsOfBothOrganizations();

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->invitations()->add(
                $this->invitationOf(self::NEW_FOR_ACME, self::ACME_FOODS, 'Mia@Acme-Foods.example', new \DateTimeImmutable('2027-03-16 10:00:00 UTC')),
            ));
            self::fail('A second open invitation of Mia was saved.');
        } catch (InvitationAlreadyOpen $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(UniqueConstraintViolationException::class, $previous);
            self::assertStringContainsString('org_invitation_organization_id_email_key', $previous->getMessage());
        }

        self::assertFalse(self::getContainer()->get('doctrine.orm.default_entity_manager')->isOpen());
        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame([self::OMAR_EXPIRED, self::OMAR_ACCEPTED, self::MIA_AT_ACME], $this->idsOf(self::ACME_FOODS));
    }

    #[Test]
    public function aUniqueViolationOfAnotherKeyIsNotTakenForAnOpenInvitation(): void
    {
        $this->addInvitationsOfBothOrganizations();
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $mia = $this->invitations()->byId(new InvitationId(self::MIA_AT_ACME));
            self::assertNotNull($mia);
            $mia->attachLinkTokenHash(hash('sha256', 'one link token', true));
            $this->invitations()->add($mia);
        });

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
                $raj = $this->invitationOf(self::NEW_FOR_ACME, self::ACME_FOODS, 'raj@acme-foods.example', new \DateTimeImmutable('2027-03-16 10:00:00 UTC'));
                $raj->attachLinkTokenHash(hash('sha256', 'one link token', true));
                $this->invitations()->add($raj);
            });
            self::fail('Two invitations were saved with the same token hash.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertStringContainsString('org_invitation_token_hash_key', $exception->getMessage());
        }

        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame([self::OMAR_EXPIRED, self::OMAR_ACCEPTED, self::MIA_AT_ACME], $this->idsOf(self::ACME_FOODS));
    }

    #[Test]
    public function removeNotAcceptedForRemovesAnExpiredInvitationOfTheEmailInAnyLetterCaseSoANewOneCanBeAdded(): void
    {
        $this->addInvitationsOfBothOrganizations();

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->invitations()->removeNotAcceptedFor(new OrganizationId(self::ACME_FOODS), 'OMAR@acme-foods.example');
            $this->invitations()->add($this->invitationOf(self::NEW_FOR_ACME, self::ACME_FOODS, 'omar@acme-foods.example', new \DateTimeImmutable('2027-03-16 10:00:00 UTC')));
        });

        self::assertSame([self::OMAR_ACCEPTED, self::MIA_AT_ACME, self::NEW_FOR_ACME], $this->idsOf(self::ACME_FOODS));
        self::assertSame([self::OMAR_AT_BETA], $this->idsOf(self::BETA_BAKERY));
    }

    #[Test]
    public function removeNotAcceptedForRemovesAnOpenInvitationOfThatEmailOnly(): void
    {
        $this->addInvitationsOfBothOrganizations();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->invitations()->removeNotAcceptedFor(new OrganizationId(self::ACME_FOODS), 'mia@acme-foods.example'));

        self::assertSame([self::OMAR_EXPIRED, self::OMAR_ACCEPTED], $this->idsOf(self::ACME_FOODS));
    }

    #[Test]
    public function removeNotAcceptedForLeavesTheInvitationsOfAnotherOrganizationAlone(): void
    {
        $this->addInvitationsOfBothOrganizations();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->invitations()->removeNotAcceptedFor(new OrganizationId(self::BETA_BAKERY), 'omar@acme-foods.example'));

        self::assertSame([self::OMAR_AT_BETA], $this->idsOf(self::BETA_BAKERY));
        self::assertSame([self::OMAR_EXPIRED, self::OMAR_ACCEPTED, self::MIA_AT_ACME], $this->idsOf(self::ACME_FOODS));
    }

    /**
     * @return iterable<string, array{string, string}> the columns team_id, team_role, email, org_roles as SQL, and
     *                                                 the CHECK that refuses them
     */
    public static function rowsTheChecksRefuse(): iterable
    {
        yield 'a team lead' => ["'".self::SHOP_TEAM."', 'lead', 'omar@acme-foods.example', '{}'", 'org_invitation_team_role_check'];
        yield 'a team without a team role' => ["'".self::SHOP_TEAM."', NULL, 'omar@acme-foods.example', '{}'", 'org_invitation_team_check'];
        yield 'a team role without a team' => ["NULL, 'member', 'omar@acme-foods.example', '{}'", 'org_invitation_team_check'];
        yield 'an email in upper case' => ["NULL, NULL, 'Omar@acme-foods.example', '{}'", 'org_invitation_email_check'];
        yield 'an unknown org role' => ["NULL, NULL, 'omar@acme-foods.example', '{owner}'", 'org_invitation_org_roles_check'];
    }

    #[Test]
    #[DataProvider('rowsTheChecksRefuse')]
    public function theTableRefusesARowTheDomainWouldNotMake(string $values, string $check): void
    {
        try {
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->entityManager()->getConnection()->executeStatement(\sprintf(
                "INSERT INTO org_invitation (organization_id, id, team_id, team_role, email, org_roles, invited_by, created_at, expires_at) VALUES ('%s', '%s', %s, '%s', now(), now() + interval '72 hours')",
                self::ACME_FOODS,
                self::NEW_FOR_ACME,
                $values,
                self::SARA,
            )));
            self::fail('The table accepted '.$values);
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
            self::assertStringContainsString($check, $exception->getMessage());
        }
    }

    /**
     * Acme Foods: Omar expired and never accepted, Omar accepted, Mia open. Beta Bakery: Omar open.
     */
    private function addInvitationsOfBothOrganizations(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->invitations()->add($this->invitationOf(self::OMAR_EXPIRED, self::ACME_FOODS, 'omar@acme-foods.example', new \DateTimeImmutable('2027-01-04 10:00:00 UTC')));
            $accepted = $this->invitationOf(self::OMAR_ACCEPTED, self::ACME_FOODS, 'omar@acme-foods.example', new \DateTimeImmutable('2027-02-01 10:00:00 UTC'));
            $accepted->accept(new \DateTimeImmutable('2027-02-02 10:00:00 UTC'));
            $this->invitations()->add($accepted);
            $this->invitations()->add($this->invitationOf(self::MIA_AT_ACME, self::ACME_FOODS, 'mia@acme-foods.example', new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->invitations()->add(
            $this->invitationOf(self::OMAR_AT_BETA, self::BETA_BAKERY, 'omar@acme-foods.example', new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));
    }

    private function invitationOf(string $id, string $organizationId, string $email, \DateTimeImmutable $createdAt): Invitation
    {
        return Invitation::issue(new InvitationId($id), new OrganizationId($organizationId), $email, new OrgRoles(), null, null, new UserId(self::SARA), $createdAt);
    }

    /**
     * @return list<mixed>
     */
    private function idsOf(string $organizationId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->entityManager()->getConnection()->fetchFirstColumn('SELECT id FROM org_invitation ORDER BY id'));
    }

    private function invitations(): DoctrineInvitations
    {
        return new DoctrineInvitations($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
