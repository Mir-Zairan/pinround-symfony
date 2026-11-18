<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Application;

use App\Identity\Application\IssueApiToken;
use App\Identity\Application\TooManyApiTokens;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\InvalidApiToken;
use App\Identity\Domain\IssuedApiToken;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Identity\Application\RecordingIdentityEvents;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * IssueApiToken on the real idn_api_token table as pinround_app, with tokens committed in two organizations and removed
 * through the migrator. The clock is fixed in 2099: the token that expired by then still works by the database clock,
 * so a count that read the database clock instead of the given time would count it.
 */
final class IssueApiTokenTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000da1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000db1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000dd1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000dd2';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string NOW = '2099-03-15 10:00:00 UTC';
    private const string LOCK_TIMEOUT = '300ms';

    protected function setUp(): void
    {
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        $now = new \DateTimeImmutable(self::NOW);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($now): void {
            for ($i = 0; $i < IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 2; ++$i) {
                $this->seed(self::ACME_FOODS, self::OMAR, $now->modify(\sprintf('-%d hours', $i + 1)));
            }
            $revoked = $this->seed(self::ACME_FOODS, self::OMAR, $now->modify('-2 days'));
            $this->tokens()->revoke($revoked, $now->modify('-1 day'));
            // Expired before now by the given clock, but not by the database's.
            $this->seed(self::ACME_FOODS, self::OMAR, $now->modify('-10 days'), 1);
            // Expires at now exactly: the token works until just before.
            $this->seed(self::ACME_FOODS, self::OMAR, $now->modify('-1 day'), 1);
            $this->seed(self::ACME_FOODS, self::LENA, $now->modify('-1 hour'));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): string => $this->seed(self::BETA_BAKERY, self::OMAR, $now->modify('-1 hour')));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function revokedExpiredAndOtherPeoplesOrOrganizationsTokensDoNotCountTowardsTheCap(): void
    {
        self::assertSame(IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 2, $this->liveTokensOfOmarAtAcme());
        self::assertSame(IssueApiToken::MAX_LIVE_TOKENS_PER_USER + 1, $this->rowCountOfOmarAtAcme());

        $issued = $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Claude Code'));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Browser plugin'));

        self::assertSame(IssueApiToken::MAX_LIVE_TOKENS_PER_USER, $this->liveTokensOfOmarAtAcme());
        self::assertSame(
            ['name' => 'Claude Code', 'user_id' => self::OMAR, 'scopes' => '{read,rounds:write}', 'expires_at' => '2099-04-14 10:00:00'],
            $this->asAcmeFoods(fn (): array|false => $this->connection()->fetchAssociative(
                "SELECT name, user_id::text AS user_id, scopes::text AS scopes, to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at FROM idn_api_token WHERE id = ?",
                [$issued->token()->id()],
            )),
        );
    }

    #[Test]
    public function theTwentyFirstLiveTokenIsRefusedAndNoRowIsAdded(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Claude Code'));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Browser plugin'));
        $rowsAtTheCap = $this->idsOfOmarAtAcme();

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('One too many'));
            self::fail('A 21st live token was issued.');
        } catch (TooManyApiTokens $refusal) {
            self::assertSame(20, $refusal->maxLiveTokens);
            self::assertSame('You have 20 tokens that still work. Revoke one before you make another.', $refusal->getMessage());
        }

        self::assertSame(20, $this->liveTokensOfOmarAtAcme());
        self::assertSame($rowsAtTheCap, $this->idsOfOmarAtAcme());
    }

    #[Test]
    public function aRevokedTokenFreesItsPlaceUnderTheCap(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Claude Code'));
        $toRevoke = $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Browser plugin'))->token()->id();
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->tokens()->revoke($toRevoke, new \DateTimeImmutable(self::NOW)));

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('Laptop'));

        self::assertSame(20, $this->liveTokensOfOmarAtAcme());
    }

    /**
     * Two requests of one person must not both count before either inserts, so the issue waits on that person's lock;
     * here a second connection holds it, and lock_timeout ends the wait instead of the test hanging.
     */
    #[Test]
    public function issuingWaitsWhileAnotherRequestOfThePersonHoldsTheLockAndAddsNothing(): void
    {
        $idsBefore = $this->idsOfOmarAtAcme();
        self::assertNotSame([], $idsBefore);

        $this->holdingIssuingLockOf(self::ACME_FOODS, self::OMAR, function (): void {
            try {
                $this->transactions()->committedAs(self::ACME_FOODS, function (): IssuedApiToken {
                    $this->connection()->executeStatement(\sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

                    return $this->issueForOmar('Claude Code');
                });
                self::fail('The issue did not wait for the lock another request of Omar holds.');
            } catch (DriverException $timeout) {
                self::assertSame('55P03', $timeout->getSQLState());
                self::assertStringContainsString('canceling statement due to lock timeout', $timeout->getMessage());
            }
        });

        self::assertSame($idsBefore, $this->idsOfOmarAtAcme());
    }

    /** The lock is per person and organization: Lena's, or Omar's in Beta Bakery, does not hold up Omar in Acme Foods. */
    #[Test]
    public function anotherPersonsOrOrganizationsLockDoesNotHoldUpTheIssue(): void
    {
        $this->holdingIssuingLockOf(self::ACME_FOODS, self::LENA, function (): void {
            $this->holdingIssuingLockOf(self::BETA_BAKERY, self::OMAR, function (): void {
                $this->transactions()->committedAs(self::ACME_FOODS, function (): IssuedApiToken {
                    $this->connection()->executeStatement(\sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

                    return $this->issueForOmar('Claude Code');
                });
            });
        });

        self::assertSame(IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 1, $this->liveTokensOfOmarAtAcme());
    }

    #[Test]
    public function aTokenForAnotherOrganizationThanTheTenantIsRefusedAndNothingIsAdded(): void
    {
        $idsBefore = $this->idsOfOmarAtAcme();

        try {
            $this->transactions()->committedAs(self::BETA_BAKERY, fn (): IssuedApiToken => $this->issueForOmar('Claude Code'));
            self::fail('A token was issued for another organization than the tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('A token is issued under its own organization as tenant.', $refusal->getMessage());
        }

        self::assertSame($idsBefore, $this->idsOfOmarAtAcme());
    }

    #[Test]
    public function withoutAnOpenTransactionTheIssueIsRefusedAndNothingIsAdded(): void
    {
        $idsBefore = $this->idsOfOmarAtAcme();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        try {
            $this->issueForOmar('Claude Code');
            self::fail('A token was issued outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Issuing a token needs an open transaction; none is open.', $refusal->getMessage());
        } finally {
            $tenantContext->clear();
        }

        self::assertSame($idsBefore, $this->idsOfOmarAtAcme());
    }

    #[Test]
    public function anInvalidTokenIsRefusedBeforeTheCapAndNothingIsAdded(): void
    {
        $idsBefore = $this->idsOfOmarAtAcme();

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): IssuedApiToken => $this->issueForOmar('   '));
            self::fail('A token without a name was issued.');
        } catch (InvalidApiToken $refusal) {
            self::assertSame('Give the token a name.', $refusal->getMessage());
        }

        self::assertSame($idsBefore, $this->idsOfOmarAtAcme());
    }

    private function issueForOmar(string $name): IssuedApiToken
    {
        $issue = new IssueApiToken($this->tokens(), new HmacApiTokenHashing(self::PEPPER), new RecordingIdentityEvents());

        return $issue(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $name, ApiTokenScope::claudeCodeDefaults(), 30, new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return string the new token's id
     */
    private function seed(string $organizationId, string $userId, \DateTimeImmutable $issuedAt, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): string
    {
        $tokens = $this->tokens();
        $issued = ApiToken::issue($tokens->nextIdentity(), new OrganizationId($organizationId), new UserId($userId), 'Seeded', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), $issuedAt, $lifetimeDays);
        $tokens->add($issued->token());

        return $issued->token()->id();
    }

    private function liveTokensOfOmarAtAcme(): int
    {
        return $this->asAcmeFoods(fn (): int => $this->tokens()->countLiveOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));
    }

    private function rowCountOfOmarAtAcme(): int
    {
        return \count($this->idsOfOmarAtAcme());
    }

    /**
     * @return list<mixed>
     */
    private function idsOfOmarAtAcme(): array
    {
        return $this->asAcmeFoods(fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM idn_api_token WHERE user_id = ? ORDER BY id', [self::OMAR]));
    }

    /**
     * Runs $work while the migrator's own connection holds the advisory lock IssueApiToken takes for $userId in
     * $organizationId, as another request of that person would.
     */
    private function holdingIssuingLockOf(string $organizationId, string $userId, \Closure $work): void
    {
        $lockKey = \sprintf(DoctrineApiTokens::ISSUING_LOCK_KEY_FORMAT, $organizationId, $userId);
        self::assertTrue($this->migrator()->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$lockKey]), 'The lock was already held.');
        try {
            $work();
        } finally {
            $this->migrator()->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
        }
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
                $this->migrator()->transactional(
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

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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
