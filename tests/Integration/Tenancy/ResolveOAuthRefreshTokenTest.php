<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Infrastructure\Doctrine\DoctrineOAuthRefreshTokens;
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
 * resolve_oauth_refresh_token on real idn_oauth_refresh_token rows, called as pinround_app before any tenant is known,
 * as the token endpoint does (data-model.md › Lookups before the tenant is known). The database clock decides expiry,
 * so the live tokens are issued far ahead and the expired one far back. Omar is a member of Acme Foods and Mia of Beta
 * Bakery, so only the revocation, the expiry or the missing membership refuses the other tokens. A used token is still
 * found: only then can its reuse be told from an unknown token.
 */
final class ResolveOAuthRefreshTokenTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000001f0a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000001f0b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000001f0c1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000001f0c2';
    private const string LIVE_AT_ACME = 'live at Acme Foods';
    private const string LIVE_AT_BETA = 'live at Beta Bakery';
    private const string USED = 'used';
    private const string REVOKED = 'revoked';
    private const string EXPIRED = 'expired';
    private const string OF_A_NON_MEMBER = 'of a person who is not a member';
    private const string LIVE_ISSUED_AT = '2099-01-01 10:00:00 UTC';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string CLIENT_ID = 'abcdefghijklmnopqrstuvwxyz012345';

    /** @var array<string, OAuthRefreshToken> token name => the token */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->addMembership(self::ACME_FOODS, self::OMAR));
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): int|string => $this->addMembership(self::BETA_BAKERY, self::MIA));
        $this->add(self::LIVE_AT_ACME, self::ACME_FOODS, self::OMAR, self::LIVE_ISSUED_AT);
        $this->add(self::USED, self::ACME_FOODS, self::OMAR, self::LIVE_ISSUED_AT);
        self::assertNotNull($this->transactions()->committedAs(
            self::ACME_FOODS,
            fn (): ?OAuthRefreshToken => $this->refreshTokens()->markUsed($this->tokens[self::USED]->tokenHash(), self::CLIENT_ID, new \DateTimeImmutable('2099-01-01 10:00:05 UTC')),
        ));
        $this->add(self::REVOKED, self::ACME_FOODS, self::OMAR, self::LIVE_ISSUED_AT);
        $this->transactions()->committedAs(
            self::ACME_FOODS,
            fn (): array => $this->refreshTokens()->revokeFamily($this->tokens[self::REVOKED]->familyId(), new \DateTimeImmutable('2099-01-01 10:00:05 UTC')),
        );
        $this->add(self::EXPIRED, self::ACME_FOODS, self::OMAR, '2020-01-01 10:00:00 UTC');
        $this->add(self::LIVE_AT_BETA, self::BETA_BAKERY, self::MIA, self::LIVE_ISSUED_AT);
        $this->add(self::OF_A_NON_MEMBER, self::BETA_BAKERY, self::OMAR, self::LIVE_ISSUED_AT);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aLiveTokenIsFoundByItsHashInEveryOrganization(): void
    {
        self::assertSame([['organization_id' => self::ACME_FOODS]], $this->resolve(self::LIVE_AT_ACME));
        self::assertSame([['organization_id' => self::BETA_BAKERY]], $this->resolve(self::LIVE_AT_BETA));
    }

    #[Test]
    public function aUsedTokenIsStillFoundSoItsReuseCanRevokeItsFamily(): void
    {
        self::assertSame([['organization_id' => self::ACME_FOODS]], $this->resolve(self::USED));
    }

    #[Test]
    public function anUnknownHashGetsNoRow(): void
    {
        self::assertSame([], $this->resolveHash(hash('sha256', 'no refresh token has this hash', true)));
    }

    #[Test]
    public function aRevokedTokenGetsNoRow(): void
    {
        self::assertSame([], $this->resolve(self::REVOKED));
    }

    #[Test]
    public function anExpiredTokenGetsNoRow(): void
    {
        self::assertSame([], $this->resolve(self::EXPIRED));
    }

    #[Test]
    public function aTokenOfAPersonWhoIsNotAMemberOfItsOrganizationGetsNoRow(): void
    {
        self::assertSame([], $this->resolve(self::OF_A_NON_MEMBER));
    }

    #[Test]
    public function theTokenOfAPersonWhoLeftItsOrganizationGetsNoRow(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [self::ACME_FOODS, self::OMAR],
        ));

        self::assertSame([], $this->resolve(self::LIVE_AT_ACME));
        self::assertSame([], $this->resolve(self::USED));
        self::assertSame([['organization_id' => self::BETA_BAKERY]], $this->resolve(self::LIVE_AT_BETA));
    }

    #[Test]
    public function theRepositoryGivesTheResolvedOrganizationOrNull(): void
    {
        self::assertSame(self::ACME_FOODS, $this->refreshTokens()->organizationOfToken($this->tokens[self::LIVE_AT_ACME]->tokenHash())?->toString());
        self::assertSame(self::BETA_BAKERY, $this->refreshTokens()->organizationOfToken($this->tokens[self::LIVE_AT_BETA]->tokenHash())?->toString());
        self::assertNull($this->refreshTokens()->organizationOfToken($this->tokens[self::REVOKED]->tokenHash()));
        self::assertNull($this->refreshTokens()->organizationOfToken(hash('sha256', 'no refresh token has this hash', true)));
    }

    #[Test]
    public function theAppRoleCannotReadTheTokensWithoutATenant(): void
    {
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_refresh_token'));
    }

    #[Test]
    public function theReadonlyRoleIsRefusedEvenForALiveToken(): void
    {
        $readonly = self::getContainer()->get('doctrine.dbal.readonly_connection');
        self::assertInstanceOf(Connection::class, $readonly);
        self::assertSame('pinround_readonly', $readonly->fetchOne('SELECT current_user'));

        try {
            $readonly->fetchAllAssociative('SELECT * FROM resolve_oauth_refresh_token(?)', [$this->tokens[self::LIVE_AT_ACME]->tokenHash()], [ParameterType::BINARY]);
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('permission denied for function resolve_oauth_refresh_token', $refusal->getMessage());

            return;
        }

        self::fail('pinround_readonly could execute resolve_oauth_refresh_token.');
    }

    private function add(string $name, string $organizationId, string $userId, string $issuedAt): void
    {
        $token = OAuthRefreshToken::issue(
            $this->refreshTokens()->nextIdentity(),
            $this->refreshTokens()->nextIdentity(),
            new OrganizationId($organizationId),
            new UserId($userId),
            self::CLIENT_ID,
            [ApiTokenScope::Read],
            $this->refreshTokens()->nextIdentity(),
            new HmacApiTokenHashing(self::PEPPER),
            new \DateTimeImmutable($issuedAt),
        )->token();
        $this->transactions()->committedAs($organizationId, fn () => $this->refreshTokens()->add($token));
        $this->tokens[$name] = $token;
    }

    private function addMembership(string $organizationId, string $userId): int|string
    {
        return $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2020-01-01 09:00:00+00')",
            [$organizationId, $userId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolve(string $tokenName): array
    {
        return $this->resolveHash($this->tokens[$tokenName]->tokenHash());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveHash(string $tokenHash): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT organization_id FROM resolve_oauth_refresh_token(?)',
            [$tokenHash],
            [ParameterType::BINARY],
        );
    }

    /**
     * Through the migrator, which owns the tables and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeRowsOf(string ...$organizationIds): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ($organizationIds as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM idn_oauth_refresh_token WHERE organization_id = ?', [$organizationId]);
                    $migrator->executeStatement('DELETE FROM org_membership WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function refreshTokens(): DoctrineOAuthRefreshTokens
    {
        return new DoctrineOAuthRefreshTokens(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
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
