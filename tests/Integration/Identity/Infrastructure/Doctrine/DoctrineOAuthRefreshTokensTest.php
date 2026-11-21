<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\Identity\Infrastructure\Doctrine\DoctrineOAuthRefreshTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Identity\WaitsForWaitingChanges;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * DoctrineOAuthRefreshTokens on the real idn_oauth_refresh_token table as pinround_app, each call in its own committed
 * transaction under a tenant, as the token endpoint runs it. Every time is fixed: markUsed() and revokeFamily() write
 * and compare the time they are given; only organizationOfToken() reads the database clock (ResolveOAuthRefreshTokenTest).
 */
final class DoctrineOAuthRefreshTokensTest extends KernelTestCase
{
    use WaitsForWaitingChanges;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001fa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001fb1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000001fc1';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string ISSUED_AT = '2027-03-15 10:00:00 UTC';
    private const string CLIENT_ID = 'abcdefghijklmnopqrstuvwxyz012345';
    private const string OTHER_CLIENT_ID = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ-_6789';

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
    public function anAddedTokenIsSavedWithItsHashOnlyAndLivesThirtyDays(): void
    {
        $issued = OAuthRefreshToken::issue(
            $this->refreshTokens()->nextIdentity(),
            $familyId = $this->refreshTokens()->nextIdentity(),
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            self::CLIENT_ID,
            [ApiTokenScope::RoundsWrite, ApiTokenScope::Read],
            $accessTokenId = $this->refreshTokens()->nextIdentity(),
            new HmacApiTokenHashing(self::PEPPER),
            new \DateTimeImmutable(self::ISSUED_AT),
        );
        $token = $issued->token();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->refreshTokens()->add($token));

