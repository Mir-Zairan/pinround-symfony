<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\Credential;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\UserId;
use App\Identity\Infrastructure\Doctrine\DoctrineCredentials;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DoctrineCredentials on the real idn_credential table as pinround_app, in a transaction rolled back after each test.
 */
final class DoctrineCredentialsTest extends KernelTestCase
{
    use Factories;

    private const string ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection()->rollBack();
        parent::tearDown();
    }

    #[Test]
    public function aSavedCredentialIsFoundByItsUsersId(): void
    {
        $user = UserFactory::createOne();
        $changedAt = new \DateTimeImmutable('2026-11-03 14:00:00 UTC');
        $this->credentials()->add(new Credential($user, new PasswordHash(self::ARGON2ID_HASH), $changedAt));
        $this->entityManager->clear();

        $found = $this->credentials()->byUserId($user->id());

        self::assertNotNull($found);
        self::assertTrue($user->id()->equals($found->userId()));
        self::assertSame(self::ARGON2ID_HASH, $found->passwordHash()->encoded());
        self::assertSame($changedAt->getTimestamp(), $found->changedAt()->getTimestamp());
    }

    #[Test]
    public function aUserWithoutACredentialHasNone(): void
    {
        $withCredential = UserFactory::createOne();
        $this->credentials()->add(new Credential($withCredential, new PasswordHash(self::ARGON2ID_HASH), new \DateTimeImmutable('2026-11-03 14:00:00 UTC')));
        $withoutCredential = UserFactory::createOne();

        self::assertNull($this->credentials()->byUserId($withoutCredential->id()));
    }

    #[Test]
    public function aCredentialForAUserThatDoesNotExistIsRefused(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection()->executeStatement(
            'INSERT INTO idn_credential (user_id, password_hash, changed_at) VALUES (?, ?, now())',
            [new UserId('0197a3c0-0000-7000-8000-0000000000ff')->toString(), self::ARGON2ID_HASH],
        );
    }

    private function credentials(): DoctrineCredentials
    {
        return new DoctrineCredentials($this->entityManager);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
