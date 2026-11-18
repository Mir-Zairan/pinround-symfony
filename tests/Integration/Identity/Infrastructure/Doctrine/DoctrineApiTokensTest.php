<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineApiTokens on the real idn_api_token table as pinround_app. The tokens are committed in two organizations, as
 * the resolver and the tenant checks need rows another transaction can see, and removed through the migrator. Every
 * time is fixed; the live tokens are dated ahead of the database clock that resolve_api_token reads.
 */
final class DoctrineApiTokensTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000ca1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000cb1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000cd1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000cd2';
    private const string OMAR_LIVE = '0197a3c0-0000-7000-8000-000000000ce1';
    private const string OMAR_SAME_SECOND = '0197a3c0-0000-7000-8000-000000000ce2';
    private const string OMAR_OLDER = '0197a3c0-0000-7000-8000-000000000ce3';
    private const string OMAR_EXPIRED = '0197a3c0-0000-7000-8000-000000000ce4';
    private const string LENA_AT_ACME = '0197a3c0-0000-7000-8000-000000000ce5';
    private const string OMAR_AT_BETA = '0197a3c0-0000-7000-8000-000000000ce6';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string ISSUED_AT = '2099-03-15 10:00:00 UTC';

    /** @var array<string, string> token id => the plain token, shown once at issue */
    private array $plainTokens = [];

    protected function setUp(): void
    {
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        // Added out of order, so the list order cannot come from the insert order.
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            $this->add(self::OMAR_SAME_SECOND, self::ACME_FOODS, self::OMAR, $issuedAt);
            $this->add(self::OMAR_LIVE, self::ACME_FOODS, self::OMAR, $issuedAt);
            $this->add(self::LENA_AT_ACME, self::ACME_FOODS, self::LENA, $issuedAt->modify('+1 day'));
            $this->add(self::OMAR_EXPIRED, self::ACME_FOODS, self::OMAR, new \DateTimeImmutable('2020-01-01 10:00:00 UTC'), 1);
            $this->add(self::OMAR_OLDER, self::ACME_FOODS, self::OMAR, $issuedAt->modify('-1 day'));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->add(self::OMAR_AT_BETA, self::BETA_BAKERY, self::OMAR, $issuedAt->modify('+2 days')));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theRowHoldsThePepperedHashAndNeverThePlainToken(): void
    {
        $plainToken = $this->plainTokens[self::OMAR_LIVE];

        $row = $this->asAcmeFoods(fn (): array|false => $this->connection()->fetchAssociative(
            "SELECT token_hash, prefix, scopes::text AS scopes, user_id::text AS user_id, name,
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at, last_used_at, revoked_at,
                    row_to_json(t)::text AS every_column
             FROM idn_api_token t WHERE id = ?",
            [self::OMAR_LIVE],
        ));

        self::assertIsArray($row);
        $storedHash = \is_resource($row['token_hash']) ? stream_get_contents($row['token_hash']) : $row['token_hash'];
        self::assertSame(hash_hmac('sha256', $plainToken, self::PEPPER, true), $storedHash);
        self::assertSame(substr($plainToken, 0, 11), $row['prefix']);
        self::assertSame('{read,rounds:write}', $row['scopes']);
        self::assertSame(self::OMAR, $row['user_id']);
        self::assertSame('Claude Code', $row['name']);
        self::assertSame('2099-06-13 10:00:00', $row['expires_at']);
        self::assertNull($row['last_used_at']);
        self::assertNull($row['revoked_at']);
        self::assertIsString($row['every_column']);
        self::assertStringNotContainsString(substr($plainToken, 3), $row['every_column']);
    }

    #[Test]
    public function aTokenIsFoundByIdOnlyForItsOwnerInItsOrganization(): void
    {
        $this->asAcmeFoods(function (): void {
            $tokens = $this->tokens();
            $found = $tokens->byIdForOwner(self::OMAR_LIVE, new UserId(self::OMAR));

            self::assertNotNull($found);
            self::assertSame(self::OMAR_LIVE, $found->id());
            self::assertSame($this->hashOf(self::OMAR_LIVE), $found->tokenHash());
            self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $found->scopes());
            self::assertSame(self::OMAR_LIVE, $tokens->byIdForOwner(strtoupper(self::OMAR_LIVE), new UserId(self::OMAR))?->id());
            self::assertNull($tokens->byIdForOwner(self::OMAR_LIVE, new UserId(self::LENA)));
            self::assertNull($tokens->byIdForOwner(self::OMAR_AT_BETA, new UserId(self::OMAR)));
            self::assertNull($tokens->byIdForOwner('0197a3c0-0000-7000-8000-000000000cff', new UserId(self::OMAR)));
            self::assertNull($tokens->byIdForOwner('not-a-token-id', new UserId(self::OMAR)));
        });
    }

    #[Test]
    public function theUsersTokensOfTheOrganizationAreListedNewestFirstWithTheLaterIdFirstInOneSecond(): void
    {
        $listed = $this->asAcmeFoods(fn (): array => array_map(
            static fn (ApiToken $token): string => $token->id(),
            $this->tokens()->forUser(new UserId(self::OMAR)),
        ));

        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER, self::OMAR_EXPIRED], $listed);
    }

    #[Test]
    public function aSecondRevokeChangesNothingAndTheFirstTimeStays(): void
    {
        [$first, $second, $revokedAt, $revokedCount] = $this->asAcmeFoods(fn (): array => [
            $this->tokens()->revoke(self::OMAR_LIVE, new \DateTimeImmutable('2099-03-20 09:00:00 UTC')),
            $this->tokens()->revoke(self::OMAR_LIVE, new \DateTimeImmutable('2099-03-21 09:00:00 UTC')),
            $this->utcTextOf('revoked_at', self::OMAR_LIVE),
            $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token WHERE revoked_at IS NOT NULL'),
        ]);

        self::assertTrue($first);
        self::assertFalse($second);
        self::assertSame('2099-03-20 09:00:00', $revokedAt);
        self::assertSame(1, $revokedCount);
    }

    #[Test]
    public function anotherOrganizationsTokenIsNeitherRevokedNorMarkedUsed(): void
    {
        $at = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');

        self::assertSame([false, false], $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            $this->tokens()->revoke(self::OMAR_AT_BETA, $at),
            $this->tokens()->markUsed(self::OMAR_AT_BETA, $at),
        ]));
        self::assertSame(
            ['revoked_at' => null, 'last_used_at' => null],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array|false => $this->connection()->fetchAssociative(
                'SELECT revoked_at, last_used_at FROM idn_api_token WHERE id = ?',
                [self::OMAR_AT_BETA],
            )),
        );
    }

    #[Test]
    public function anUnknownOrMalformedIdIsNeitherRevokedNorMarkedUsed(): void
    {
        $at = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');

        self::assertSame([false, false, false, false, 0], $this->asAcmeFoods(fn (): array => [
            $this->tokens()->revoke('0197a3c0-0000-7000-8000-000000000cff', $at),
            $this->tokens()->markUsed('0197a3c0-0000-7000-8000-000000000cff', $at),
            $this->tokens()->revoke("not-a-token-id'", $at),
            $this->tokens()->markUsed("not-a-token-id'", $at),
            $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token WHERE revoked_at IS NOT NULL OR last_used_at IS NOT NULL'),
        ]));
    }

    #[Test]
    public function aUseIsWrittenAtMostOnceAMinuteAndAFractionOfASecondIsCutNotRounded(): void
    {
        $firstUse = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');

        $outcomes = $this->asAcmeFoods(fn (): array => [
            'first use' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            '59 seconds later' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse->modify('+59 seconds')), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            'a minute later' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse->modify('+60 seconds')), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            '61.9 seconds later' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse->modify('+61 seconds +900 milliseconds')), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            'the other token' => [null, $this->utcTextOf('last_used_at', self::OMAR_SAME_SECOND)],
        ]);

        self::assertSame(
            [
                'first use' => [true, '2099-03-20 09:00:00'],
                '59 seconds later' => [false, '2099-03-20 09:00:00'],
                'a minute later' => [false, '2099-03-20 09:00:00'],
                '61.9 seconds later' => [true, '2099-03-20 09:01:01'],
                'the other token' => [null, null],
            ],
            $outcomes,
        );
    }

    #[Test]
    public function withoutATenantRevokeAndMarkUsedRefuseAndChangeNothing(): void
    {
        $at = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');
        $refusals = [];
        $calls = [
            'revoke' => fn (): bool => $this->tokens()->revoke(self::OMAR_LIVE, $at),
            'markUsed' => fn (): bool => $this->tokens()->markUsed(self::OMAR_LIVE, $at),
        ];
        foreach ($calls as $method => $call) {
            try {
                $call();
            } catch (TenantNotSet) {
                $refusals[] = $method;
            }
        }

        self::assertSame(['revoke', 'markUsed'], $refusals);
        self::assertSame(
            ['revoked_at' => null, 'last_used_at' => null],
            $this->asAcmeFoods(fn (): array|false => $this->connection()->fetchAssociative('SELECT revoked_at, last_used_at FROM idn_api_token WHERE id = ?', [self::OMAR_LIVE])),
        );
    }

    #[Test]
    public function aLiveTokenIsResolvedToItsIdsBeforeAnyTenantAndOthersAreNot(): void
    {
        $owner = $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_AT_BETA));

        self::assertNotNull($owner);
        self::assertSame(self::OMAR_AT_BETA, $owner->tokenId);
        self::assertSame(self::BETA_BAKERY, $owner->organizationId->toString());
        self::assertSame(self::OMAR, $owner->userId->toString());
        self::assertSame(self::OMAR_LIVE, $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_LIVE))?->tokenId);
        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_EXPIRED)));
        self::assertNull($this->tokens()->ownerOfLiveToken(hash('sha256', $this->plainTokens[self::OMAR_LIVE], true)));
        self::assertNull($this->tokens()->ownerOfLiveToken(new HmacApiTokenHashing(self::PEPPER.'-other')->hash($this->plainTokens[self::OMAR_LIVE])));
    }

    #[Test]
    public function aRevokedTokenIsNoLongerResolved(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->tokens()->revoke(self::OMAR_LIVE, new \DateTimeImmutable('2099-03-20 09:00:00 UTC')));

        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_LIVE)));
        self::assertSame(self::OMAR_SAME_SECOND, $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_SAME_SECOND))?->tokenId);
    }

    #[Test]
    public function eachOrganizationSeesOnlyItsOwnTokenRowsAndNoneWithoutATenant(): void
    {
        $visibleTokens = fn (string $organizationId): array => $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM idn_api_token ORDER BY id'),
        );

        self::assertSame([self::OMAR_LIVE, self::OMAR_SAME_SECOND, self::OMAR_OLDER, self::OMAR_EXPIRED, self::LENA_AT_ACME], $visibleTokens(self::ACME_FOODS));
        self::assertSame([self::OMAR_AT_BETA], $visibleTokens(self::BETA_BAKERY));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token'));
    }

    /**
     * The app may change only the revocation and the last use, so a token's scopes, owner and expiry stay as issued.
     */
    #[Test]
    public function theAppCannotChangeWhatATokenMayDoOrHowLongItLasts(): void
    {
        foreach (["scopes = '{read,tickets:move}'", "expires_at = expires_at + interval '1 year'", 'user_id = organization_id', "name = 'renamed'"] as $change) {
            try {
                $this->asAcmeFoods(fn (): int|string => $this->connection()->executeStatement('UPDATE idn_api_token SET '.$change.' WHERE id = ?', [self::OMAR_LIVE]));
                self::fail('The app changed a token: '.$change);
            } catch (DriverException $refusal) {
                self::assertSame('42501', $refusal->getSQLState(), $change);
                self::assertStringContainsString('permission denied for table idn_api_token', $refusal->getMessage(), $change);
            }
        }
    }

    private function add(string $id, string $organizationId, string $userId, \DateTimeImmutable $issuedAt, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): void
    {
        $issued = ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), 'Claude Code', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), $issuedAt, $lifetimeDays);
        $this->tokens()->add($issued->token());
        $this->plainTokens[$id] = $issued->reveal();
    }

    private function hashOf(string $tokenId): string
    {
        return new HmacApiTokenHashing(self::PEPPER)->hash($this->plainTokens[$tokenId]);
    }

    private function utcTextOf(string $column, string $tokenId): mixed
    {
        return $this->connection()->fetchOne(
            \sprintf("SELECT to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_api_token WHERE id = ?", $column),
            [$tokenId],
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function asAcmeFoods(\Closure $work): mixed
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, $work);
    }

    /**
     * Through the migrator, which owns the table and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeTokensOf(string ...$organizationIds): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ($organizationIds as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(
                    static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM idn_api_token WHERE organization_id = ?', [$organizationId]),
                );
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function tokens(): DoctrineApiTokens
    {
        return new DoctrineApiTokens($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }
}
