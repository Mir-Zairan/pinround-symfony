<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DbalUserQueries;
use App\Identity\Infrastructure\Doctrine\DoctrineUsers;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalUserQueries on the real idn_user table as pinround_app. Each test runs in a transaction that is rolled back, since
 * the app may not delete users.
 */
final class DbalUserQueriesTest extends KernelTestCase
{
    private const string USER_ID = '0197a3c0-0000-7000-8000-0000000000d1';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->entityManager->getConnection()->beginTransaction();
        new DoctrineUsers($this->entityManager)->add(
            new User(new UserId(self::USER_ID), new Email('mia@acme-foods.example'), 'Mia', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC')),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    #[Test]
    #[TestWith(['mia@acme-foods.example'])]
    #[TestWith(['  Mia@Acme-Foods.example '])]
    public function theIdOfTheUserWithTheAddressIsFoundInAnyCase(string $email): void
    {
        self::assertSame(self::USER_ID, $this->userQueries()->idByEmail($email)?->toString());
    }

    #[Test]
    #[TestWith(['omar@acme-foods.example'])]
    #[TestWith(['not an address'])]
    #[TestWith([''])]
    public function anAddressNoOneHasOrAMalformedOneGivesNull(string $email): void
    {
        self::assertNull($this->userQueries()->idByEmail($email));
    }

    private function userQueries(): DbalUserQueries
    {
        return new DbalUserQueries($this->entityManager->getConnection());
    }
}
