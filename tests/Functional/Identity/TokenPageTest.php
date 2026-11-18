<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\IssueApiToken;
use App\Identity\Application\RecentAuth;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\TokenController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Organization\LogsInAdmins;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tokens page, /me/tokens (FR-ID-05, P09.T1), through the real firewalls, login form, code page and request
 * transaction over HTTPS. The client keeps its kernel, so the clock set in the container is the one every request
 * reads; it is fixed on 16 Nov 2026, before the day this was written, as a clock ahead of the wall clock would end the
 * session. The tokens seeded through IssuesApiTokens are dated 2099, so they are live by the database clock too, which
 * decides whether a Bearer request gets in.
 */
final class TokenPageTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use TurnsOnTwoStepLogin;

    private const string TOKENS_PAGE = '/me/tokens';
    private const string TOKEN_PROBE = '/mcp/_test/token-probe';
    private const string NOW = '2026-11-16 09:00:00 UTC';
    private const string PLAIN_TOKEN = '/\Apr_[A-Za-z0-9_-]{43}\z/';

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
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar works in Acme Foods and is a member of Beta Bakery too; Lena works in Acme Foods.
     */
    #[Test]
    public function theListShowsOnlyTheViewersLiveTokensOfTheOrganizationTheyWorkInNewestFirst(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarInAcmeFoodsAndBetaBakery();
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);
        [$olderTokenId] = $this->issueApiToken($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        [$newerTokenId] = $this->issueApiToken($omar, $acmeFoods, '2099-03-15 10:00:00 UTC');
        [$revokedTokenId] = $this->issueApiToken($omar, $acmeFoods, '2099-03-16 10:00:00 UTC');
        $this->revokeApiToken($revokedTokenId, $acmeFoods);
        $this->issueApiToken($omar, $acmeFoods, '2026-11-01 09:00:00 UTC', 15);
        $this->issueApiToken($lena, $acmeFoods, '2099-03-17 10:00:00 UTC');
        $this->issueApiToken($omar, $betaBakery, '2099-03-18 10:00:00 UTC');
        $this->logIn($this->client, $omar);
        $this->chooseOrganization($acmeFoods);

        $page = $this->client->request('GET', self::TOKENS_PAGE);

        self::assertResponseStatusCodeSame(200);
        $this->assertNotStored();
        self::assertSelectorTextSame('title', 'Personal access tokens · pinround');
        $listed = $this->listedTokenIds($page);
        self::assertNotSame([], $listed);
        self::assertSame([$newerTokenId, $olderTokenId], $listed);
        $row = $page->filter(\sprintf('main li.api-tokens__row[data-token-id="%s"]', $newerTokenId));
        self::assertStringContainsString('Claude Code', $row->text());
        self::assertStringContainsString('read, rounds:write', $row->text());
        self::assertStringContainsString('Last used never', $row->text());
        self::assertMatchesRegularExpression('/pr_[A-Za-z0-9_-]{8}…/', $row->filter('code')->text());
        self::assertSame(
            array_values(TokenController::SCOPE_EXPLANATIONS),
            $page->filter('main fieldset label')->each(static fn (Crawler $label): string => trim($label->text())),
        );
        self::assertSame(['read', 'rounds:write'], $page->filter('main input[name="scopes[]"][checked]')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertSame('90', $page->filter('main select[name="lifetime_days"] option[selected]')->attr('value'));
        self::assertCount(0, $page->filter('main input[name="new_token"]'));
        self::assertCount(0, $page->filter('main [role="alert"]'));
    }

    #[Test]
    public function aPersonWithoutLiveTokensIsToldSo(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        [$revokedTokenId] = $this->issueApiToken($omar, $acmeFoods);
        $this->revokeApiToken($revokedTokenId, $acmeFoods);
        $this->logIn($this->client, $omar);

        $page = $this->client->request('GET', self::TOKENS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('main li.api-tokens__row'));
        self::assertSame('No tokens that still work.', trim($page->filter('main .empty-state')->text()));
    }

    #[Test]
    public function makingATokenWithoutAProofOfTheLastTenMinutesSendsToConfirmItsYouAndMakesNone(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $csrfToken = $this->createCsrfToken();
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);

        $this->postNewToken(['_csrf_token' => $csrfToken]);

        self::assertResponseRedirects('/me/confirm?return=/me/tokens', 302);
        self::assertSame(0, $this->tokenCountOf($omar, $acmeFoods));
    }

    #[Test]
    public function makingATokenWithoutTwoStepLoginSendsToItsSetupAndMakesNone(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $this->logIn($this->client, $omar);
        $csrfToken = $this->createCsrfToken();

        $this->postNewToken(['_csrf_token' => $csrfToken]);

        self::assertResponseRedirects('/me/security/two-step', 302);
        self::assertSame(0, $this->tokenCountOf($omar, $acmeFoods));
    }

    #[Test]
    public function withARecentProofTheNewTokenIsShownOnceWithTheClaudeCodeCommand(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $csrfToken = $this->createCsrfToken();

        $page = $this->postNewToken(['name' => 'Omar laptop', 'scopes' => ['tickets:move', 'read'], 'lifetime_days' => '30', '_csrf_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(200);
        $this->assertNotStored();
        self::assertCount(0, $page->filter('main [role="alert"]'));
        $plainToken = (string) $page->filter('main input[name="new_token"]')->attr('value');
        self::assertMatchesRegularExpression(self::PLAIN_TOKEN, $plainToken);
        self::assertSame(
            'claude mcp add --transport http pinround https://localhost/mcp --header "Authorization: Bearer '.$plainToken.'"',
            $page->filter('main input[name="claude_mcp_add"]')->attr('value'),
        );
        self::assertSame(2, substr_count((string) $this->client->getResponse()->getContent(), $plainToken), 'the token is in the page outside its two fields');
        $listed = $this->listedTokenIds($page);
        self::assertCount(1, $listed);
        self::assertSame(1, $this->tokenCountOf($omar, $acmeFoods));
        self::assertSame(
            ['name' => 'Omar laptop', 'scopes' => '{read,tickets:move}', 'expires_at' => $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s'), 'is_hash_of_shown_token' => true],
            $this->storedTokenOf($listed[0], $acmeFoods, $plainToken),
        );

        $reloaded = $this->client->request('GET', self::TOKENS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($listed, $this->listedTokenIds($reloaded));
        self::assertCount(0, $reloaded->filter('main input[name="new_token"]'));
        self::assertStringNotContainsString($plainToken, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(substr($plainToken, 11), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function revokingOwnTokenTakesItOffTheListAndItStopsWorkingAtOnce(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        [$revokedTokenId, $revokedToken] = $this->issueApiToken($omar, $acmeFoods, '2099-03-15 10:00:00 UTC');
        [$keptTokenId, $keptToken] = $this->issueApiToken($omar, $acmeFoods, '2099-03-14 10:00:00 UTC');
        $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$revokedToken]);
        self::assertResponseStatusCodeSame(200, 'the token did not work before the revoke, so the 401 after it proves nothing');
        $this->logIn($this->client, $omar);
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/me/tokens/'.$revokedTokenId.'/revoke', ['_csrf_token' => $this->revokeCsrfTokenOf($page, $revokedTokenId)]);

        self::assertResponseRedirects(self::TOKENS_PAGE, 302);
        $this->assertNotStored();
        $listPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([TokenController::TOKEN_REVOKED], $listPage->filter('main [role="status"]')->each(static fn (Crawler $notice): string => $notice->text()));
        self::assertSame([$keptTokenId], $this->listedTokenIds($listPage));
        self::assertSame('2026-11-16 09:00:00', $this->usageOf($revokedTokenId, $acmeFoods)['revoked_at']);
        self::assertNull($this->usageOf($keptTokenId, $acmeFoods)['revoked_at']);

        $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$revokedToken]);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$keptToken]);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * As after a double click: the second post finds the token, revokes nothing and says nothing, and the first time stays.
     */
    #[Test]
    public function revokingATokenAgainKeepsTheFirstTimeAndShowsNoNotice(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        [$tokenId] = $this->issueApiToken($omar, $acmeFoods);
        $this->logIn($this->client, $omar);
        $csrfToken = $this->revokeCsrfTokenOf($this->client->request('GET', self::TOKENS_PAGE), $tokenId);
        $this->client->request('POST', '/me/tokens/'.$tokenId.'/revoke', ['_csrf_token' => $csrfToken]);
        self::assertResponseRedirects(self::TOKENS_PAGE, 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->clock->sleep(60);

        $this->client->request('POST', '/me/tokens/'.$tokenId.'/revoke', ['_csrf_token' => $csrfToken]);

        self::assertResponseRedirects(self::TOKENS_PAGE, 302);
        $listPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $listPage->filter('main [role="status"]'));
        self::assertSame('2026-11-16 09:00:00', $this->usageOf($tokenId, $acmeFoods)['revoked_at']);
    }

    /**
     * Lena's token of Acme Foods, Omar's own of Beta Bakery while he works in Acme Foods, an unknown id and a malformed
     * one: each gets the same 404, and every token stays live.
     */
    #[Test]
    public function revokingATokenThatIsNotTheViewersInTheOrganizationIsNotFoundAndRevokesNothing(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarInAcmeFoodsAndBetaBakery();
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);
        [$omarsTokenId] = $this->issueApiToken($omar, $acmeFoods);
        [$lenasTokenId] = $this->issueApiToken($lena, $acmeFoods);
        [$omarsBetaTokenId] = $this->issueApiToken($omar, $betaBakery);
        $this->logIn($this->client, $omar);
        $this->chooseOrganization($acmeFoods);
        $csrfToken = $this->revokeCsrfTokenOf($this->client->request('GET', self::TOKENS_PAGE), $omarsTokenId);

        foreach (['Lena\'s' => $lenasTokenId, 'his own of Beta Bakery' => $omarsBetaTokenId, 'unknown' => '0197a3c0-0000-7000-8000-000000000fff', 'malformed' => 'not-a-token-id'] as $case => $tokenId) {
            $this->client->request('POST', '/me/tokens/'.$tokenId.'/revoke', ['_csrf_token' => $csrfToken]);
            self::assertResponseStatusCodeSame(404, $case);
        }

        self::assertNull($this->usageOf($omarsTokenId, $acmeFoods)['revoked_at']);
        self::assertNull($this->usageOf($lenasTokenId, $acmeFoods)['revoked_at']);
        self::assertNull($this->usageOf($omarsBetaTokenId, $betaBakery)['revoked_at']);
        self::assertSame([$omarsTokenId], $this->listedTokenIds($this->client->request('GET', self::TOKENS_PAGE)));
    }

    #[Test]
    public function aRevokeWithoutAValidCsrfTokenIsRefusedAndTheTokenStaysLive(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        [$tokenId] = $this->issueApiToken($omar, $acmeFoods);
        $this->logIn($this->client, $omar);
        $createCsrfToken = $this->createCsrfToken();

        foreach (['missing' => [], 'forged' => ['_csrf_token' => 'forged'], 'of the create form' => ['_csrf_token' => $createCsrfToken]] as $case => $fields) {
            $page = $this->client->request('POST', '/me/tokens/'.$tokenId.'/revoke', $fields);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame([LoginController::FORM_EXPIRED], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()), $case);
            self::assertSame([$tokenId], $this->listedTokenIds($page), $case);
        }

        self::assertNull($this->usageOf($tokenId, $acmeFoods)['revoked_at']);
    }

    /**
     * The main firewall reads no Bearer header, so the request is anonymous and sent to the login page, and the token's
     * use is not recorded.
     */
    #[Test]
    public function aBearerTokenRequestDoesNotReachThePage(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        [$tokenId, $token] = $this->issueApiToken($omar, $acmeFoods);
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];

        $this->client->request('GET', self::TOKENS_PAGE, server: $bearer);
        self::assertResponseRedirects('/login', 302);
        $this->client->request('POST', self::TOKENS_PAGE, ['name' => 'Made by a token', 'scopes' => ['read'], 'lifetime_days' => '30'], server: $bearer);
        self::assertResponseRedirects('/login', 302);
        $this->client->request('POST', '/me/tokens/'.$tokenId.'/revoke', server: $bearer);
        self::assertResponseRedirects('/login', 302);

        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($tokenId, $acmeFoods));
        self::assertSame(1, $this->tokenCountOf($omar, $acmeFoods));
    }

    /**
     * Twenty live tokens, and a revoked and an expired one that do not count.
     */
    #[Test]
    public function theTwentyFirstLiveTokenIsRefusedWithAPlainMessage(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        for ($i = 0; $i < IssueApiToken::MAX_LIVE_TOKENS_PER_USER; ++$i) {
            $this->issueApiToken($omar, $acmeFoods, \sprintf('2099-03-15 10:%02d:00 UTC', $i));
        }
        [$revokedTokenId] = $this->issueApiToken($omar, $acmeFoods);
        $this->revokeApiToken($revokedTokenId, $acmeFoods);
        $this->issueApiToken($omar, $acmeFoods, '2026-11-01 09:00:00 UTC', 15);
        $this->logInWithACode($this->client, $this->clock, $omar);
        $csrfToken = $this->createCsrfToken();

        $page = $this->postNewToken(['name' => 'One too many', 'scopes' => ['read'], 'lifetime_days' => '30', '_csrf_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('You have 20 tokens that still work. Revoke one before you make another.', $page->filter('main #create-token-error')->text());
        self::assertCount(0, $page->filter('main input[name="new_token"]'));
        self::assertCount(IssueApiToken::MAX_LIVE_TOKENS_PER_USER, $this->listedTokenIds($page));
        self::assertSame(IssueApiToken::MAX_LIVE_TOKENS_PER_USER + 2, $this->tokenCountOf($omar, $acmeFoods));
    }

    #[Test]
    public function aChoiceThePageDoesNotOfferIsRefusedWithAMessageAndMakesNoToken(): void
    {
        [$omar, $acmeFoods] = $this->omarInAcmeFoods();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $csrfToken = $this->createCsrfToken();
        $valid = ['name' => 'Omar laptop', 'scopes' => ['read'], 'lifetime_days' => '30', '_csrf_token' => $csrfToken];
        $refusals = [
            'an empty name' => [['name' => ''], 'Give the token a name.'],
            'a name of spaces only' => [['name' => " \u{00A0} "], 'Give the token a name.'],
            'a name of 101 characters' => [['name' => str_repeat('é', 101)], 'A token name has at most 100 characters.'],
            'a name with an escape character' => [['name' => "Omar \e[31mlaptop"], 'A token name is one line of plain text.'],
            'a name with a line break' => [['name' => "Omar\r\nlaptop"], 'A token name is one line of plain text.'],
            'a name with a line separator' => [['name' => "Omar\u{2028}laptop"], 'A token name is one line of plain text.'],
            'a name that is not UTF-8' => [['name' => "Omar \xC3\x28"], 'A token name is one line of plain text.'],
            'no scope' => [['scopes' => []], 'Choose at least one thing the token may do.'],
            'an unknown scope' => [['scopes' => ['read', 'admin']], IssueApiToken::UNKNOWN_SCOPE],
            'a scope in capitals' => [['scopes' => ['READ']], IssueApiToken::UNKNOWN_SCOPE],
            'a scope that is a list' => [['scopes' => ['read', ['rounds:write']]], IssueApiToken::UNKNOWN_SCOPE],
            'a lifetime not offered' => [['lifetime_days' => '45'], TokenController::LIFETIME_NOT_OFFERED],
            'a lifetime with a space' => [['lifetime_days' => '30 '], TokenController::LIFETIME_NOT_OFFERED],
            'no lifetime' => [['lifetime_days' => ''], TokenController::LIFETIME_NOT_OFFERED],
            'no CSRF token' => [['_csrf_token' => ''], LoginController::FORM_EXPIRED],
            'a forged CSRF token' => [['_csrf_token' => 'forged'], LoginController::FORM_EXPIRED],
        ];

        foreach ($refusals as $case => [$change, $message]) {
            $page = $this->postNewToken([...$valid, ...$change]);

            self::assertResponseStatusCodeSame(422, $case);
            $this->assertNotStored();
            self::assertSame($message, $page->filter('main #create-token-error')->text(), $case);
            self::assertCount(0, $page->filter('main input[name="new_token"]'), $case);
        }

        self::assertSame(0, $this->tokenCountOf($omar, $acmeFoods));
        $this->postNewToken($valid);
        self::assertResponseStatusCodeSame(200, 'the valid choice is refused too, so the refusals above prove nothing');
        self::assertSame(1, $this->tokenCountOf($omar, $acmeFoods));
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
     * Opens the page, as a person does before they post its form.
     */
    private function createCsrfToken(): string
    {
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);

        return (string) $page->filter('main form[action="/me/tokens"] input[name="_csrf_token"]')->attr('value');
    }

    private function revokeCsrfTokenOf(Crawler $page, string $tokenId): string
    {
        return (string) $page->filter(\sprintf('main li.api-tokens__row[data-token-id="%s"] form input[name="_csrf_token"]', $tokenId))->attr('value');
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function postNewToken(array $fields): Crawler
    {
        return $this->client->request('POST', self::TOKENS_PAGE, $fields, server: ['HTTP_REFERER' => 'https://localhost/me/tokens']);
    }

    /**
     * @return list<string>
     */
    private function listedTokenIds(Crawler $page): array
    {
        return $page->filter('main li.api-tokens__row')->each(static fn (Crawler $row): string => (string) $row->attr('data-token-id'));
    }

    private function tokenCountOf(User $user, OrganizationId $organizationId): int
    {
        $count = $this->inTokenTransactionAs($organizationId, fn (): mixed => $this->tokenEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM idn_api_token WHERE user_id = ?',
            [$user->id()->toString()],
        ));
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return array{name: mixed, scopes: mixed, expires_at: mixed, is_hash_of_shown_token: bool}
     */
    private function storedTokenOf(string $tokenId, OrganizationId $organizationId, string $plainToken): array
    {
        $row = $this->inTokenTransactionAs($organizationId, fn (): array|false => $this->tokenEntityManager()->getConnection()->fetchAssociative(
            "SELECT name, scopes::text AS scopes, to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at, token_hash
             FROM idn_api_token WHERE id = ?",
            [$tokenId],
        ));
        self::assertIsArray($row, 'no token has the id '.$tokenId);
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $storedHash = \is_resource($row['token_hash']) ? stream_get_contents($row['token_hash']) : $row['token_hash'];

        return ['name' => $row['name'], 'scopes' => $row['scopes'], 'expires_at' => $row['expires_at'], 'is_hash_of_shown_token' => $hashing->hash($plainToken) === $storedHash];
    }

    private function assertNotStored(): void
    {
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }
}
