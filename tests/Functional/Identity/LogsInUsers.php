<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrineCredentials;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\SymfonyPasswordHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Factory\Identity\UserFactory;
use App\Tests\Integration\Organization\OrganizationRows;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Users with a password for functional tests, committed so the requests' own connections see them, and deleted
 * through the migrator after each test (pinround_app has no DELETE on the Identity tables). Each user is a member of
 * one organization of their own unless the test asks otherwise, so the pages that need an organization open at once
 * (EnterChosenOrganization chooses a user's only one). Use it next to Foundry's Factories trait, in a test class
 * without its own tearDown.
 */
trait LogsInUsers
{
    private const string PASSWORD = 'correct horse battery staple';

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdOrganizationIds = [];

    private function createUserWithPassword(string $name, string $password = self::PASSWORD): User
    {
        $user = $this->createUserWithoutOrganization($name, $password);
        $this->addMembership($user, $this->createOrganization('Acme Foods'));

        return $user;
    }

    /**
     * @param string|null $email null for the factory's own, unique one
     */
    private function createUserWithoutOrganization(string $name, string $password = self::PASSWORD, ?string $email = null): User
    {
        $user = UserFactory::createOne(null === $email ? ['name' => $name] : ['name' => $name, 'email' => new Email($email)]);
        $this->createdUserIds[] = $user->id()->toString();

        $hasherFactory = self::getContainer()->get('security.password_hasher_factory');
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $hash = new SymfonyPasswordHashing($hasherFactory)->hash(new NewPassword($password));
        new DoctrineCredentials($entityManager)->add(new Credential($user, $hash, new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));

        return $user;
    }

    /**
     * Logs in without the form, through the provider the firewall uses.
     */
    private function logIn(KernelBrowser $client, User $user): void
    {
        $provider = self::getContainer()->get(SecurityUserProvider::class);
        $client->loginUser($provider->loadUserByIdentifier($user->email()->toString()), 'main');
    }

    private function blockUser(User $user): void
    {
        $this->migrator()->executeStatement("UPDATE idn_user SET status = 'blocked' WHERE id = ?", [$user->id()->toString()]);
    }

    /**
     * A committed organization row with a unique slug; the name may repeat across organizations.
     */
    private function createOrganization(string $name): OrganizationId
    {
        $organizationId = new OrganizationId(Uuid::v7()->toRfc4122());
        $this->createdOrganizationIds[] = $organizationId->toString();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_organization (organization_id, name, slug, status, created_at) VALUES (?, ?, ?, 'active', '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), $name, 'test-'.$organizationId->toString()],
        ));

        return $organizationId;
    }

    private function addMembership(User $user, OrganizationId $organizationId): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), $user->id()->toString()],
        ));
    }

    /**
     * @param string $orgRoles a PostgreSQL text[] literal, such as '{admin}'
     */
    private function setOrgRoles(User $user, OrganizationId $organizationId, string $orgRoles): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE org_membership SET org_roles = ? WHERE organization_id = ? AND user_id = ?',
            [$orgRoles, $organizationId->toString(), $user->id()->toString()],
        ));
    }

    private function removeMembership(User $user, OrganizationId $organizationId): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [$organizationId->toString(), $user->id()->toString()],
        ));
    }

    /**
     * Through the migrator, which owns the org_ tables; FORCE ROW LEVEL SECURITY binds it too, so the work runs with the
     * organization as tenant. The tenant is reset, not switched or cleared: those ask the switch guard, which refuses
     * while the entity manager holds the test's users, and the last request may have left its own tenant set.
     *
     * @param \Closure(Connection): (int|string) $work the affected row count, as executeStatement() gives it
     */
    private function committedAs(OrganizationId $organizationId, \Closure $work): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            $this->migrator()->transactional($work);
        } finally {
            $tenantContext->reset();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (OrganizationRows::TABLES as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->createdOrganizationIds = [];

        if ([] !== $this->createdUserIds) {
            $migrator = $this->migrator();
            $migrator->executeStatement('DELETE FROM idn_password_reset WHERE user_id IN (?)', [$this->createdUserIds], [ArrayParameterType::STRING]);
            $migrator->executeStatement('DELETE FROM idn_mfa_secret WHERE user_id IN (?)', [$this->createdUserIds], [ArrayParameterType::STRING]);
            $migrator->executeStatement('DELETE FROM idn_credential WHERE user_id IN (?)', [$this->createdUserIds], [ArrayParameterType::STRING]);
            $migrator->executeStatement('DELETE FROM idn_user WHERE id IN (?)', [$this->createdUserIds], [ArrayParameterType::STRING]);
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
