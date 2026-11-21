<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Infrastructure\Doctrine\DoctrineOAuthClients;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineOAuthClients on the real idn_oauth_client table as pinround_app, in a transaction rolled back after each test.
 */
final class DoctrineOAuthClientsTest extends KernelTestCase
{
    private const string REGISTERED_AT = '2026-11-06 12:00:00.600 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    private const string LOOPBACK_CALLBACK = 'http://127.0.0.1:53127/cb';
    private const string VALID_CLIENT_ID = 'Abc-_0123456789abcdefghijklmnopq';

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
    public function anAddedClientIsSavedAtOnceAndReloadsIntact(): void
    {
        $client = OAuthClient::register(
            'Claude Code',
            [self::CALLBACK, self::LOOPBACK_CALLBACK],
            [OAuthGrantType::RefreshToken, OAuthGrantType::AuthorizationCode],
            new \DateTimeImmutable(self::REGISTERED_AT),
        );
        $this->clients()->add($client);

        $row = $this->connection()->fetchAssociative(
            'SELECT client_name, array_to_json(redirect_uris)::text AS redirect_uris, array_to_json(grant_types)::text AS grant_types,'
            ." to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS created_at FROM idn_oauth_client WHERE client_id = ?",
            [$client->clientId()],
        );
        self::assertSame(
            [
                'client_name' => 'Claude Code',
                'redirect_uris' => '["'.self::CALLBACK.'","'.self::LOOPBACK_CALLBACK.'"]',
                'grant_types' => '["authorization_code","refresh_token"]',
                'created_at' => '2026-11-06 12:00:00.000000',
            ],
            $row,
        );

        $this->entityManager()->clear();
        $reloaded = $this->clients()->byClientId($client->clientId());

        self::assertNotNull($reloaded);
        self::assertNotSame($client, $reloaded);
        self::assertSame($client->clientId(), $reloaded->clientId());
        self::assertSame('Claude Code', $reloaded->clientName());
        self::assertSame([self::CALLBACK, self::LOOPBACK_CALLBACK], $reloaded->redirectUris());
        self::assertSame([OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken], $reloaded->grantTypes());
        self::assertSame('2026-11-06 12:00:00.000000 +00:00', $reloaded->createdAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u P'));
    }

    #[Test]
    public function aClientWithoutANameReloadsWithoutOne(): void
    {
        $client = OAuthClient::register(null, [self::CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::REGISTERED_AT));
        $this->clients()->add($client);
        $this->entityManager()->clear();

        $reloaded = $this->clients()->byClientId($client->clientId());

        self::assertNotNull($reloaded);
        self::assertNull($reloaded->clientName());
        self::assertSame([OAuthGrantType::AuthorizationCode], $reloaded->grantTypes());
    }

    #[Test]
    public function anUnknownOrDifferentlyCasedClientIdFindsNoClient(): void
    {
        $client = OAuthClient::register(null, [self::CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::REGISTERED_AT));
        $this->clients()->add($client);
        $this->clients()->add(OAuthClient::register('Other', [self::CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::REGISTERED_AT)));
        $this->entityManager()->clear();
        $otherCase = strtr($client->clientId(), 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');

        self::assertNotSame($client->clientId(), $otherCase);
        self::assertNotNull($this->clients()->byClientId($client->clientId()));
        self::assertNull($this->clients()->byClientId($otherCase));
        self::assertNull($this->clients()->byClientId(self::VALID_CLIENT_ID));
        self::assertNull($this->clients()->byClientId(''));
    }

    #[Test]
    public function onlyTheAppMayReadAndAddClientsAndTheTableHasNoRowLevelSecurity(): void
    {
        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT'],
            $this->connection()->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
                 FROM pg_class c, aclexplode(c.relacl) acl
                 WHERE c.oid = to_regclass('public.idn_oauth_client') AND acl.grantee <> c.relowner",
            ),
        );
        self::assertSame(
            ['pinround_migrator', false, false],
            $this->connection()->fetchNumeric("SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.idn_oauth_client')"),
        );
    }

