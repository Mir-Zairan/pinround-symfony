<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Audit\Application\RecordIdentityAuditEntry;
use App\Identity\Domain\User;
use App\Organization\UI\Admin\PeopleController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\RegistersOAuthClients;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The connected apps of Admin › People: an Admin sees every person's live OAuth connections and ends any of them
 * (ADR-0050), through the real firewall, code login, token endpoint, outbox and audit worker over HTTPS. The page's
 * clock is fixed on 16 Nov 2026, inside the months aud_event has partitions for and before the day this was written;
 * the connections are made in 2099 before the login, as the token resolvers compare with the database clock.
 */
final class AdminPeopleConnectionsTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use IssuesCsrfTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;
    use RunsTheWorkerUntilIdle;
    use TurnsOnTwoStepLogin;

    private const string PEOPLE_PAGE = '/admin/people';
    private const string END = '/admin/people/connections/%s/end';
    private const string END_CSRF_ID = 'organization_admin_people_connection_end';
    private const string API_PROBE = '/api/v1/me/tickets';
    private const string NOW = '2026-11-16 14:00:00 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';

    private KernelBrowser $client;
    private MockClock $clock;
    private string $clientId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->clientId = $this->registerClient($this->client, 'Claude', [self::CALLBACK], ['authorization_code', 'refresh_token']);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordIdentityAuditEntry::HANDLER_NAME]);
        $this->removeOAuthClientsAndCodes();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar connected before Lena, so the list order is not the order of the names. An ended connection of Omar's and a
     * connection of another organization are left out.
     */
    #[Test]
    public function anAdminSeesEveryPersonsLiveConnectionsNewestFirst(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $lenas = $this->connect($lena, $acmeFoods, '2099-03-15 10:00:00 UTC');
        $omarsEnded = $this->connect($omar, $acmeFoods, '2099-03-16 10:00:00 UTC');
        $this->readAs($acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE idn_oauth_refresh_token SET revoked_at = '2026-11-15 09:00:00+00' WHERE family_id = ?",
            [$omarsEnded['familyId']],
        ));
        $betaBakery = $this->createOrganization('Beta Bakery');
        $mia = $this->createUserWithoutOrganization('Mia Lund');
        $this->addMembership($mia, $betaBakery);
        $this->connect($mia, $betaBakery, '2099-03-17 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Connected apps', $page->filter('main #people-connections-title')->text());
        self::assertSame([$lenas['familyId'], $omars['familyId']], $this->connectionIdsOn($page));
        self::assertSame(['Lena Berg', 'Omar Haddad'], $page->filter('main .people__connection-row .people__token-owner')->each(static fn (Crawler $owner): string => $owner->text()));
        self::assertSame('Claude · Connected 15 Mar 2099 · Not renewed yet', $page->filter('main .people__connection-row .people__sub')->first()->text());
        self::assertSame(
            ['End connection: Lena Berg’s Claude', 'End connection: Omar Haddad’s Claude'],
            $page->filter('main .people__connection-row form button[type="submit"]')->each(static fn (Crawler $end): ?string => $end->attr('aria-label')),
        );
        self::assertCount(0, $page->filter('main .people__connections .empty-state'));
    }

    #[Test]
    public function withNoConnectionsTheSectionSaysSo(): void
    {
        [$sara] = $this->acmeFoodsWithSaraOmarAndLena();
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('No apps are connected.', $page->filter('main .people__connections .empty-state')->text());
        self::assertCount(0, $page->filter('main .people__connection-row'));
        self::assertCount(0, $page->filter('main .people__connections a'));
    }

    /**
     * Its access token stops at once; Lena's keeps working. A second click, as from a page opened before the first,
     * changes nothing and says nothing. One audit row names Sara, not Omar, as the one who stopped the token.
     */
    #[Test]
    public function anAdminEndsOmarsConnectionOnceAndTheAuditNamesHerAsActor(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $lenas = $this->connect($lena, $acmeFoods, '2099-03-15 10:00:00 UTC');
        self::assertSame(200, $this->apiStatusWith($omars['accessToken']), 'the token did not work before, so the 401 after proves nothing');
        $this->logInWithACode($this->client, $this->clock, $sara);
        $page = $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);
        $endOmars = $page->filter(\sprintf('main li[data-connection-id="%s"] form', $omars['familyId']))->form();
        $this->forgetEarlierEvents();
        $this->clock->sleep(60);
        $endedAt = $this->clock->now()->format('Y-m-d H:i:s');

        $this->client->submit($endOmars);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(PeopleController::CONNECTION_ENDED, $page->filter('.toast-region .toast[data-controller="toast"]')->attr('data-toast-message-value'));
        self::assertSame([$lenas['familyId']], $this->connectionIdsOn($page));
        self::assertSame([$endedAt], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $lenas['familyId']));
        self::assertSame(401, $this->apiStatusWith($omars['accessToken']));
        self::assertSame(200, $this->apiStatusWith($lenas['accessToken']));

        $this->clock->sleep(60);
        $this->client->submit($endOmars);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.toast-region .toast, noscript'));
        self::assertSame([$endedAt], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        self::assertSame([['user', $sara->id()->toString(), 'api_token.revoked', 'api_token', $endedAt]], $this->auditRowsOf($acmeFoods));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rolesBelowAdmin(): iterable
    {
        yield 'manager' => ['{manager}'];
        yield 'member' => ['{}'];
    }

    /**
     * REACH: with a fresh code and a valid CSRF token, so only the role refuses; the page and the end are both 404.
     */
    #[Test]
    #[DataProvider('rolesBelowAdmin')]
    public function aPersonBelowAdminGetsNoPageAndNoEndAndTheConnectionStaysLive(string $orgRoles): void
    {
        [, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $this->setOrgRoles($lena, $acmeFoods, $orgRoles);
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $lena);
        $this->forgetEarlierEvents();

        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', \sprintf(self::END, $omars['familyId']), ['_csrf_token' => $this->csrfTokenFor($this->client, self::END_CSRF_ID)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
        self::assertSame(200, $this->apiStatusWith($omars['accessToken']));
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function idsOfNoConnectionHere(): iterable
    {
        yield 'another organization\'s' => [null];
        yield 'malformed' => ["not-a-connection-id'"];
        yield 'unknown' => ['0198f000-0000-7000-8000-0000000000ff'];
    }

    /**
     * REACH: an Admin of Acme Foods ends nothing of Beta Bakery's, and an id of no connection is the same 404.
     */
    #[Test]
    #[DataProvider('idsOfNoConnectionHere')]
    public function anEndOfAnIdOfNoConnectionOfTheOrganizationIsNotFoundAndEndsNothing(?string $familyId): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $mia = $this->createUserWithoutOrganization('Mia Lund');
        $this->addMembership($mia, $betaBakery);
        $mias = $this->connect($mia, $betaBakery, '2099-03-15 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('POST', \sprintf(self::END, rawurlencode($familyId ?? $mias['familyId'])), ['_csrf_token' => $this->csrfTokenFor($this->client, self::END_CSRF_ID)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
        self::assertSame([null], $this->refreshTokensRevokedAtOf($betaBakery, $mias['familyId']));
        self::assertSame(200, $this->apiStatusWith($mias['accessToken']));
    }

    #[Test]
    public function anEndWithAWrongCsrfTokenIsRefusedAndTheConnectionStaysLive(): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('POST', \sprintf(self::END, $omars['familyId']), [
            '_csrf_token' => $this->csrfTokenFor($this->client, 'organization_admin_people_token_revoke'),
            PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => '2100-01-01T00:00:00+00:00',
            PeopleController::CONNECTIONS_AFTER_ID => '0198f000-0000-7000-8000-0000000003ff',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(PeopleController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());
        self::assertSame([$omars['familyId']], $this->connectionIdsOn($page));
        self::assertSame(
            [
                PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => '2100-01-01T00:00:00+00:00',
                PeopleController::CONNECTIONS_AFTER_ID => '0198f000-0000-7000-8000-0000000003ff',
            ],
            array_diff_key($page->filter(\sprintf('main li[data-connection-id="%s"] form', $omars['familyId']))->form()->getValues(), ['_csrf_token' => true]),
            'the page shown again keeps the page of connected apps the form named',
        );
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
        self::assertSame(200, $this->apiStatusWith($omars['accessToken']));
    }

    /**
     * 21 connections, the newest first; Show more names the next page of connections and the page the tokens are on.
     */
    #[Test]
    public function theConnectionsShowTwentyAtATimeWithShowMoreForTheNext(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $oldestFirst = [];
        for ($day = 1; $day <= 21; ++$day) {
            $oldestFirst[] = $this->connect(0 === $day % 2 ? $omar : $lena, $acmeFoods, \sprintf('2099-01-%02d 10:00:00 UTC', $day))['familyId'];
        }
        $newestFirst = array_reverse($oldestFirst);
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(\array_slice($newestFirst, 0, PeopleController::PAGE_SIZE), $this->connectionIdsOn($page));
        $showMore = $page->filter('main .people__connections a.btn')->reduce(static fn (Crawler $link): bool => 'Show more' === $link->text());
        self::assertCount(1, $showMore);
        self::assertSame(
            '/admin/people?tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff'
            .'&connections_after_connected_at=2099-01-02T10:00:00%2B00:00&connections_after_id='.$newestFirst[PeopleController::PAGE_SIZE - 1],
            $showMore->attr('href'),
        );

        $page = $this->client->click($showMore->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$oldestFirst[0]], $this->connectionIdsOn($page));
        self::assertCount(0, $page->filter('main .people__connections a.btn'));
    }

    /**
     * Past the oldest connection, a later page says there are no more and links back to the first page with the
     * tokens' page kept; "No apps are connected." would be wrong there, as Omar's connection is live.
     */
    #[Test]
    public function aLaterPageOfConnectionsWithNoneLeftLinksBackToTheFirstPage(): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
            PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::CONNECTIONS_AFTER_ID => '0198f000-0000-7000-8000-0000000003ff',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->connectionIdsOn($page));
        self::assertCount(0, $page->filter('main .people__connections .empty-state'));
        self::assertSame('No more connected apps. Back to the first page', $page->filter('main .people__connections p.people__sub')->text());
        $firstPageLink = $page->filter('main .people__connections a');
        self::assertCount(1, $firstPageLink);
        self::assertSame('/admin/people?tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff', $firstPageLink->attr('href'));

        $page = $this->client->click($firstPageLink->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$omars['familyId']], $this->connectionIdsOn($page));
    }

    /**
     * The admin is on a later page of tokens and of connections; ending one, or revoking a token, brings them back to
     * those pages. Both connections are older than the connections cursor, so they show on its page.
     */
    #[Test]
    public function anEndOrARevokeGoesBackToThePagesTheListsWereOn(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $lenas = $this->connect($lena, $acmeFoods, '2099-03-13 10:00:00 UTC');
        [$lenasToken] = $this->issueApiToken($lena, $acmeFoods, '2099-06-13 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);
        $pages = [
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
            PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => '2099-12-01T00:00:00+00:00',
            PeopleController::CONNECTIONS_AFTER_ID => '0198f000-0000-7000-8000-0000000003ff',
        ];
        $shownPages = '/admin/people?tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff'
            .'&connections_after_connected_at=2099-12-01T00:00:00%2B00:00&connections_after_id=0198f000-0000-7000-8000-0000000003ff';
        $page = $this->client->request('GET', self::PEOPLE_PAGE, $pages);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$omars['familyId'], $lenas['familyId']], $this->connectionIdsOn($page));

        $this->client->submit($page->filter(\sprintf('main li[data-connection-id="%s"] form', $omars['familyId']))->form());

        self::assertResponseRedirects($shownPages, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$lenas['familyId']], $this->connectionIdsOn($page));
        $revokedAt = $this->clock->now()->format('Y-m-d H:i:s');

        $this->client->submit($page->filter(\sprintf('main li[data-token-id="%s"] form', $lenasToken))->form());

        self::assertResponseRedirects($shownPages, 302);
        self::assertSame(['last_used_at' => null, 'revoked_at' => $revokedAt], $this->usageOf($lenasToken, $acmeFoods));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notAPageOfConnections(): iterable
    {
        $at = '2099-03-14T10:00:00+00:00';
        $id = '0198f000-0000-7000-8000-000000000001';
        yield 'the time only' => [[PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => $at]];
        yield 'the id only' => [[PeopleController::CONNECTIONS_AFTER_ID => $id]];
        yield 'an id that is no uuid' => [[PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => $at, PeopleController::CONNECTIONS_AFTER_ID => 'not-a-uuid']];
        yield 'a day the month does not have' => [[PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => '2099-06-31T10:00:00+00:00', PeopleController::CONNECTIONS_AFTER_ID => $id]];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('notAPageOfConnections')]
    public function aPartOrBadConnectionPageCursorIsRefused(array $query): void
    {
        [$sara] = $this->acmeFoodsWithSaraOmarAndLena();
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('GET', self::PEOPLE_PAGE, $query);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('notAPageOfConnections')]
    public function anEndWithAPartOrBadConnectionPageCursorIsRefusedAndEndsNothing(array $query): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        $omars = $this->connect($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('POST', \sprintf(self::END, $omars['familyId']), ['_csrf_token' => $this->csrfTokenFor($this->client, self::END_CSRF_ID), ...$query]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
    }

    /**
     * @return array{familyId: string, accessToken: string, refreshToken: string}
     */
    private function connect(User $user, OrganizationId $organizationId, string $connectedAt): array
    {
        return $this->connectWithOAuth($this->client, $this->clock, $user, $organizationId, $this->clientId, self::CALLBACK, $connectedAt);
    }

    private function apiStatusWith(string $accessToken): int
    {
        $this->client->request('GET', self::API_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array{User, OrganizationId, User, User} Sara, the Admin, and Omar and Lena, members, of Acme Foods
     */
    private function acmeFoodsWithSaraOmarAndLena(): array
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $this->addMembership($omar, $acmeFoods);
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);

        return [$sara, $acmeFoods, $omar, $lena];
    }

    /**
     * @return list<string>
     */
    private function connectionIdsOn(Crawler $page): array
    {
        return $page->filter('main .people__connection-row')->each(static fn (Crawler $row): string => (string) $row->attr('data-connection-id'));
    }

    /**
     * Drops the events of the logins and connects, so the outbox holds only what the requests after it publish.
     */
    private function forgetEarlierEvents(): void
    {
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    /**
     * @return list<list<mixed>> actor kind, actor, action, target type, time of each api_token event; the API calls record their own
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, action, target_type, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM aud_event WHERE organization_id = ? AND action LIKE 'api_token.%' ORDER BY at, id",
                [$organizationId->toString()],
            );

            return 0;
        });

        return $rows;
    }
}
