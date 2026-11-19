<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Audit\Application\RecordIdentityAuditEntry;
use App\Identity\Domain\ApiToken;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Organization\LogsInAdmins;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The audit rows of the tokens page, /me/tokens, through the real firewall, code login, outbox and audit worker over
 * HTTPS (P09.T1, ADR-0026). The client keeps its kernel, so the clock set in the container is the one every request
 * reads; it is fixed on 17 Nov 2026, inside the months aud_event has partitions for and before the day this was
 * written: a clock ahead of the wall clock would end the session, whose idle check reads the session's own time.
 */
final class TokenAuditTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use IssuesCsrfTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RunsTheWorkerUntilIdle;
    use TurnsOnTwoStepLogin;

    private const string TOKENS_PAGE = '/me/tokens';
    private const string NOW = '2026-11-17 09:00:00 UTC';
    private const string TOKEN_NAME = 'Laptop of Omar';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordIdentityAuditEntry::HANDLER_NAME]);
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function makingATokenWritesOneIssuedRowAndRevokingItASecondRowWithIdsOnly(): void
    {
        [$omar, $acmeFoods] = $this->memberWithOrgRoles('Omar Haddad', '{}');
        $this->logInWithACode($this->client, $this->clock, $omar);
        $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();
        $issuedAt = $this->clock->now();

        $page = $this->client->submitForm('Make token', ['name' => self::TOKEN_NAME, 'lifetime_days' => '30']);

        self::assertResponseStatusCodeSame(200);
        $plainToken = (string) $page->filter('main input[name="new_token"]')->attr('value');
        self::assertStringStartsWith(ApiToken::PLAIN_PREFIX, $plainToken);
        $displayPrefix = substr($plainToken, 0, \strlen(ApiToken::PLAIN_PREFIX) + ApiToken::DISPLAY_PREFIX_LENGTH);
        $tokenIds = $page->filter('main li.api-tokens__row')->each(static fn (Crawler $row): string => (string) $row->attr('data-token-id'));
        self::assertCount(1, $tokenIds);
        $tokenId = $tokenIds[0];

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        $issuedRow = ['user', $omar->id()->toString(), 'api_token.issued', 'api_token', $tokenId, $issuedAt->format('Y-m-d H:i:s')];
        $rows = $this->auditRowsOf($acmeFoods);
        self::assertSame([$issuedRow], array_map(static fn (array $row): array => \array_slice($row, 0, 6), $rows));
        // assertEquals: jsonb keeps the keys, not their order.
        self::assertEquals(
            ['scopes' => ['read', 'rounds:write'], 'expires_at' => $issuedAt->modify('+30 days')->format(\DATE_ATOM)],
            $rows[0][6],
        );
        $this->assertNoRowHolds($acmeFoods, [$plainToken, $displayPrefix, self::TOKEN_NAME]);

        $this->clock->sleep(60);
        $revokedAt = $this->clock->now();
        $this->client->submitForm('Revoke');

        self::assertResponseRedirects(self::TOKENS_PAGE, 302);
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        $rows = $this->auditRowsOf($acmeFoods);
        self::assertSame(
            [$issuedRow, ['user', $omar->id()->toString(), 'api_token.revoked', 'api_token', $tokenId, $revokedAt->format('Y-m-d H:i:s')]],
            array_map(static fn (array $row): array => \array_slice($row, 0, 6), $rows),
        );
        self::assertSame([], $rows[1][6]);
        $this->assertNoRowHolds($acmeFoods, [$plainToken, $displayPrefix, self::TOKEN_NAME]);
    }

    #[Test]
    public function revokingAnotherPersonsTokenIsNotFoundWritesNoRowAndLeavesTheTokenLive(): void
    {
        [$omar, $acmeFoods] = $this->memberWithOrgRoles('Omar Haddad', '{}');
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);
        [$lenasTokenId] = $this->issueApiToken($lena, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $omar);
        $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();

        $this->client->request('POST', \sprintf('/me/tokens/%s/revoke', $lenasTokenId), ['_csrf_token' => $this->csrfTokenFor($this->client, 'identity_token_revoke')]);

        self::assertResponseStatusCodeSame(404);
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        self::assertSame([], $this->auditRowsOf($acmeFoods));
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($lenasTokenId, $acmeFoods));
    }

    /**
     * Drops the events of the logins, so the outbox holds only what the requests after it publish.
     */
    private function forgetEarlierEvents(): void
    {
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}> actor kind, actor, action, target type, target, time, data
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ? ORDER BY at, id",
                [$organizationId->toString()],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[6]);
            $data = json_decode($row[6], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $data];
        }, $rows);
    }

    /**
     * Every column of every row, as text, holds none of $secrets.
     *
     * @param list<string> $secrets
     */
    private function assertNoRowHolds(OrganizationId $organizationId, array $secrets): void
    {
        $rowTexts = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rowTexts): int {
            $rowTexts = $migrator->fetchFirstColumn('SELECT e::text FROM aud_event e WHERE organization_id = ?', [$organizationId->toString()]);

            return 0;
        });
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $rowText);
            }
        }
    }
}
