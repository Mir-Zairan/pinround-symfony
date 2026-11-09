<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Api\InvitedPersonRegistration;
use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\Identity\Application\RegisterInvitedPerson;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\Identity\Infrastructure\Doctrine\DoctrineCredentials;
use App\Identity\Infrastructure\Doctrine\DoctrineUsers;
use App\SharedKernel\Domain\UserId;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * RegisterInvitedPerson on the real idn_user and idn_credential tables as pinround_app, in a
 * transaction rolled back after each test, since the app may not delete users.
 */
final class RegisterInvitedPersonTest extends KernelTestCase
{
    use Factories;

    private const string PASSWORD = 'correct horse battery';

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
    public function theRegisteredPersonHasAnActiveUserRowAndAnArgon2idPasswordThatVerifies(): void
    {
        $userId = $this->registration()->register(' Omar@Acme-Foods.Example', 'Omar', self::PASSWORD, new \DateTimeImmutable('2026-11-09 14:15:16 UTC'));
        $this->entityManager->clear();

        self::assertSame(
            [['id' => $userId->toString(), 'email' => 'omar@acme-foods.example', 'name' => 'Omar', 'status' => 'active', 'locale' => 'en', 'time_zone' => 'UTC', 'theme' => 'system', 'created_at' => '2026-11-09 14:15:16']],
            $this->connection()->fetchAllAssociative("SELECT id, email, name, status, locale, time_zone, theme, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at FROM idn_user"),
        );
        $credentialRow = $this->connection()->fetchAssociative(
            "SELECT password_hash, to_char(changed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS changed_at FROM idn_credential WHERE user_id = ?",
            [$userId->toString()],
        );
        self::assertIsArray($credentialRow);
        self::assertSame('2026-11-09 14:15:16', $credentialRow['changed_at']);
        self::assertIsString($credentialRow['password_hash']);
        self::assertStringStartsWith('$argon2id$', $credentialRow['password_hash']);
        $passwordHashing = self::getContainer()->get(PasswordHashing::class);
        self::assertTrue($passwordHashing->matches(new PasswordHash($credentialRow['password_hash']), self::PASSWORD));
        self::assertFalse($passwordHashing->matches(new PasswordHash($credentialRow['password_hash']), 'correct horse battery!'));
    }

    #[Test]
    public function anEmailAnAccountHasInAnotherLetterCaseIsRefusedAsTakenAndAddsNoRow(): void
    {
        $lena = UserFactory::createOne(['email' => new Email('lena@acme-foods.example')]);

        try {
            $this->registration()->register('LENA@Acme-Foods.Example ', 'Lena', self::PASSWORD, new \DateTimeImmutable('2026-11-09 14:15:16 UTC'));
            self::fail('RegistrationRefused expected.');
        } catch (RegistrationRefused $refused) {
            self::assertSame([RegistrationRefusal::EmailTaken], $refused->refusals);
        }

        self::assertSame([$lena->id()->toString()], $this->connection()->fetchFirstColumn('SELECT id FROM idn_user'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT user_id FROM idn_credential'));
    }

    #[Test]
    public function anEmailAnotherTransactionTookAfterTheCheckIsRefusedAsTakenWithTheEntityManagerClosed(): void
    {
        UserFactory::createOne(['email' => new Email('mia@acme-foods.example')]);
        $usersMissingTheOtherTransactionsRow = new readonly class(new DoctrineUsers($this->entityManager)) implements Users {
            public function __construct(private DoctrineUsers $users)
            {
            }

            public function nextIdentity(): UserId
            {
                return $this->users->nextIdentity();
            }

            public function byId(UserId $id): ?User
            {
                return $this->users->byId($id);
            }

            public function byEmail(Email $email): ?User
            {
                return null;
            }

            public function add(User $user): void
            {
                $this->users->add($user);
            }

            public function save(User $user): void
            {
                $this->users->save($user);
            }
        };
        $registration = new RegisterInvitedPerson($usersMissingTheOtherTransactionsRow, new DoctrineCredentials($this->entityManager), self::getContainer()->get(PasswordHashing::class));

        try {
            $registration->register('Mia@Acme-Foods.Example', 'Mia', self::PASSWORD, new \DateTimeImmutable('2026-11-09 14:15:16 UTC'));
            self::fail('RegistrationRefused expected.');
        } catch (RegistrationRefused $refused) {
            self::assertSame([RegistrationRefusal::EmailTaken], $refused->refusals);
        }

        self::assertFalse($this->entityManager->isOpen());
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT user_id FROM idn_credential'));
    }

    /**
     * Built by hand: until the accept page injects it, the container prunes the autowired alias.
     */
    private function registration(): InvitedPersonRegistration
    {
        return new RegisterInvitedPerson(new DoctrineUsers($this->entityManager), new DoctrineCredentials($this->entityManager), self::getContainer()->get(PasswordHashing::class));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
