<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Personal access tokens for functional tests, issued through the container's ApiTokens and hashing (so with the
 * pepper of .env.test) and committed, so the requests' own connections see them. Use it next to LogsInUsers, and call
 * removeApiTokens() in tearDown before LogsInUsers' own: it removes the tokens of the organizations that trait created.
 */
trait IssuesApiTokens
{
    /** In the future, so a token issued then is live by the database clock that resolve_api_token reads. */
    private const string LIVE_TOKEN_ISSUED_AT = '2099-03-15 10:00:00 UTC';

    /**
     * @param list<ApiTokenScope>|null $scopes null for the scopes a token made for Claude Code starts with
     *
     * @return array{string, string} the token's id and its plain text, shown once
     */
    private function issueApiToken(User $user, OrganizationId $organizationId, string $issuedAt = self::LIVE_TOKEN_ISSUED_AT, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS, ?array $scopes = null): array
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue(
            $this->apiTokens()->nextIdentity(),
            $organizationId,
            $user->id(),
            'Claude Code',
            $scopes ?? ApiTokenScope::claudeCodeDefaults(),
            $hashing,
            new \DateTimeImmutable($issuedAt),
            $lifetimeDays,
        );
        $this->inTokenTransactionAs($organizationId, fn () => $this->apiTokens()->add($issued->token()));

        return [$issued->token()->id(), $issued->reveal()];
    }

    private function revokeApiToken(string $tokenId, OrganizationId $organizationId): void
    {
        self::assertTrue($this->inTokenTransactionAs($organizationId, fn (): bool => $this->apiTokens()->revoke($tokenId, new \DateTimeImmutable('2099-03-16 10:00:00 UTC'))));
    }

    /**
     * @return array{last_used_at: string|null, revoked_at: string|null} in UTC, to the second
     */
    private function usageOf(string $tokenId, OrganizationId $organizationId): array
    {
        $usage = $this->inTokenTransactionAs($organizationId, fn (): array|false => $this->tokenEntityManager()->getConnection()->fetchAssociative(
            "SELECT to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS last_used_at,
                    to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS revoked_at
             FROM idn_api_token WHERE id = ?",
            [$tokenId],
        ));
        self::assertIsArray($usage, 'no token has the id '.$tokenId);
        self::assertTrue(null === $usage['last_used_at'] || \is_string($usage['last_used_at']));
        self::assertTrue(null === $usage['revoked_at'] || \is_string($usage['revoked_at']));

        return ['last_used_at' => $usage['last_used_at'], 'revoked_at' => $usage['revoked_at']];
    }

    /**
     * Through the migrator, which owns the table and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeApiTokens(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->asTokenTenant(new OrganizationId($organizationId), fn (): int|string => $this->tokenMigrator()->transactional(
                static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM idn_api_token WHERE organization_id = ?', [$organizationId]),
            ));
        }
    }

    /**
     * One committed transaction of the app's connection under $organizationId as tenant.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inTokenTransactionAs(OrganizationId $organizationId, \Closure $work): mixed
    {
        return $this->asTokenTenant($organizationId, fn (): mixed => $this->tokenEntityManager()->wrapInTransaction(static fn (): mixed => $work()));
    }

    /**
     * As LogsInUsers::committedAs: the tenant is reset, not switched or cleared, and left reset, as the switch guard
     * refuses while the entity manager holds the test's users.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function asTokenTenant(OrganizationId $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            return $work();
        } finally {
            $tenantContext->reset();
        }
    }

    private function apiTokens(): ApiTokens
    {
        $tokens = self::getContainer()->get('test.identity_api_tokens');
        \assert($tokens instanceof ApiTokens);

        return $tokens;
    }

    private function tokenEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tokenMigrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