    #[Test]
    public function aRowWithinEveryConstraintIsAccepted(): void
    {
        $this->insertRow([]);
        $this->insertRow(['client_id' => 'Zbc-_0123456789abcdefghijklmnopq', 'client_name' => null]);
        $this->insertRow(['client_id' => 'Ybc-_0123456789abcdefghijklmnopq', 'client_name' => str_repeat("\u{00E9}", 100), 'redirect_uris' => '{a,b,c,d,e}', 'grant_types' => '{refresh_token,authorization_code}']);

        self::assertSame(3, $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_client WHERE client_id LIKE ?', ['%bc-_0123456789abcdefghijklmnopq']));
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}> the columns that differ from a valid row and the constraint refusing them
     */
    public static function rowsBreakingAConstraint(): iterable
    {
        yield 'a client_id of 31 characters' => [['client_id' => substr(self::VALID_CLIENT_ID, 1)], 'idn_oauth_client_client_id_check'];
        yield 'a client_id of 33 characters' => [['client_id' => self::VALID_CLIENT_ID.'x'], 'idn_oauth_client_client_id_check'];
        yield 'a client_id with a plus' => [['client_id' => '+'.substr(self::VALID_CLIENT_ID, 1)], 'idn_oauth_client_client_id_check'];
        yield 'a client_id with a final newline' => [['client_id' => self::VALID_CLIENT_ID."\n"], 'idn_oauth_client_client_id_check'];
        yield 'an empty client_name' => [['client_name' => ''], 'idn_oauth_client_client_name_check'];
        yield 'a client_name of 101 characters' => [['client_name' => str_repeat("\u{00E9}", 101)], 'idn_oauth_client_client_name_check'];
        yield 'no redirect URI' => [['redirect_uris' => '{}'], 'idn_oauth_client_redirect_uris_check'];
        yield 'six redirect URIs' => [['redirect_uris' => '{a,b,c,d,e,f}'], 'idn_oauth_client_redirect_uris_check'];
        yield 'no grant' => [['grant_types' => '{}'], 'idn_oauth_client_grant_types_check'];
        yield 'only refresh_token' => [['grant_types' => '{refresh_token}'], 'idn_oauth_client_grant_types_check'];
        yield 'an unknown grant' => [['grant_types' => '{authorization_code,implicit}'], 'idn_oauth_client_grant_types_check'];
    }

    /**
     * @param array<string, string|null> $columns
     */
    #[Test]
    #[DataProvider('rowsBreakingAConstraint')]
    public function aRowBreakingAConstraintIsRefused(array $columns, string $constraint): void
    {
        $rowsBefore = $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_client');
        $this->connection()->beginTransaction();
        try {
            $this->insertRow($columns);
            self::fail('The row was accepted.');
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString($constraint, $refusal->getMessage());
        } finally {
            $this->connection()->rollBack();
        }

        self::assertSame($rowsBefore, $this->connection()->fetchOne('SELECT count(*) FROM idn_oauth_client'));
    }

    /**
     * @param array<string, string|null> $columns replacing those of a valid row
     */
    private function insertRow(array $columns): void
    {
        $row = array_merge([
            'client_id' => self::VALID_CLIENT_ID,
            'client_name' => 'Claude Code',
            'redirect_uris' => '{'.self::CALLBACK.'}',
            'grant_types' => '{authorization_code}',
        ], $columns);
        $this->connection()->executeStatement(
            'INSERT INTO idn_oauth_client (client_id, client_name, redirect_uris, grant_types, created_at)'
            ." VALUES (?, ?, ?::text[], ?::text[], '2026-11-06 12:00:00+00')",
            [$row['client_id'], $row['client_name'], $row['redirect_uris'], $row['grant_types']],
        );
    }

    private function clients(): DoctrineOAuthClients
    {
        return new DoctrineOAuthClients($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
