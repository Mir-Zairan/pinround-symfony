<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Doctrine;

use App\Identity\Api\OAuthConnection;
use App\Identity\Application\OAuthConnections;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\Identity\Infrastructure\Doctrine\DbalOAuthConnections;
use App\Identity\Infrastructure\Doctrine\DoctrineOAuthRefreshTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalOAuthConnections on the real idn_oauth_refresh_token and idn_oauth_client tables as pinround_app. Every family is
 * committed by DoctrineOAuthRefreshTokens and the time is fixed in March 2027: the lists compare expiry with the time
 * they are given, never the database clock.
 *
 * Omar's live families of Acme Foods, newest connected first: NEWEST, RENEWED (connected three days ago, renewed two
 * days ago), the tied TIE_HIGH and TIE_LOW, added lowest id first, and LAST_SECOND, which expires one second after now.
 * His revoked family, his family that expires at now, Lena's family and his family of Beta Bakery are seeded beside
 * them; Lena's is live, so it is on the organization's list.
 */
final class DbalOAuthConnectionsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000041a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000041b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000041c1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000041c2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000041c3';

    private const string NEWEST = '0197a3c0-0000-7000-8000-0000000041f1';
    private const string RENEWED = '0197a3c0-0000-7000-8000-0000000041f2';
    private const string TIE_LOW = '0197a3c0-0000-7000-8000-0000000041f3';
    private const string TIE_HIGH = '0197a3c0-0000-7000-8000-0000000041f4';
    private const string LAST_SECOND = '0197a3c0-0000-7000-8000-0000000041f5';
    private const string REVOKED = '0197a3c0-0000-7000-8000-0000000041f6';
    private const string EXPIRED_AT_NOW = '0197a3c0-0000-7000-8000-0000000041f7';
    private const string OF_LENA = '0197a3c0-0000-7000-8000-0000000041f8';
    private const string OF_BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000041f9';
    private const string LONG_RENEWED = '0197a3c0-0000-7000-8000-0000000041fa';

    private const string NAMED_CLIENT_ID = 'connected-apps-test-client-00041';
    private const string UNREGISTERED_CLIENT_ID = 'unregistered-client-of-test-0041';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    protected function setUp(): void
    {
        $this->removeSeededRows();
        $this->connection()->executeStatement(
            'INSERT INTO idn_oauth_client (client_id, client_name, redirect_uris, grant_types, created_at)'
            ." VALUES (?, 'Claude Code', '{https://claude.example/callback}', '{authorization_code,refresh_token}', '2027-03-01 10:00:00+00')",
            [self::NAMED_CLIENT_ID],
        );
        $this->addToken(self::ACME_FOODS, self::NEWEST, self::OMAR, self::UNREGISTERED_CLIENT_ID, [ApiTokenScope::Read], '-1 day');
        $traded = $this->addToken(self::ACME_FOODS, self::RENEWED, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::TicketsMove, ApiTokenScope::Read], '-3 days');
        $this->trade($traded, '-2 days');
        $this->addToken(self::ACME_FOODS, self::RENEWED, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::TicketsMove, ApiTokenScope::Read], '-2 days', $traded->familyCreatedAt());
        $this->addToken(self::ACME_FOODS, self::TIE_LOW, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-5 days');
        $this->addToken(self::ACME_FOODS, self::TIE_HIGH, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-5 days');
        $this->addToken(self::ACME_FOODS, self::LAST_SECOND, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-30 days +1 second');
        $this->addToken(self::ACME_FOODS, self::REVOKED, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-1 hour');
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->refreshTokens()->revokeFamily(self::REVOKED, $this->at('-30 minutes')));
        $this->addToken(self::ACME_FOODS, self::EXPIRED_AT_NOW, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-30 days');
        $this->addToken(self::ACME_FOODS, self::OF_LENA, self::LENA, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-4 days');
        $this->addToken(self::BETA_BAKERY, self::OF_BETA_BAKERY, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-2 hours');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeSeededRows();
        parent::tearDown();
    }

    #[Test]
    public function aPersonsListShowsOnlyTheirOwnLiveConnectionsOfTheTenantNewestFirst(): void
    {
        $connections = $this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('+0 seconds')));

        self::assertSame(
            [
                [self::NEWEST, self::OMAR, self::UNREGISTERED_CLIENT_ID, '2027-03-14 10:00:00', null, ['read']],
                [self::RENEWED, self::OMAR, 'Claude Code', '2027-03-12 10:00:00', '2027-03-13 10:00:00', ['read', 'tickets:move']],
                [self::TIE_HIGH, self::OMAR, 'Claude Code', '2027-03-10 10:00:00', null, ['read']],
                [self::TIE_LOW, self::OMAR, 'Claude Code', '2027-03-10 10:00:00', null, ['read']],
                [self::LAST_SECOND, self::OMAR, 'Claude Code', '2027-02-13 10:00:01', null, ['read']],
            ],
            self::rowsOf($connections),
        );
    }

    #[Test]
    public function aPersonsListUnderAnotherOrganizationShowsOnlyThatOrganizationsConnections(): void
    {
        self::assertSame(
            [self::OF_BETA_BAKERY],
            self::familyIdsOf($this->asTenant(self::BETA_BAKERY, fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('+0 seconds')))),
        );
        self::assertSame([], $this->asTenant(self::BETA_BAKERY, fn (): array => $this->connections()->liveOf(new UserId(self::LENA), $this->at('+0 seconds'))));
    }

    #[Test]
    public function aConnectionIsListedUntilTheSecondItsNewestTokenExpires(): void
    {
        self::assertContains(
            self::EXPIRED_AT_NOW,
            self::familyIdsOf($this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('-1 second')))),
        );
        self::assertNotContains(
            self::LAST_SECOND,
            self::familyIdsOf($this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('+1 second')))),
        );
    }

    #[Test]
    public function aPersonsListHoldsTheNewestFiftyConnections(): void
    {
        $familyIds = [];
        $this->transactions()->committedAs(self::ACME_FOODS, function () use (&$familyIds): void {
            for ($i = 0; $i <= OAuthConnections::MAX_LISTED; ++$i) {
                $familyIds[] = $familyId = $this->refreshTokens()->nextIdentity();
                $this->refreshTokens()->add($this->issue(self::ACME_FOODS, $familyId, self::MIA, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], \sprintf('-%d minutes', $i + 1)));
            }
        });

        $listed = self::familyIdsOf($this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->liveOf(new UserId(self::MIA), $this->at('+0 seconds'))));

        self::assertSame(\array_slice($familyIds, 0, OAuthConnections::MAX_LISTED), $listed);
    }

    #[Test]
    public function theOrganizationsPagesShowEveryPersonsLiveConnectionsOfTheTenantOnceAcrossATie(): void
    {
        self::assertSame(
            [[self::NEWEST, self::RENEWED], [self::OF_LENA, self::TIE_HIGH], [self::TIE_LOW, self::LAST_SECOND], []],
            $this->organizationPagesOfTwo(),
        );
        self::assertSame(
            [self::OF_BETA_BAKERY],
            self::familyIdsOf($this->asTenant(self::BETA_BAKERY, fn (): array => $this->connections()->livePage($this->at('+0 seconds'), 100))),
        );
    }

    /**
     * LONG_RENEWED is connected in the same second as TIE_LOW and TIE_HIGH and renewed twice, the last time an hour ago:
     * its live token is newer than every other, so a list read from the live token's own time would put it first. The
     * retention job's purge of traded tokens is done by hand, through the migrator.
     */
    #[Test]
    public function theConnectionTimeRenewalAndOrderStayWhenTheTradedTokensArePurged(): void
    {
        $first = $this->addToken(self::ACME_FOODS, self::LONG_RENEWED, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-5 days');
        $this->trade($first, '-4 days');
        $second = $this->addToken(self::ACME_FOODS, self::LONG_RENEWED, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-4 days', $first->familyCreatedAt());
        $this->trade($second, '-1 hour');
        $this->addToken(self::ACME_FOODS, self::LONG_RENEWED, self::OMAR, self::NAMED_CLIENT_ID, [ApiTokenScope::Read], '-1 hour', $second->familyCreatedAt());
        $omarsList = [
            [self::NEWEST, self::OMAR, self::UNREGISTERED_CLIENT_ID, '2027-03-14 10:00:00', null, ['read']],
            [self::RENEWED, self::OMAR, 'Claude Code', '2027-03-12 10:00:00', '2027-03-13 10:00:00', ['read', 'tickets:move']],
            [self::LONG_RENEWED, self::OMAR, 'Claude Code', '2027-03-10 10:00:00', '2027-03-15 09:00:00', ['read']],
            [self::TIE_HIGH, self::OMAR, 'Claude Code', '2027-03-10 10:00:00', null, ['read']],
            [self::TIE_LOW, self::OMAR, 'Claude Code', '2027-03-10 10:00:00', null, ['read']],
            [self::LAST_SECOND, self::OMAR, 'Claude Code', '2027-02-13 10:00:01', null, ['read']],
        ];
        $organizationPages = [[self::NEWEST, self::RENEWED], [self::OF_LENA, self::LONG_RENEWED], [self::TIE_HIGH, self::TIE_LOW], [self::LAST_SECOND]];
        self::assertSame($omarsList, self::rowsOf($this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('+0 seconds')))));
        self::assertSame($organizationPages, $this->organizationPagesOfTwo());
        $cursor = $this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->livePage($this->at('+0 seconds'), 4))[3] ?? null;
        self::assertSame(self::LONG_RENEWED, $cursor?->familyId);

        self::assertSame(3, $this->purgeTradedTokensOf(self::ACME_FOODS));

        self::assertSame($omarsList, self::rowsOf($this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('+0 seconds')))));
        self::assertSame($organizationPages, $this->organizationPagesOfTwo());
        self::assertSame(
            [self::TIE_HIGH, self::TIE_LOW],
            self::familyIdsOf($this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->livePage($this->at('+0 seconds'), 2, $cursor->connectedAt, $cursor->familyId))),
        );
    }

    #[Test]
    public function aPageWithABadSizeOrHalfACursorIsRefusedBeforeAnyQuery(): void
    {
        $connectedAt = $this->at('-5 days');
        foreach ([
            'no connection' => [static fn (OAuthConnections $connections, \DateTimeImmutable $now): array => $connections->livePage($now, 0), 'A page of connections holds at least one.'],
            'only the time' => [static fn (OAuthConnections $connections, \DateTimeImmutable $now): array => $connections->livePage($now, 2, $connectedAt), 'A page of connections starts after both the connection time and the id of a connection.'],
            'only the id' => [static fn (OAuthConnections $connections, \DateTimeImmutable $now): array => $connections->livePage($now, 2, null, self::TIE_HIGH), 'A page of connections starts after both the connection time and the id of a connection.'],
            'an id that is no UUID' => [static fn (OAuthConnections $connections, \DateTimeImmutable $now): array => $connections->livePage($now, 2, $connectedAt, 'not-a-uuid'), 'A page of connections starts after both the connection time and the id of a connection.'],
        ] as $case => [$page, $reason]) {
            try {
                // No tenant: a query would refuse with TenantNotSet instead.
                $page($this->connections(), $this->at('+0 seconds'));
                self::fail('The page was given for '.$case.'.');
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame($reason, $refusal->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function bothListsNeedATenant(): void
    {
        $outcomes = [];
        foreach ([
            'liveOf' => fn (): array => $this->connections()->liveOf(new UserId(self::OMAR), $this->at('+0 seconds')),
            'livePage' => fn (): array => $this->connections()->livePage($this->at('+0 seconds'), 2),
        ] as $call => $withoutTenant) {
            try {
                $withoutTenant();
                $outcomes[$call] = 'ran';
            } catch (TenantNotSet) {
                $outcomes[$call] = 'refused';
            }
        }

        self::assertSame(['liveOf' => 'refused', 'livePage' => 'refused'], $outcomes);
    }

    /**
     * @param list<OAuthConnection> $connections
     *
     * @return list<array{string, string, string, string, string|null, list<string>}>
     */
    private static function rowsOf(array $connections): array
    {
        self::assertNotSame([], $connections);
        $utc = new \DateTimeZone('UTC');

        return array_map(static fn (OAuthConnection $connection): array => [
            $connection->familyId,
            $connection->userId->toString(),
            $connection->clientName,
            $connection->connectedAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            $connection->lastRenewedAt?->setTimezone($utc)->format('Y-m-d H:i:s'),
            $connection->scopes,
        ], $connections);
    }

    /**
     * @param list<OAuthConnection> $connections
     *
     * @return list<string>
     */
    private static function familyIdsOf(array $connections): array
    {
        return array_map(static fn (OAuthConnection $connection): string => $connection->familyId, $connections);
    }

    /**
     * Acme Foods' pages of two, each after the last connection of the one before, until a page is not full.
     *
     * @return list<list<string>>
     */
    private function organizationPagesOfTwo(): array
    {
        $firstPage = $this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->livePage($this->at('+0 seconds'), 2));
        $pages = [self::familyIdsOf($firstPage)];
        $last = $firstPage[1] ?? null;
        while (null !== $last) {
            $page = $this->asTenant(self::ACME_FOODS, fn (): array => $this->connections()->livePage($this->at('+0 seconds'), 2, $last->connectedAt, strtoupper($last->familyId)));
            $pages[] = self::familyIdsOf($page);
            $last = $page[1] ?? null;
        }

        return $pages;
    }

    /**
     * @param list<ApiTokenScope> $scopes
     * @param ?\DateTimeImmutable $familyCreatedAt the traded token's, for a family's next token
     */
    private function addToken(string $organizationId, string $familyId, string $userId, string $clientId, array $scopes, string $sinceNow, ?\DateTimeImmutable $familyCreatedAt = null): OAuthRefreshToken
    {
        $token = $this->issue($organizationId, $familyId, $userId, $clientId, $scopes, $sinceNow, $familyCreatedAt);
        $this->transactions()->committedAs($organizationId, fn () => $this->refreshTokens()->add($token));

        return $token;
    }

    private function trade(OAuthRefreshToken $token, string $sinceNow): void
    {
        $traded = $this->transactions()->committedAs(
            $token->organizationId()->toString(),
            fn (): ?OAuthRefreshToken => $this->refreshTokens()->markUsed($token->tokenHash(), $token->clientId(), $this->at($sinceNow)),
        );
        self::assertNotNull($traded, 'The token was not traded.');
    }

    /**
     * Through the migrator, as the retention job will: the app may not delete.
     */
    private function purgeTradedTokensOf(string $organizationId): int
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $purged = $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM idn_oauth_refresh_token WHERE organization_id = ? AND used_at IS NOT NULL',
                [$organizationId],
            ));
        } finally {
            $this->tenantContext()->clear();
        }

        return (int) $purged;
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private function issue(string $organizationId, string $familyId, string $userId, string $clientId, array $scopes, string $sinceNow, ?\DateTimeImmutable $familyCreatedAt = null): OAuthRefreshToken
    {
        return OAuthRefreshToken::issue(
            $this->refreshTokens()->nextIdentity(),
            $familyId,
            new OrganizationId($organizationId),
            new UserId($userId),
            $clientId,
            $scopes,
            $this->refreshTokens()->nextIdentity(),
            new HmacApiTokenHashing(self::PEPPER),
            $this->at($sinceNow),
            $familyCreatedAt,
        )->token();
    }

    private function at(string $sinceNow): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW)->modify($sinceNow);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function asTenant(string $organizationId, \Closure $read): mixed
    {
        return $this->transactions()->rolledBackAs($organizationId, $read);
    }

    /**
     * Through the migrator, which owns the tables and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeSeededRows(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->tenantContext()->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM idn_oauth_refresh_token WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext()->clear();
            }
        }
        $this->migrator()->executeStatement('DELETE FROM idn_oauth_client WHERE client_id = ?', [self::NAMED_CLIENT_ID]);
    }

    private function connections(): OAuthConnections
    {
        return new DbalOAuthConnections($this->connection(), $this->tenantContext());
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
