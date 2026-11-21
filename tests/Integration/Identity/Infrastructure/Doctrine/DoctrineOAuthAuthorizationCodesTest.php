<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Infrastructure\Doctrine\DoctrineOAuthAuthorizationCodes;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * DoctrineOAuthAuthorizationCodes on the real idn_oauth_authorization_code table as pinround_app. The codes are
 * committed, as add() runs its own transaction and consume() is called in a committed one, and removed through the
 * migrator. Every time is fixed: consume() compares with the time it is given; only organizationOfCode() reads the
 * database clock, so its live code is dated ahead of it.
 */
final class DoctrineOAuthAuthorizationCodesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000fa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000fb1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000fc1';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string ISSUED_AT = '2027-03-15 10:00:00 UTC';
    private const string CALLBACK = 'http://127.0.0.1:53127/callback';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected function setUp(): void
    {
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function anAddedCodeIsSavedUnderItsOrganizationAndTheTenantIsPutBack(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));

        $this->codes()->add($code);

        self::assertSame(self::BETA_BAKERY, $this->tenantContext()->get()?->toString());
        self::assertFalse($this->connection()->isTransactionActive());
        $this->tenantContext()->reset();
        self::assertSame(
            [
                'id' => $code->id(),
                'user_id' => self::OMAR,
                'client_id' => $code->clientId(),
                'redirect_uri' => self::CALLBACK,
                'code_challenge' => self::CHALLENGE,
                'scopes' => '["read","rounds:write"]',
                'code_hash' => bin2hex($code->codeHash()),
                'created_at' => '2027-03-15 10:00:00',
                'expires_at' => '2027-03-15 10:01:00',
                'used_at' => null,
            ],
            $this->rowOf(self::ACME_FOODS, $code->id()),
        );
        self::assertSame(0, $this->countAs(self::BETA_BAKERY));
    }

    #[Test]
    public function withNoTenantBeforeNoneIsLeftAfter(): void
    {
        $code = $this->issue(self::ACME_FOODS);

        $this->codes()->add($code);

        self::assertNull($this->tenantContext()->get());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, $this->countAs(self::ACME_FOODS));
    }

    #[Test]
    public function aCodeIsConsumedOnceAndASecondConsumeChangesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        $consumed = $this->consumeAs(self::ACME_FOODS, $code->codeHash(), $this->at('+59 seconds'));

        self::assertNotNull($consumed);
        self::assertSame($code->id(), $consumed->id());
        self::assertSame($code->clientId(), $consumed->clientId());
        self::assertSame(self::CALLBACK, $consumed->redirectUri());
        self::assertSame(self::CHALLENGE, $consumed->codeChallenge());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $consumed->scopes());
        self::assertTrue($consumed->userId()->equals(new UserId(self::OMAR)));
        self::assertTrue($consumed->organizationId()->equals(new OrganizationId(self::ACME_FOODS)));
        self::assertSame('2027-03-15 10:00:59', $consumed->usedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        self::assertNull($this->consumeAs(self::ACME_FOODS, $code->codeHash(), $this->at('+59 seconds')));
        self::assertNull($this->consumeAs(self::ACME_FOODS, $code->codeHash(), $this->at('+30 seconds')));

        self::assertSame('2027-03-15 10:00:59', $this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    /**
     * Two app processes trade the same code at once: the test holds the row's lock until both of their UPDATEs wait
     * on it, then lets go, so they race for real. PostgreSQL checks used_at again after the wait, so only one marks it.
     */
    #[Test]
    public function ofTwoConsumesAtOnceOnTwoConnectionsExactlyOneGetsTheCode(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);
        $consumes = [];
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->migrator()->beginTransaction();
        try {
            self::assertSame([$code->id()], $this->migrator()->fetchFirstColumn('SELECT id FROM idn_oauth_authorization_code WHERE organization_id = ? AND id = ? FOR UPDATE', [self::ACME_FOODS, $code->id()]));
            foreach (['first', 'second'] as $process) {
                $consume = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/consume-oauth-code.php', self::ACME_FOODS, bin2hex($code->codeHash()), $this->at('+5 seconds')->format(\DATE_ATOM)]);
                $consume->start();
                $consumes[$process] = $consume;
            }
            $this->waitUntilBothWaitOnTheLock($consumes);
        } finally {
            $this->migrator()->rollBack();
            $this->tenantContext()->reset();
        }

        $outcomes = [];
        foreach ($consumes as $consume) {
            $consume->wait();
            self::assertTrue($consume->isSuccessful(), $consume->getErrorOutput());
            $outcomes[] = $consume->getOutput();
        }
        sort($outcomes);
        self::assertSame(['lost', 'won'], $outcomes);
        self::assertSame('2027-03-15 10:00:05', $this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function aCodeIsNotConsumedFromTheSecondItExpiresAndStaysUnused(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNull($this->consumeAs(self::ACME_FOODS, $code->codeHash(), $this->at('+60 seconds')));
        self::assertNull($this->consumeAs(self::ACME_FOODS, $code->codeHash(), $this->at('+1 day')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function anUnknownHashConsumesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNull($this->consumeAs(self::ACME_FOODS, hash('sha256', 'no code has this hash', true), $this->at('+5 seconds')));
        self::assertNull($this->consumeAs(self::ACME_FOODS, '', $this->at('+5 seconds')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function aCodeOfOneOrganizationIsNeitherSeenNorConsumedUnderAnother(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNull($this->consumeAs(self::BETA_BAKERY, $code->codeHash(), $this->at('+5 seconds')));

        self::assertSame(0, $this->countAs(self::BETA_BAKERY));
        self::assertSame(1, $this->countAs(self::ACME_FOODS));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_authorization_code'));
        self::assertNull($this->transactions()->committedAs(
            self::BETA_BAKERY,
            fn (): ?OAuthAuthorizationCode => $this->entityManager()->find(OAuthAuthorizationCode::class, ['organizationId' => self::ACME_FOODS, 'id' => $code->id()]),
        ));
        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function anAddInsideAnOpenTransactionIsRefusedAndSavesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->connection()->beginTransaction();
        try {
            $this->codes()->add($code);
            self::fail('The add ran inside an open transaction.');
        } catch (TenantSwitchRefused) {
            self::assertSame(self::ACME_FOODS, $this->tenantContext()->get()?->toString());
        } finally {
            $this->connection()->rollBack();
            $this->tenantContext()->reset();
        }

        self::assertSame(0, $this->countAs(self::ACME_FOODS));
    }

    #[Test]
    public function aConsumeOutsideATransactionIsRefusedAndChangesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->codes()->consume($code->codeHash(), $this->at('+5 seconds'));
            self::fail('The consume ran outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A code is traded only in an open transaction; none is open.', $refusal->getMessage());
        } finally {
            $this->tenantContext()->reset();
        }

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function aConsumeWithoutATenantIsRefusedAndChangesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);
        $this->connection()->beginTransaction();
        try {
            $this->codes()->consume($code->codeHash(), $this->at('+5 seconds'));
            self::fail('The consume ran without a tenant.');
        } catch (TenantNotSet) {
            self::assertNull($this->tenantContext()->get());
        } finally {
            $this->connection()->rollBack();
        }

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    /**
     * A rolled-back consume leaves the code unused, so the tokens of a trade and its use commit or vanish together.
     */
    #[Test]
    public function aConsumeIsUndoneWithItsTransaction(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNotNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?OAuthAuthorizationCode => $this->codes()->consume($code->codeHash(), $this->at('+5 seconds'))));

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function onlyAUsedCodeOfTheTenantGivesItsId(): void
    {
        $used = $this->issue(self::ACME_FOODS);
        $unused = $this->issue(self::ACME_FOODS);
        $this->codes()->add($used);
        $this->codes()->add($unused);
        self::assertNotNull($this->consumeAs(self::ACME_FOODS, $used->codeHash(), $this->at('+5 seconds')));

        self::assertSame($used->id(), $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->codes()->idOfUsedCode($used->codeHash())));
        self::assertNull($this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->codes()->idOfUsedCode($unused->codeHash())));
        self::assertNull($this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->codes()->idOfUsedCode(hash('sha256', 'no code has this hash', true))));
        self::assertNull($this->transactions()->committedAs(self::BETA_BAKERY, fn (): ?string => $this->codes()->idOfUsedCode($used->codeHash())));
    }

    #[Test]
    public function theAppMayChangeNoColumnButUsedAtAndMayNotDelete(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        foreach ([
            "UPDATE idn_oauth_authorization_code SET expires_at = expires_at + interval '1 second' WHERE organization_id = ?",
            "UPDATE idn_oauth_authorization_code SET scopes = '{read}' WHERE organization_id = ?",
            'DELETE FROM idn_oauth_authorization_code WHERE organization_id = ?',
        ] as $statement) {
            // On the connection, not through the entity manager, which a failed transaction would close.
            $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
            try {
                $this->connection()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement($statement, [self::ACME_FOODS]));
                self::fail('pinround_app ran: '.$statement);
            } catch (DriverException $refusal) {
                self::assertSame('42501', $refusal->getSQLState(), $statement);
                self::assertStringContainsString('permission denied for table idn_oauth_authorization_code', $refusal->getMessage());
            } finally {
                $this->tenantContext()->clear();
            }
        }

        self::assertSame(
            ['scopes' => '["read","rounds:write"]', 'expires_at' => '2027-03-15 10:01:00', 'used_at' => null],
            array_intersect_key($this->rowOf(self::ACME_FOODS, $code->id()), ['expires_at' => true, 'scopes' => true, 'used_at' => true]),
        );
    }

    #[Test]
    public function theResolverGivesTheOrganizationOfALiveOrUsedCodeOnly(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-01-01 09:00:00+00')",
            [self::ACME_FOODS, self::OMAR],
        ));
        // Ahead of the database clock, which the resolver reads.
        $live = $this->issue(self::ACME_FOODS, '2099-01-01 10:00:00 UTC');
        $expired = $this->issue(self::ACME_FOODS, '2020-01-01 10:00:00 UTC');
        $usedAndExpired = $this->issue(self::ACME_FOODS, '2020-01-01 10:00:00 UTC');
        $this->codes()->add($live);
        $this->codes()->add($expired);
        $this->codes()->add($usedAndExpired);
        self::assertNotNull($this->consumeAs(self::ACME_FOODS, $usedAndExpired->codeHash(), new \DateTimeImmutable('2020-01-01 10:00:05 UTC')));

        self::assertSame(self::ACME_FOODS, $this->codes()->organizationOfCode($live->codeHash())?->toString());
        self::assertSame(self::ACME_FOODS, $this->codes()->organizationOfCode($usedAndExpired->codeHash())?->toString());
        self::assertNull($this->codes()->organizationOfCode($expired->codeHash()));
        self::assertNull($this->codes()->organizationOfCode(hash('sha256', 'no code has this hash', true)));
    }

    /**
     * @return iterable<string, array{string, string}> a column change that breaks one constraint, and that constraint
     */
    public static function rowsBreakingAConstraint(): iterable
    {
        yield 'a life of 61 seconds' => ["expires_at = created_at + interval '61 seconds'", 'idn_oauth_authorization_code_expires_at_check'];
        yield 'an expiry at the issue' => ['expires_at = created_at', 'idn_oauth_authorization_code_expires_at_check'];
        yield 'a challenge of 42 characters' => ['code_challenge = substr(code_challenge, 2)', 'idn_oauth_authorization_code_code_challenge_check'];
        yield 'a challenge with a final newline' => ["code_challenge = code_challenge || E'\\n'", 'idn_oauth_authorization_code_code_challenge_check'];
        yield 'a challenge with a plus' => ["code_challenge = '+' || substr(code_challenge, 2)", 'idn_oauth_authorization_code_code_challenge_check'];
        yield 'a hash of 31 bytes' => ['code_hash = substring(code_hash from 2)', 'idn_oauth_authorization_code_code_hash_check'];
        yield 'a client_id of 31 characters' => ['client_id = substr(client_id, 2)', 'idn_oauth_authorization_code_client_id_check'];
        yield 'no scope' => ["scopes = '{}'", 'idn_oauth_authorization_code_scopes_check'];
        yield 'an unknown scope' => ["scopes = '{read,admin}'", 'idn_oauth_authorization_code_scopes_check'];
        yield 'an empty redirect URI' => ["redirect_uri = ''", 'idn_oauth_authorization_code_redirect_uri_check'];
        yield 'a redirect URI of 513 bytes' => ["redirect_uri = 'https://a.example/' || repeat('a', 495)", 'idn_oauth_authorization_code_redirect_uri_check'];
    }

    /**
     * Through the migrator, which owns the table, so that a refusal can come from the constraint only.
     */
    #[Test]
    #[DataProvider('rowsBreakingAConstraint')]
    public function aRowBreakingAConstraintIsRefused(string $change, string $constraint): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'UPDATE idn_oauth_authorization_code SET '.$change.' WHERE organization_id = ? AND id = ?',
                [self::ACME_FOODS, $code->id()],
            ));
            self::fail('The change was accepted: '.$change);
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString($constraint, $refusal->getMessage());
        } finally {
            $this->tenantContext()->reset();
        }

        self::assertSame(['client_id' => $code->clientId(), 'expires_at' => '2027-03-15 10:01:00'], array_intersect_key($this->rowOf(self::ACME_FOODS, $code->id()), ['expires_at' => true, 'client_id' => true]));
    }

    private function issue(string $organizationId, string $issuedAt = self::ISSUED_AT): OAuthAuthorizationCode
    {
        return OAuthAuthorizationCode::issue(
            $this->codes()->nextIdentity(),
            new OrganizationId($organizationId),
            new UserId(self::OMAR),
            OAuthClient::register('Claude Code', [self::CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable('2027-03-15 09:00:00 UTC')),
            self::CALLBACK,
            self::CHALLENGE,
            'S256',
            [ApiTokenScope::RoundsWrite, ApiTokenScope::Read],
            new HmacApiTokenHashing(self::PEPPER),
            new \DateTimeImmutable($issuedAt),
        )->code();
    }

    /**
     * Polls pg_locks, which every role may read, on the app's connection outside the held transaction. Fails when a
     * process ends first, such as on a boot error, or when both are not waiting within 30 seconds.
     *
     * @param array<string, Process> $consumes
     */
    private function waitUntilBothWaitOnTheLock(array $consumes): void
    {
        $deadline = microtime(true) + 30;
        do {
            foreach ($consumes as $name => $consume) {
                self::assertTrue($consume->isRunning(), 'the '.$name.' consume ended before it waited: '.$consume->getOutput().$consume->getErrorOutput());
            }
            $waitingProcesses = $this->connection()->fetchOne('SELECT count(DISTINCT pid) FROM pg_locks WHERE NOT granted');
            if (2 === $waitingProcesses) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('The two consumes did not both wait on the held row within 30 seconds.');
    }

    private function consumeAs(string $organizationId, string $codeHash, \DateTimeImmutable $at): ?OAuthAuthorizationCode
    {
        return $this->transactions()->committedAs($organizationId, fn (): ?OAuthAuthorizationCode => $this->codes()->consume($codeHash, $at));
    }

    private function at(string $afterIssue): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::ISSUED_AT)->modify($afterIssue);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(string $organizationId, string $id): array
    {
        $row = $this->transactions()->committedAs($organizationId, fn (): array|false => $this->connection()->fetchAssociative(
            "SELECT id, user_id, client_id, redirect_uri, code_challenge, array_to_json(scopes)::text AS scopes, encode(code_hash, 'hex') AS code_hash,"
            ." to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at,"
            ." to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at,"
            ." to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS used_at"
            .' FROM idn_oauth_authorization_code WHERE organization_id = ? AND id = ?',
            [$organizationId, $id],
        ));
        self::assertIsArray($row, 'No code row '.$id);

        return $row;
    }

    private function countAs(string $organizationId): mixed
    {
        return $this->transactions()->committedAs($organizationId, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_authorization_code'));
    }

    /**
     * Through the migrator, which owns the tables and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeRowsOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext()->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM idn_oauth_authorization_code WHERE organization_id = ?', [$organizationId]);
                    $migrator->executeStatement('DELETE FROM org_membership WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext()->clear();
            }
        }
    }

    private function codes(): OAuthAuthorizationCodes
    {
        $codes = self::getContainer()->get('test.identity_oauth_authorization_codes');
        self::assertInstanceOf(DoctrineOAuthAuthorizationCodes::class, $codes);

        return $codes;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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
