<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Api\OAuthConnectionRefused;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\TokenController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Organization\LogsInAdmins;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Connected apps section of the tokens page, /me/tokens (ADR-0050): a person's own OAuth connections in the
 * organization they work in, and End connection, through the real firewalls, token endpoint and request transaction
 * over HTTPS. The page's clock is fixed on 16 Nov 2026, before the day this was written, as a clock ahead of the wall
 * clock would end the session; the connections are made in March 2099 before the login, as the token resolvers compare
 * with the database clock, so their tokens work on /api/v1 too.
 */
final class ConnectedAppsSectionTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;
    use TurnsOnTwoStepLogin;

    private const string TOKENS_PAGE = '/me/tokens';
    private const string END = '/me/tokens/connections/%s/end';
    private const string API_PROBE = '/api/v1/me/tickets';
    private const string NOW = '2026-11-16 09:00:00 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';

    private KernelBrowser $client;
    private MockClock $clock;
    private string $claudeClientId;
    private string $notesClientId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->claudeClientId = $this->registerClient($this->client, 'Claude', [self::CALLBACK], ['authorization_code', 'refresh_token']);
        $this->notesClientId = $this->registerClient($this->client, 'Notes app', [self::CALLBACK], ['authorization_code', 'refresh_token']);
    }

    protected function tearDown(): void
    {
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->removeOAuthClientsAndCodes();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar works in Acme Foods and is a member of Beta Bakery too; Lena works in Acme Foods. Omar's Claude connection is
     * the older and renewed once; his ended one, Lena's and his own of Beta Bakery are left out. The times show in Omar's
     * time zone, an hour ahead of UTC in March.
     */
    #[Test]
    public function aPersonSeesOnlyTheirOwnLiveConnectionsOfTheOrganizationTheyWorkInNewestFirst(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarInAcmeFoodsAndBetaBakery();
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);
        $olderClaude = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-14 10:00:00 UTC');
        $this->renew($olderClaude['refreshToken'], $this->claudeClientId, '2099-03-15 11:30:00 UTC');
        $newerNotes = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->notesClientId, self::CALLBACK, '2099-03-16 10:00:00 UTC');
        $ended = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->notesClientId, self::CALLBACK, '2099-03-17 10:00:00 UTC');
        $this->readAs($acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE idn_oauth_refresh_token SET revoked_at = '2026-11-15 09:00:00+00' WHERE family_id = ?",
            [$ended['familyId']],
        ));
        $this->connectWithOAuth($this->client, $this->clock, $lena, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-18 10:00:00 UTC');
        $this->connectWithOAuth($this->client, $this->clock, $omar, $betaBakery, $this->claudeClientId, self::CALLBACK, '2099-03-19 10:00:00 UTC');
        $this->logIn($this->client, $omar);
        $this->chooseOrganization($acmeFoods);

        $page = $this->client->request('GET', self::TOKENS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Connected apps', $page->filter('main #connected-apps-title')->text());
        $listed = $this->listedConnectionIds($page);
        self::assertNotSame([], $listed);
        self::assertSame([$newerNotes['familyId'], $olderClaude['familyId']], $listed);
        $notesRow = $this->rowOf($page, $newerNotes['familyId']);
        self::assertSame('Notes app', $notesRow->filter('strong')->text());
        self::assertSame(TokenController::SCOPE_EXPLANATIONS['read'], $notesRow->filter('p')->eq(1)->text());
        self::assertSame('Connected 16 Mar 2099 · Not renewed yet', $notesRow->filter('p')->eq(2)->text());
        $claudeRow = $this->rowOf($page, $olderClaude['familyId']);
        self::assertSame('Claude', $claudeRow->filter('strong')->text());
        self::assertSame('Connected 14 Mar 2099 · Last renewed 15 Mar 2099, 12:30', $claudeRow->filter('p')->eq(2)->text());
        self::assertSame('End connection', $claudeRow->filter('form button[type="submit"]')->text());
        self::assertSame('End connection of Claude', $claudeRow->filter('form button[type="submit"]')->attr('aria-label'));
        self::assertCount(0, $page->filter('main [aria-labelledby="connected-apps-title"] .empty-state'));
    }

    #[Test]
    public function aPersonWithoutLiveConnectionsIsToldSo(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $ended = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-14 10:00:00 UTC');
        $this->readAs($acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE idn_oauth_refresh_token SET revoked_at = '2026-11-15 09:00:00+00' WHERE family_id = ?",
            [$ended['familyId']],
        ));
        $this->logIn($this->client, $omar);

        $page = $this->client->request('GET', self::TOKENS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->listedConnectionIds($page));
        self::assertSame('No apps are connected.', trim($page->filter('main [aria-labelledby="connected-apps-title"] .empty-state')->text()));
    }

    /**
     * The access token works on /api/v1 before; after End connection neither it nor the refresh token does, and the
     * other connection keeps working. A second post, as after a double click, changes nothing and says nothing.
     */
    #[Test]
    public function endingOwnConnectionStopsItsTokensAtOnceAndTakesItOffTheList(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $ended = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-15 10:00:00 UTC');
        $kept = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->notesClientId, self::CALLBACK, '2099-03-14 10:00:00 UTC');
        self::assertSame(200, $this->apiStatusWith($ended['accessToken']), 'the token did not work before, so the 401 after proves nothing');
        $this->logIn($this->client, $omar);
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);
        $csrfToken = $this->endCsrfTokenOf($page, $ended['familyId']);

        $this->client->request('POST', \sprintf(self::END, $ended['familyId']), ['_csrf_token' => $csrfToken]);

        self::assertResponseRedirects(self::TOKENS_PAGE, 302);
        $listPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([TokenController::CONNECTION_ENDED], $listPage->filter('main [role="status"]')->each(static fn (Crawler $notice): string => $notice->text()));
        self::assertSame([$kept['familyId']], $this->listedConnectionIds($listPage));
        self::assertSame(['2026-11-16 09:00:00'], $this->refreshTokensRevokedAtOf($acmeFoods, $ended['familyId']));
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $kept['familyId']));
        self::assertSame(401, $this->apiStatusWith($ended['accessToken']));
        self::assertSame(200, $this->apiStatusWith($kept['accessToken']));
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $ended['refreshToken'], 'client_id' => $this->claudeClientId]);
        self::assertResponseStatusCodeSame(400);

        $this->clock->sleep(60);
        $this->client->request('POST', \sprintf(self::END, $ended['familyId']), ['_csrf_token' => $csrfToken]);

        self::assertResponseRedirects(self::TOKENS_PAGE, 302);
        $listPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $listPage->filter('main [role="status"]'));
        self::assertSame(['2026-11-16 09:00:00'], $this->refreshTokensRevokedAtOf($acmeFoods, $ended['familyId']));
    }

    #[Test]
    public function anEndWithoutAValidCsrfTokenIsRefusedAndTheConnectionStaysLive(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $connection = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-15 10:00:00 UTC');
        $this->logIn($this->client, $omar);
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);
        $createCsrfToken = (string) $page->filter('main form[action="/me/tokens"] input[name="_csrf_token"]')->attr('value');

        foreach (['missing' => [], 'forged' => ['_csrf_token' => 'forged'], 'of the create form' => ['_csrf_token' => $createCsrfToken]] as $case => $fields) {
            $page = $this->client->request('POST', \sprintf(self::END, $connection['familyId']), $fields);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame([LoginController::FORM_EXPIRED], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()), $case);
            self::assertSame([$connection['familyId']], $this->listedConnectionIds($page), $case);
        }

        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $connection['familyId']));
        self::assertSame(200, $this->apiStatusWith($connection['accessToken']));
    }

    /**
     * REACH: Lena, a member who is no Admin, posts with her own valid CSRF token to end Omar's connection of the same
     * organization; the voter refuses with its reason and the connection keeps working.
     */
    #[Test]
    public function anotherMembersEndIsRefusedWithTheReasonAndTheConnectionStaysLive(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);
        $omars = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-15 10:00:00 UTC');
        $lenas = $this->connectWithOAuth($this->client, $this->clock, $lena, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-14 10:00:00 UTC');
        $this->logIn($this->client, $lena);
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$lenas['familyId']], $this->listedConnectionIds($page));

        $page = $this->client->request('POST', \sprintf(self::END, $omars['familyId']), ['_csrf_token' => $this->endCsrfTokenOf($page, $lenas['familyId'])]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([OAuthConnectionRefused::NOT_YOURS], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()));
        self::assertSame([$lenas['familyId']], $this->listedConnectionIds($page));
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $omars['familyId']));
        self::assertSame(200, $this->apiStatusWith($omars['accessToken']));
    }

    /**
     * REACH: Omar's own connection of Beta Bakery while he works in Acme Foods, Mia's of Beta Bakery, an unknown id and
     * a malformed one each get the same 404, and every connection stays live.
     */
    #[Test]
    public function anEndOfAnotherOrganizationsUnknownOrMalformedIdIsNotFoundAndEndsNothing(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarInAcmeFoodsAndBetaBakery();
        $mia = $this->createUserWithoutOrganization('Mia Lund');
        $this->addMembership($mia, $betaBakery);
        $omarsAcme = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-15 10:00:00 UTC');
        $omarsBeta = $this->connectWithOAuth($this->client, $this->clock, $omar, $betaBakery, $this->claudeClientId, self::CALLBACK, '2099-03-14 10:00:00 UTC');
        $miasBeta = $this->connectWithOAuth($this->client, $this->clock, $mia, $betaBakery, $this->claudeClientId, self::CALLBACK, '2099-03-13 10:00:00 UTC');
        $this->logIn($this->client, $omar);
        $this->chooseOrganization($acmeFoods);
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);
        $csrfToken = $this->endCsrfTokenOf($page, $omarsAcme['familyId']);

        foreach (['his own of Beta Bakery' => $omarsBeta['familyId'], 'Mia\'s of Beta Bakery' => $miasBeta['familyId'], 'unknown' => '0197a3c0-0000-7000-8000-000000000fff', 'malformed' => 'not-a-connection-id'] as $case => $familyId) {
            $this->client->request('POST', \sprintf(self::END, $familyId), ['_csrf_token' => $csrfToken]);
            self::assertResponseStatusCodeSame(404, $case);
        }

        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $omarsAcme['familyId']));
        self::assertSame([null], $this->refreshTokensRevokedAtOf($betaBakery, $omarsBeta['familyId']));
        self::assertSame([null], $this->refreshTokensRevokedAtOf($betaBakery, $miasBeta['familyId']));
    }

    /**
     * REACH: the main firewall reads no Bearer header, so the connection's own access token cannot end it.
     */
    #[Test]
    public function aBearerTokenRequestDoesNotReachTheEnd(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $connection = $this->connectWithOAuth($this->client, $this->clock, $omar, $acmeFoods, $this->claudeClientId, self::CALLBACK, '2099-03-15 10:00:00 UTC');

        $this->client->request('POST', \sprintf(self::END, $connection['familyId']), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$connection['accessToken']]);

        self::assertResponseRedirects('/login', 302);
        self::assertSame([null], $this->refreshTokensRevokedAtOf($acmeFoods, $connection['familyId']));
        self::assertSame(200, $this->apiStatusWith($connection['accessToken']));
    }

    /**
     * Trades the refresh token as the client renews, with the clock at $renewedAt, then puts the clock back.
     */
    private function renew(string $refreshToken, string $clientId, string $renewedAt): void
    {
        $wasAt = $this->clock->now();
        $this->clock->modify($renewedAt);
        try {
            $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId]);
            self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        } finally {
            $this->clock->modify($wasAt->format(\DATE_ATOM));
        }
    }

    private function apiStatusWith(string $accessToken): int
    {
        $this->client->request('GET', self::API_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array{User, OrganizationId} Omar, a member of Acme Foods only, so it is chosen at once
     */
    private function omarInAcmeFoods(): array
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);

        return [$omar, $acmeFoods];
    }

    /**
     * @return array{User, OrganizationId, OrganizationId} Omar, a member of Acme Foods and Beta Bakery
     */
    private function omarInAcmeFoodsAndBetaBakery(): array
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $betaBakery);

        return [$omar, $acmeFoods, $betaBakery];
    }

    private function chooseOrganization(OrganizationId $organizationId): void
    {
        $picker = $this->client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        $form = $picker->filter(\sprintf('input[name="organization_id"][value="%s"]', $organizationId->toString()))->closest('form')?->form();
        self::assertNotNull($form);
        $this->client->submit($form);
        self::assertResponseRedirects('/', 302);
    }

    /**
     * @return list<string>
     */
    private function listedConnectionIds(Crawler $page): array
    {
        return $page->filter('main li.api-tokens__row[data-connection-id]')->each(static fn (Crawler $row): string => (string) $row->attr('data-connection-id'));
    }

    private function rowOf(Crawler $page, string $familyId): Crawler
    {
        $row = $page->filter(\sprintf('main li.api-tokens__row[data-connection-id="%s"]', $familyId));
        self::assertCount(1, $row);

        return $row;
    }

    private function endCsrfTokenOf(Crawler $page, string $familyId): string
    {
        return (string) $this->rowOf($page, $familyId)->filter('form input[name="_csrf_token"]')->attr('value');
    }
}