        self::assertSame(
            [
                'id' => $token->id(),
                'family_id' => $familyId,
                'user_id' => self::OMAR,
                'client_id' => self::CLIENT_ID,
                'scopes' => '["read","rounds:write"]',
                'token_hash' => bin2hex(new HmacApiTokenHashing(self::PEPPER)->hash($issued->reveal())),
                'access_token_id' => $accessTokenId,
                'created_at' => '2027-03-15 10:00:00',
                'expires_at' => '2027-04-14 10:00:00',
                'used_at' => null,
                'revoked_at' => null,
            ],
            $this->rowOf(self::ACME_FOODS, $token->id()),
        );
        self::assertSame(0, $this->countAs(self::BETA_BAKERY));
    }

    #[Test]
    public function aTokenIsNotAddedUnderAnotherOrganization(): void
    {
        $token = $this->issue(self::ACME_FOODS);
        // Outside a transaction, as a refusal inside wrapInTransaction would close the entity manager for the checks below.
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));
        try {
            $this->refreshTokens()->add($token);
            self::fail('A token of Acme Foods was added under Beta Bakery.');
        } catch (\LogicException $refusal) {
            self::assertSame('A refresh token is saved under its own organization as tenant.', $refusal->getMessage());
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame(0, $this->countAs(self::ACME_FOODS));
        self::assertSame(0, $this->countAs(self::BETA_BAKERY));
    }

    #[Test]
    public function aTokenIsMarkedUsedOnceAndASecondMarkChangesNothing(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        $used = $this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour'));

        self::assertNotNull($used);
        self::assertSame($token->id(), $used->id());
        self::assertSame($token->familyId(), $used->familyId());
        self::assertSame($token->accessTokenId(), $used->accessTokenId());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $used->scopes());
        self::assertTrue($used->userId()->equals(new UserId(self::OMAR)));
        self::assertSame('2027-03-15 11:00:00', $used->usedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        self::assertNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+2 hours')));
        self::assertNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+30 minutes')));

        self::assertSame('2027-03-15 11:00:00', $this->rowOf(self::ACME_FOODS, $token->id())['used_at']);
    }

    #[Test]
    public function aTokenMarkedInTheSameTransactionTwiceIsMarkedOnlyOnceAndReadFresh(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        [$first, $second] = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($token): array {
            // Loaded before the mark, so a reload that trusted the identity map would give used_at null.
            self::assertNull($this->refreshTokens()->byHash($token->tokenHash())?->usedAt());

            return [
                $this->refreshTokens()->markUsed($token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour')),
                $this->refreshTokens()->markUsed($token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour')),
            ];
        });

        self::assertSame('2027-03-15 11:00:00', $first?->usedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertNull($second);
    }

    #[Test]
    public function aTokenIsNotMarkedForAnotherClientAndStaysUnused(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        self::assertNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::OTHER_CLIENT_ID, $this->at('+1 hour')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $token->id())['used_at']);
    }

    #[Test]
    public function aTokenIsNotMarkedFromTheSecondItExpiresAndStaysUnused(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        self::assertNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+30 days')));
        self::assertNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+31 days')));
        self::assertNotNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+30 days -1 second')));
    }

    #[Test]
    public function aRevokedTokenIsNotMarkedAndStaysUnused(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->refreshTokens()->revokeFamily($token->familyId(), $this->at('+1 minute')));

        self::assertNull($this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $token->id())['used_at']);
    }

    #[Test]
    public function anUnknownHashMarksNothing(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        self::assertNull($this->markUsedAs(self::ACME_FOODS, hash('sha256', 'no refresh token has this hash', true), self::CLIENT_ID, $this->at('+1 hour')));
        self::assertNull($this->markUsedAs(self::ACME_FOODS, '', self::CLIENT_ID, $this->at('+1 hour')));

        self::assertNull($this->rowOf(self::ACME_FOODS, $token->id())['used_at']);
    }

    #[Test]
    public function aTokenOfOneOrganizationIsNeitherSeenMarkedNorRevokedUnderAnother(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        self::assertNull($this->markUsedAs(self::BETA_BAKERY, $token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour')));
        self::assertSame([], $this->transactions()->committedAs(self::BETA_BAKERY, fn (): array => $this->refreshTokens()->revokeFamily($token->familyId(), $this->at('+1 hour'))));
        self::assertNull($this->transactions()->committedAs(self::BETA_BAKERY, fn (): ?OAuthRefreshToken => $this->refreshTokens()->byHash($token->tokenHash())));
        self::assertNull($this->transactions()->committedAs(self::BETA_BAKERY, fn (): ?OAuthRefreshToken => $this->refreshTokens()->byAccessTokenId($token->accessTokenId())));
        self::assertNull($this->transactions()->committedAs(
            self::BETA_BAKERY,
            fn (): ?OAuthRefreshToken => $this->entityManager()->find(OAuthRefreshToken::class, ['organizationId' => self::ACME_FOODS, 'id' => $token->id()]),
        ));

        self::assertSame(0, $this->countAs(self::BETA_BAKERY));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_refresh_token'));
        self::assertSame(
            ['used_at' => null, 'revoked_at' => null],
            array_intersect_key($this->rowOf(self::ACME_FOODS, $token->id()), ['used_at' => true, 'revoked_at' => true]),
        );
    }

    #[Test]
    public function aUsedOrRevokedTokenIsStillFoundByItsHashAndItsAccessToken(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));
        $this->markUsedAs(self::ACME_FOODS, $token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour'));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->refreshTokens()->revokeFamily($token->familyId(), $this->at('+2 hours')));

        [$byHash, $byAccessToken, $byUpperCaseAccessToken] = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            $this->refreshTokens()->byHash($token->tokenHash()),
            $this->refreshTokens()->byAccessTokenId($token->accessTokenId()),
            $this->refreshTokens()->byAccessTokenId(strtoupper($token->accessTokenId())),
        ]);

        self::assertSame($token->id(), $byHash?->id());
        self::assertSame('2027-03-15 12:00:00', $byHash->revokedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame($token->id(), $byAccessToken?->id());
        self::assertSame($token->id(), $byUpperCaseAccessToken?->id());
    }

    #[Test]
    public function anAccessTokenIdThatIsNotAUuidFindsNothing(): void
    {
        $this->add($this->issue(self::ACME_FOODS));

        self::assertNull($this->transactions()->committedAs(self::ACME_FOODS, fn (): ?OAuthRefreshToken => $this->refreshTokens()->byAccessTokenId('not-a-uuid')));
        self::assertNull($this->transactions()->committedAs(self::ACME_FOODS, fn (): ?OAuthRefreshToken => $this->refreshTokens()->byAccessTokenId('')));
    }

    #[Test]
    public function revokingAFamilyRevokesItsTokensOnceAndNamesOnlyTheUntradedAccessTokens(): void
    {
        $familyId = $this->refreshTokens()->nextIdentity();
        $traded = $this->add($this->issue(self::ACME_FOODS, $familyId));
        $this->markUsedAs(self::ACME_FOODS, $traded->tokenHash(), self::CLIENT_ID, $this->at('+1 hour'));
        $untraded = $this->add($this->issue(self::ACME_FOODS, $familyId, '+1 hour'));
        $otherFamily = $this->add($this->issue(self::ACME_FOODS));

        $revoked = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->refreshTokens()->revokeFamily($familyId, $this->at('+2 hours')));

        self::assertSame([$untraded->accessTokenId()], $revoked);
        self::assertSame('2027-03-15 12:00:00', $this->rowOf(self::ACME_FOODS, $traded->id())['revoked_at']);
        self::assertSame('2027-03-15 12:00:00', $this->rowOf(self::ACME_FOODS, $untraded->id())['revoked_at']);
        self::assertNull($this->rowOf(self::ACME_FOODS, $otherFamily->id())['revoked_at']);

        self::assertSame([], $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->refreshTokens()->revokeFamily($familyId, $this->at('+3 hours'))));
        self::assertSame('2027-03-15 12:00:00', $this->rowOf(self::ACME_FOODS, $untraded->id())['revoked_at']);
    }

    /**
     * A rotation and a revocation of one family on two app connections at once. The test holds the traded token's row
     * until the rotation waits on it, then until the revocation waits too, then lets go. Without the family lock the
     * revocation's UPDATE waited on the same row and, its snapshot older than the rotation's insert, left the new token
     * live; with it the revocation waits for the whole rotation and revokes both tokens.
     */
    #[Test]
    public function aFamilyRevokedWhileARotationAddsItsNextTokenKeepsNoTokenLive(): void
    {
        $traded = $this->add($this->issue(self::ACME_FOODS));
        $changes = [];
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->migrator()->beginTransaction();
        try {
            self::assertSame([$traded->id()], $this->migrator()->fetchFirstColumn('SELECT id FROM idn_oauth_refresh_token WHERE organization_id = ? AND id = ? FOR UPDATE', [self::ACME_FOODS, $traded->id()]));
            $changes['rotation'] = $this->startChange('rotate', bin2hex($traded->tokenHash()), self::CLIENT_ID, $this->at('+1 hour')->format(\DATE_ATOM));
            $this->waitUntilWaiting($this->connection(), 1, $changes);
            $changes['revocation'] = $this->startChange('revoke', $traded->familyId(), $this->at('+1 hour')->format(\DATE_ATOM));
            $this->waitUntilWaiting($this->connection(), 2, $changes);
        } finally {
            $this->migrator()->rollBack();
            $this->tenantContext()->reset();
        }

        $outcomes = [];
        foreach ($changes as $name => $change) {
            $change->wait();
            self::assertTrue($change->isSuccessful(), $name.': '.$change->getErrorOutput());
            $outcomes[$name] = $change->getOutput();
        }
        self::assertSame(['rotation' => 'won', 'revocation' => '1'], $outcomes);
        self::assertSame(
            [['used' => true, 'revoked' => true], ['used' => false, 'revoked' => true]],
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
                'SELECT used_at IS NOT NULL AS used, revoked_at IS NOT NULL AS revoked FROM idn_oauth_refresh_token'
                .' WHERE organization_id = ? AND family_id = ? ORDER BY used_at IS NULL, id',
                [self::ACME_FOODS, $traded->familyId()],
            )),
        );
    }

    #[Test]
    public function aFamilyIsChangedOnlyInAnOpenTransaction(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));
        $next = $this->issue(self::ACME_FOODS, $token->familyId(), '+1 hour');
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            foreach ([
                'add' => fn () => $this->refreshTokens()->add($next),
                'markUsed' => fn () => $this->refreshTokens()->markUsed($token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour')),
                'revokeFamily' => fn () => $this->refreshTokens()->revokeFamily($token->familyId(), $this->at('+1 hour')),
            ] as $call => $withoutTransaction) {
                try {
                    $withoutTransaction();
                    self::fail($call.' ran without a transaction.');
                } catch (\LogicException $refusal) {
                    self::assertSame('A refresh token family is changed only in an open transaction; none is open.', $refusal->getMessage(), $call);
                }
            }
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame(1, $this->countAs(self::ACME_FOODS));
        self::assertSame(['used_at' => null, 'revoked_at' => null], array_intersect_key($this->rowOf(self::ACME_FOODS, $token->id()), ['used_at' => true, 'revoked_at' => true]));
    }

    #[Test]
    public function everyCallButNextIdentityAndTheResolverNeedsATenant(): void
    {
        $token = $this->issue(self::ACME_FOODS);
        foreach ([
            'add' => fn () => $this->refreshTokens()->add($token),
            'markUsed' => fn () => $this->refreshTokens()->markUsed($token->tokenHash(), self::CLIENT_ID, $this->at('+1 hour')),
            'byHash' => fn () => $this->refreshTokens()->byHash($token->tokenHash()),
            'byAccessTokenId' => fn () => $this->refreshTokens()->byAccessTokenId($token->accessTokenId()),
            'revokeFamily' => fn () => $this->refreshTokens()->revokeFamily($token->familyId(), $this->at('+1 hour')),
        ] as $call => $withoutTenant) {
            try {
                $withoutTenant();
                self::fail($call.' ran without a tenant.');
            } catch (TenantNotSet) {
            }
        }

        self::assertSame(0, $this->countAs(self::ACME_FOODS));
    }

    #[Test]
    public function theAppMayChangeNoColumnButUsedAtAndRevokedAtAndMayNotDelete(): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));

        foreach ([
            "UPDATE idn_oauth_refresh_token SET expires_at = expires_at + interval '1 second' WHERE organization_id = ?",
            "UPDATE idn_oauth_refresh_token SET scopes = '{read,tickets:move}' WHERE organization_id = ?",
            'UPDATE idn_oauth_refresh_token SET family_id = id WHERE organization_id = ?',
            'DELETE FROM idn_oauth_refresh_token WHERE organization_id = ?',
        ] as $statement) {
            // On the connection, not through the entity manager, which a failed transaction would close.
            $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
            try {
                $this->connection()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement($statement, [self::ACME_FOODS]));
                self::fail('pinround_app ran: '.$statement);
            } catch (DriverException $refusal) {
                self::assertSame('42501', $refusal->getSQLState(), $statement);
                self::assertStringContainsString('permission denied for table idn_oauth_refresh_token', $refusal->getMessage());
            } finally {
                $this->tenantContext()->clear();
            }
        }

        self::assertSame(
            ['family_id' => $token->familyId(), 'scopes' => '["read","rounds:write"]', 'expires_at' => '2027-04-14 10:00:00'],
            array_intersect_key($this->rowOf(self::ACME_FOODS, $token->id()), ['family_id' => true, 'expires_at' => true, 'scopes' => true]),
        );
    }

    /**
     * @return iterable<string, array{string, string}> a column change that breaks one constraint, and that constraint
     */
    public static function rowsBreakingAConstraint(): iterable
    {
        yield 'a life of 30 days and a second' => ["expires_at = created_at + interval '30 days 1 second'", 'idn_oauth_refresh_token_expires_at_check'];
        yield 'an expiry at the issue' => ['expires_at = created_at', 'idn_oauth_refresh_token_expires_at_check'];
        yield 'a hash of 31 bytes' => ['token_hash = substring(token_hash from 2)', 'idn_oauth_refresh_token_token_hash_check'];
        yield 'a client_id of 31 characters' => ['client_id = substr(client_id, 2)', 'idn_oauth_refresh_token_client_id_check'];
        yield 'a client_id with a final newline' => ["client_id = substr(client_id, 2) || E'\\n'", 'idn_oauth_refresh_token_client_id_check'];
        yield 'no scope' => ["scopes = '{}'", 'idn_oauth_refresh_token_scopes_check'];
        yield 'an unknown scope' => ["scopes = '{read,admin}'", 'idn_oauth_refresh_token_scopes_check'];
    }

    /**
     * Through the migrator, which owns the table, so that a refusal can come from the constraint only.
     */
    #[Test]
    #[DataProvider('rowsBreakingAConstraint')]
    public function aRowBreakingAConstraintIsRefused(string $change, string $constraint): void
    {
        $token = $this->add($this->issue(self::ACME_FOODS));
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'UPDATE idn_oauth_refresh_token SET '.$change.' WHERE organization_id = ? AND id = ?',
                [self::ACME_FOODS, $token->id()],
            ));
            self::fail('The change was accepted: '.$change);
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString($constraint, $refusal->getMessage());
        } finally {
            $this->tenantContext()->reset();
        }

        self::assertSame(['client_id' => self::CLIENT_ID, 'expires_at' => '2027-04-14 10:00:00'], array_intersect_key($this->rowOf(self::ACME_FOODS, $token->id()), ['expires_at' => true, 'client_id' => true]));
    }

    private function issue(string $organizationId, ?string $familyId = null, string $afterIssue = '+0 seconds'): OAuthRefreshToken
    {
        return OAuthRefreshToken::issue(
            $this->refreshTokens()->nextIdentity(),
            $familyId ?? $this->refreshTokens()->nextIdentity(),
            new OrganizationId($organizationId),
            new UserId(self::OMAR),
            self::CLIENT_ID,
            [ApiTokenScope::RoundsWrite, ApiTokenScope::Read],
            $this->refreshTokens()->nextIdentity(),
            new HmacApiTokenHashing(self::PEPPER),
            $this->at($afterIssue),
        )->token();
    }

    private function add(OAuthRefreshToken $token): OAuthRefreshToken
    {
        $this->transactions()->committedAs($token->organizationId()->toString(), fn () => $this->refreshTokens()->add($token));

        return $token;
    }

    private function markUsedAs(string $organizationId, string $tokenHash, string $clientId, \DateTimeImmutable $at): ?OAuthRefreshToken
    {
        return $this->transactions()->committedAs($organizationId, fn (): ?OAuthRefreshToken => $this->refreshTokens()->markUsed($tokenHash, $clientId, $at));
    }

    private function startChange(string $change, string ...$arguments): Process
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/change-oauth-refresh-family.php', $change, self::ACME_FOODS, ...$arguments]);
        $process->start();

        return $process;
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
            "SELECT id, family_id, user_id, client_id, array_to_json(scopes)::text AS scopes, encode(token_hash, 'hex') AS token_hash, access_token_id,"
            ." to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at,"
            ." to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at,"
            ." to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS used_at,"
            ." to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS revoked_at"
            .' FROM idn_oauth_refresh_token WHERE organization_id = ? AND id = ?',
            [$organizationId, $id],
        ));
        self::assertIsArray($row, 'No refresh token row '.$id);

        return $row;
    }

    private function countAs(string $organizationId): mixed
    {
        return $this->transactions()->committedAs($organizationId, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_refresh_token'));
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
                    $migrator->executeStatement('DELETE FROM idn_oauth_refresh_token WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext()->clear();
            }
        }
    }

    private function refreshTokens(): OAuthRefreshTokens
    {
        $refreshTokens = self::getContainer()->get('test.identity_oauth_refresh_tokens');
        self::assertInstanceOf(DoctrineOAuthRefreshTokens::class, $refreshTokens);

        return $refreshTokens;
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
