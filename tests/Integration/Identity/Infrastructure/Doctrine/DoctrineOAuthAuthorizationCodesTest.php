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

/**
 * DoctrineOAuthAuthorizationCodes on the real idn_oauth_authorization_code table as pinround_app. The codes are
 * committed, as add() and consume() run their own transactions, and removed through the migrator. Every time is fixed:
 * consume() compares with the time it is given; only organizationOfLiveCode() reads the database clock, so its live
 * code is dated ahead of it.
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

        self::assertNotNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+5 seconds')));
        self::assertNull($this->tenantContext()->get());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function aCodeIsConsumedOnceAndASecondConsumeChangesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));

        $consumed = $this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+59 seconds'));

        self::assertNotNull($consumed);
        self::assertSame($code->id(), $consumed->id());
        self::assertSame($code->clientId(), $consumed->clientId());
        self::assertSame(self::CALLBACK, $consumed->redirectUri());
        self::assertSame(self::CHALLENGE, $consumed->codeChallenge());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $consumed->scopes());
        self::assertTrue($consumed->userId()->equals(new UserId(self::OMAR)));
        self::assertTrue($consumed->organizationId()->equals(new OrganizationId(self::ACME_FOODS)));
        self::assertSame('2027-03-15 10:00:59', $consumed->usedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame(self::BETA_BAKERY, $this->tenantContext()->get()?->toString());

        self::assertNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+59 seconds')));
        self::assertNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+30 seconds')));

        $this->tenantContext()->reset();
        self::assertSame('2027-03-15 10:00:59', $this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function aCodeIsNotConsumedFromTheSecondItExpiresAndStaysUnused(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+60 seconds')));
        self::assertNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+1 day')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function anUnknownHashConsumesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), hash('sha256', 'no code has this hash', true), $this->at('+5 seconds')));
        self::assertNull($this->codes()->consume(new OrganizationId(self::ACME_FOODS), '', $this->at('+5 seconds')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
    }

    #[Test]
    public function aCodeOfOneOrganizationIsNeitherSeenNorConsumedUnderAnother(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);

        self::assertNull($this->codes()->consume(new OrganizationId(self::BETA_BAKERY), $code->codeHash(), $this->at('+5 seconds')));

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
    public function aConsumeInsideAnOpenTransactionIsRefusedAndChangesNothing(): void
    {
        $code = $this->issue(self::ACME_FOODS);
        $this->codes()->add($code);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->connection()->beginTransaction();
        try {
            $this->codes()->consume(new OrganizationId(self::ACME_FOODS), $code->codeHash(), $this->at('+5 seconds'));
            self::fail('The consume ran inside an open transaction.');
        } catch (TenantSwitchRefused) {
            self::assertSame(self::ACME_FOODS, $this->tenantContext()->get()?->toString());
        } finally {
            $this->connection()->rollBack();
            $this->tenantContext()->reset();
        }

        self::assertNull($this->rowOf(self::ACME_FOODS, $code->id())['used_at']);
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
    public function theResolverGivesTheOrganizationOfALiveCodeOnly(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-01-01 09:00:00+00')",
            [self::ACME_FOODS, self::OMAR],
        ));
        // Ahead of the database clock, which the resolver reads.
        $live = $this->issue(self::ACME_FOODS, '2099-01-01 10:00:00 UTC');
        $expired = $this->issue(self::ACME_FOODS, '2020-01-01 10:00:00 UTC');
        $this->codes()->add($live);
        $this->codes()->add($expired);

        self::assertSame(self::ACME_FOODS, $this->codes()->organizationOfLiveCode($live->codeHash())?->toString());
        self::assertNull($this->codes()->organizationOfLiveCode($expired->codeHash()));
        self::assertNull($this->codes()->organizationOfLiveCode(hash('sha256', 'no code has this hash', true)));
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
