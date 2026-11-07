<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\Credential;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrineCredentials;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\SymfonyPasswordHashing;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Users with a password for functional tests, committed so the requests' own connections see them, and deleted
 * through the migrator after each test (pinround_app has no DELETE on the Identity tables). Use it next to Foundry's
 * Factories trait, in a test class without its own tearDown.
 */
trait LogsInUsers
{
    private const string PASSWORD = 'correct horse battery staple';

    /** @var list<string> */
    private array $createdUserIds = [];

    private function createUserWithPassword(string $name, string $password = self::PASSWORD): User
    {
        $user = UserFactory::createOne(['name' => $name]);
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

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            $migrator = $this->migrator();
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
