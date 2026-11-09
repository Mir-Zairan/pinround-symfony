<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\RecoveryCodeHashing;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Infrastructure\Doctrine\DoctrineTwoStepLogins;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DoctrineTwoStepLogins on the real idn_mfa_secret table as pinround_app, with the FieldEncryption and the recovery
 * code hashing of the container (the .env.test key), in a transaction rolled back after each test.
 */
final class DoctrineTwoStepLoginsTest extends KernelTestCase
{
    use Factories;

    private const string SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        $this->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection()->rollBack();
        parent::tearDown();
    }

    #[Test]
    public function theRowHoldsTheSeedOnlyEncryptedAndItReadsBackThroughTheContainersKey(): void
    {
        $user = UserFactory::createOne();
        $encryption = self::getContainer()->get(FieldEncryption::class);
        $this->twoStepLogins()->save(new TwoStepLogin($user, $encryption->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        $this->entityManager()->clear();

        $row = $this->connection()->fetchAssociative(
            'SELECT secret_enc, confirmed_at, recovery_code_hashes::text AS recovery_code_hashes, last_used_step,'
            ." to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at FROM idn_mfa_secret WHERE user_id = ?",
            [$user->id()->toString()],
        );

        self::assertIsArray($row);
        $stored = \is_resource($row['secret_enc']) ? stream_get_contents($row['secret_enc']) : $row['secret_enc'];
        self::assertIsString($stored);
        self::assertNotSame(self::SEED, $stored);
        self::assertStringNotContainsString(self::SEED, $stored);
        self::assertSame(self::SEED, $encryption->decrypt($stored));
        self::assertNull($row['confirmed_at']);
        self::assertSame('{}', $row['recovery_code_hashes']);
        self::assertNull($row['last_used_step']);
        self::assertSame('2027-03-15 10:00:00', $row['created_at']);
        $everyColumn = $this->connection()->fetchOne('SELECT row_to_json(m)::text FROM idn_mfa_secret m WHERE user_id = ?', [$user->id()->toString()]);
        self::assertIsString($everyColumn);
        self::assertStringNotContainsString(self::SEED, $everyColumn);

        $readBack = $this->twoStepLogins()->byUserId($user->id());
        self::assertNotNull($readBack);
        self::assertSame(self::SEED, $encryption->decrypt($readBack->encryptedSeed()));
        self::assertFalse($readBack->isOn());
    }

    #[Test]
    public function aConfirmedOneKeepsTheRecoveryCodesOnlyAsHashesAndTheLastStep(): void
    {
        $user = UserFactory::createOne();
        $hashing = self::getContainer()->get(RecoveryCodeHashing::class);
        $recoveryCodes = ['k7m2q-x9p4t', 'a1b2c-d3e4f'];
        $twoStepLogin = new TwoStepLogin($user, self::getContainer()->get(FieldEncryption::class)->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogins = $this->twoStepLogins();
        $twoStepLogins->save($twoStepLogin);
        // Past 2^31, so a 32-bit column would not hold it.
        $twoStepLogin->confirm(58_000_000_123, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), array_map($hashing->hash(...), $recoveryCodes));
        $twoStepLogins->save($twoStepLogin);
        $this->entityManager()->clear();

        $everyColumn = $this->connection()->fetchOne('SELECT row_to_json(m)::text FROM idn_mfa_secret m WHERE user_id = ?', [$user->id()->toString()]);
        self::assertIsString($everyColumn);
        foreach ($recoveryCodes as $recoveryCode) {
            self::assertStringNotContainsString($recoveryCode, $everyColumn);
        }

        $readBack = $twoStepLogins->byUserIdForChange($user->id());
        self::assertNotNull($readBack);
        self::assertTrue($readBack->isOn());
        self::assertSame('2027-03-15T10:01:00+00:00', $readBack->confirmedAt()?->format(\DATE_ATOM));
        self::assertSame(58_000_000_123, $readBack->lastUsedStep());
        $hashes = $readBack->recoveryCodeHashes();
        self::assertCount(2, $hashes);
        self::assertStringStartsWith('$argon2id$', $hashes[0]);
        self::assertTrue($hashing->matches($hashes[0], 'k7m2q-x9p4t'));
        self::assertTrue($hashing->matches($hashes[1], 'a1b2c-d3e4f'));
        self::assertFalse($hashing->matches($hashes[0], 'a1b2c-d3e4f'));
    }

    #[Test]
    public function aUserWithoutOneHasNone(): void
    {
        $user = UserFactory::createOne();

        self::assertNull($this->twoStepLogins()->byUserId($user->id()));
        self::assertNull($this->twoStepLogins()->byUserIdForChange($user->id()));
    }

    #[Test]
    public function readingForAChangeOutsideATransactionIsRefused(): void
    {
        $user = UserFactory::createOne();
        $this->connection()->rollBack();

        try {
            $this->twoStepLogins()->byUserIdForChange($user->id());
            self::fail('A read for a change ran without a transaction to hold its lock.');
        } catch (\LogicException $refusal) {
            self::assertSame('Changing a two-step login needs an open transaction to hold the lock.', $refusal->getMessage());
        } finally {
            $this->connection()->beginTransaction();
        }
    }

    #[Test]
    public function readingForAChangeHoldsTheUsersRowUntilTheTransactionEnds(): void
    {
        // The other session must see the user, so it is committed here and removed through the migrator at the end.
        $this->connection()->rollBack();
        $userId = UserFactory::createOne()->id();
        $otherSession = new CloseOnFailedBeginConnection($this->connection()->getParams(), new PgSQLDriver());
        $lockUserRowNowait = static fn (): array => $otherSession->transactional(static fn (Connection $session): array => $session->fetchFirstColumn(
            'SELECT id FROM idn_user WHERE id = ? FOR NO KEY UPDATE NOWAIT',
            [$userId->toString()],
        ));

        try {
            $this->connection()->beginTransaction();
            try {
                self::assertNull($this->twoStepLogins()->byUserIdForChange($userId));
                try {
                    $lockUserRowNowait();
                    self::fail('The user row was not locked.');
                } catch (DriverException $exception) {
                    self::assertSame('55P03', $exception->getSQLState());
                }
            } finally {
                $this->connection()->rollBack();
            }

            self::assertSame([$userId->toString()], $lockUserRowNowait());
        } finally {
            $otherSession->close();
            $this->entityManager()->clear();
            self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('DELETE FROM idn_user WHERE id = ?', [$userId->toString()]);
            $this->connection()->beginTransaction();
        }
    }

    private function twoStepLogins(): DoctrineTwoStepLogins
    {
        return new DoctrineTwoStepLogins($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
