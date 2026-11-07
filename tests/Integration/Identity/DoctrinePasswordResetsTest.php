<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrinePasswordResets;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DoctrinePasswordResets on the real idn_password_reset table as pinround_app, in a transaction rolled back after
 * each test.
 */
final class DoctrinePasswordResetsTest extends KernelTestCase
{
    use Factories;

    private const string CREATED_AT = '2027-03-15 10:00:00 UTC';

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
    public function theRowHoldsTheTokensSha256AndNeverTheToken(): void
    {
        $token = PasswordResetToken::generate();
        $reset = $this->addResetFor(UserFactory::createOne(), $token);

        $row = $this->connection()->fetchAssociative(
            "SELECT token_hash, user_id::text AS user_id, to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at, used_at"
            .' FROM idn_password_reset WHERE id = ?',
            [$reset->id()],
        );

        self::assertIsArray($row);
        $storedHash = \is_resource($row['token_hash']) ? stream_get_contents($row['token_hash']) : $row['token_hash'];
        self::assertSame(hash('sha256', $token->reveal(), true), $storedHash);
        self::assertSame($reset->userId()->toString(), $row['user_id']);
        self::assertSame('2027-03-15 11:00:00', $row['expires_at']);
        self::assertNull($row['used_at']);
        self::assertSame(43, \strlen($token->reveal()));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token->reveal());
        $everyColumn = $this->connection()->fetchOne('SELECT row_to_json(r)::text FROM idn_password_reset r WHERE id = ?', [$reset->id()]);
        self::assertIsString($everyColumn);
        self::assertStringNotContainsString($token->reveal(), $everyColumn);
    }

    #[Test]
    public function aTokenIsConsumedOnceAndThenNoLongerUsable(): void
    {
        $user = UserFactory::createOne();
        $token = PasswordResetToken::generate();
        $this->addResetFor($user, $token);
        $now = new \DateTimeImmutable('2027-03-15 10:30:00 UTC');
        self::assertTrue($this->resets()->isUsable($token, $now));

        $first = $this->resets()->consume(PasswordResetToken::fromLink($token->reveal()), $now);
        $second = $this->resets()->consume($token, $now);

        self::assertNotNull($first);
        self::assertTrue($user->id()->equals($first));
        self::assertNull($second);
        self::assertFalse($this->resets()->isUsable($token, $now));
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM idn_password_reset WHERE used_at IS NOT NULL AND token_hash = ?', [$token->hash()], [ParameterType::BINARY]));
    }

    #[Test]
    public function aResetWorksUntilItsHourIsOver(): void
    {
        $token = PasswordResetToken::generate();
        $this->addResetFor(UserFactory::createOne(), $token);

        self::assertTrue($this->resets()->isUsable($token, new \DateTimeImmutable('2027-03-15 10:59:59 UTC')));
        self::assertFalse($this->resets()->isUsable($token, new \DateTimeImmutable('2027-03-15 11:00:00 UTC')));
        self::assertNull($this->resets()->consume($token, new \DateTimeImmutable('2027-03-15 11:00:01 UTC')));
        self::assertNotNull($this->resets()->consume($token, new \DateTimeImmutable('2027-03-15 10:59:00 UTC')));
    }

    #[Test]
    public function anUnknownTokenIsNeitherUsableNorConsumed(): void
    {
        $this->addResetFor(UserFactory::createOne(), PasswordResetToken::generate());
        $unknown = PasswordResetToken::fromLink('not-a-token-anyone-was-sent');
        $now = new \DateTimeImmutable('2027-03-15 10:30:00 UTC');

        self::assertFalse($this->resets()->isUsable($unknown, $now));
        self::assertNull($this->resets()->consume($unknown, $now));
    }

    private function addResetFor(User $user, PasswordResetToken $token): PasswordReset
    {
        $reset = new PasswordReset($this->resets()->nextIdentity(), $user, $token, new \DateTimeImmutable(self::CREATED_AT));
        $this->resets()->add($reset);

        return $reset;
    }

    private function resets(): DoctrinePasswordResets
    {
        return new DoctrinePasswordResets(self::getContainer()->get('doctrine.orm.default_entity_manager'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
