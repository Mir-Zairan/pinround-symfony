<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * resolve_api_token on real idn_api_token rows, called as pinround_app before any tenant is known, as the token
 * authenticator does (data-model.md › Lookups before the tenant is known). The database clock decides expiry, so the
 * live tokens are dated far ahead and the expired one far back. Omar is a member of Acme Foods and Mia of Beta Bakery,
 * so only the revocation or the expiry refuses the revoked and the expired token.
 */
final class ResolveApiTokenTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000da1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000db1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000dc1';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000dc2';
    private const string LIVE_AT_ACME = '0197a3c0-0000-7000-8000-000000000dd1';
    private const string LIVE_AT_BETA = '0197a3c0-0000-7000-8000-000000000dd2';
    private const string REVOKED = '0197a3c0-0000-7000-8000-000000000dd3';
    private const string EXPIRED = '0197a3c0-0000-7000-8000-000000000dd4';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';

    /** @var array<string, string> token id => its hash */
    private array $hashes = [];

    protected function setUp(): void
    {
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->removeMembershipsOf(self::ACME_FOODS, self::BETA_BAKERY);
        $future = new \DateTimeImmutable('2099-01-01 10:00:00 UTC');
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->addMembership(self::ACME_FOODS, self::OMAR));
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): int|string => $this->addMembership(self::BETA_BAKERY, self::MIA));
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($future): void {
            $this->add(self::LIVE_AT_ACME, self::ACME_FOODS, self::OMAR, $future);
            $this->add(self::REVOKED, self::ACME_FOODS, self::OMAR, $future);
            $this->tokens()->revoke(self::REVOKED, $future->modify('+1 hour'));
            $this->add(self::EXPIRED, self::ACME_FOODS, self::OMAR, new \DateTimeImmutable('2020-01-01 10:00:00 UTC'));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->add(self::LIVE_AT_BETA, self::BETA_BAKERY, self::MIA, $future));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->removeMembershipsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aLiveTokenIsFoundByItsHashInEveryOrganization(): void
    {
        self::assertSame([['token_id' => self::LIVE_AT_ACME, 'organization_id' => self::ACME_FOODS, 'user_id' => self::OMAR]], $this->resolve($this->hashes[self::LIVE_AT_ACME]));
        self::assertSame([['token_id' => self::LIVE_AT_BETA, 'organization_id' => self::BETA_BAKERY, 'user_id' => self::MIA]], $this->resolve($this->hashes[self::LIVE_AT_BETA]));
    }

    #[Test]
    public function anUnknownHashGetsNoRow(): void
    {
        self::assertSame([], $this->resolve(hash('sha256', 'no token has this hash', true)));
    }

    #[Test]
    public function aRevokedTokenGetsNoRow(): void
    {
        self::assertSame([], $this->resolve($this->hashes[self::REVOKED]));
    }

    #[Test]
    public function anExpiredTokenGetsNoRow(): void
    {
        self::assertSame([], $this->resolve($this->hashes[self::EXPIRED]));
    }

    #[Test]
    public function theTokenOfAPersonWhoLeftItsOrganizationGetsNoRow(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [self::ACME_FOODS, self::OMAR],
        ));

        self::assertSame([], $this->resolve($this->hashes[self::LIVE_AT_ACME]));
        self::assertSame([['token_id' => self::LIVE_AT_BETA, 'organization_id' => self::BETA_BAKERY, 'user_id' => self::MIA]], $this->resolve($this->hashes[self::LIVE_AT_BETA]));
    }

    #[Test]
    public function theAppRoleCannotReadTheTokensWithoutATenant(): void
    {
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token'));
    }

    #[Test]
    public function theReadonlyRoleIsRefusedEvenForALiveToken(): void
    {
        $readonly = self::getContainer()->get('doctrine.dbal.readonly_connection');
        self::assertInstanceOf(Connection::class, $readonly);
        self::assertSame('pinround_readonly', $readonly->fetchOne('SELECT current_user'));

        try {
            $readonly->fetchAllAssociative('SELECT * FROM resolve_api_token(?)', [$this->hashes[self::LIVE_AT_ACME]], [ParameterType::BINARY]);
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('permission denied for function resolve_api_token', $refusal->getMessage());

            return;
        }

        self::fail('pinround_readonly could execute resolve_api_token.');
    }

    private function add(string $id, string $organizationId, string $userId, \DateTimeImmutable $issuedAt): void
    {
        $issued = ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), 'Claude Code', [ApiTokenScope::Read], new HmacApiTokenHashing(self::PEPPER), $issuedAt);
        $this->tokens()->add($issued->token());
        $this->hashes[$id] = $issued->token()->tokenHash();
    }

    private function addMembership(string $organizationId, string $userId): int|string
    {
        return $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2099-01-01 09:00:00+00')",
            [$organizationId, $userId],
        );
    }

    private function removeMembershipsOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement('DELETE FROM org_membership WHERE organization_id = ?', [$organizationId]));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolve(string $tokenHash): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT token_id, organization_id, user_id FROM resolve_api_token(?)',
            [$tokenHash],
            [ParameterType::BINARY],
        );
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
        return new DoctrineApiTokens(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
