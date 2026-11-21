<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\AuthorizeOAuthClient;
use App\Identity\Application\RecentAuth;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\OAuthConsentController;
use App\Identity\UI\Controller\TokenController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Organization\LogsInAdmins;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The OAuth consent page, GET and POST /oauth/authorize (ADR-0050, P14.T20), through the real firewall, two-step login,
 * "Confirm it's you", CSRF check and database. The client registers through POST /oauth/register first. The clock is
 * fixed on 17 Nov 2026, before the day this was written, as a clock ahead of the wall clock would end the session.
 * Omar is a member of Acme Foods and Beta Bakery, left Dune Deli, and was never in Lena's Cedar Market; no organization
 * is chosen in his session, so the page must not send him to the picker.
 */
final class OAuthConsentTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;
    use TurnsOnTwoStepLogin;

    private const string NOW = '2026-11-17 09:00:00 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    private const string LOOPBACK_CALLBACK = 'http://127.0.0.1/callback';
    /** The S256 challenge of RFC 7636 Appendix B. */
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const string STATE = 'xyz-state+/= &?';

    private KernelBrowser $client;
    private MockClock $clock;
    private string $clientId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->clientId = $this->registerClient($this->client, 'Claude', [self::CALLBACK, self::LOOPBACK_CALLBACK]);
    }

    protected function tearDown(): void
    {
        $this->removeOAuthClientsAndCodes();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theConsentPageShowsTheAppWhereItSendsThePersonWhatItMayDoAndOnlyTheirCurrentOrganizations(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);

        $page = $this->client->request('GET', $this->authorizePath(['scope' => 'read tickets:move']));

        self::assertResponseStatusCodeSame(200);
        $this->assertNotStored();
        self::assertSelectorTextSame('title', 'Connect an app · pinround');
        self::assertSame('Connect Claude to pinround?', $page->filter('main h1')->text());
        self::assertSame('claude.example', $page->filter('main p.login__hint strong')->text());
        self::assertSame(
            [TokenController::SCOPE_EXPLANATIONS['read'], TokenController::SCOPE_EXPLANATIONS['tickets:move']],
            $page->filter('main form ul li')->each(static fn (Crawler $item): string => $item->text()),
        );
        $choices = $page->filter('main input[type="radio"][name="organization_id"]')->each(static fn (Crawler $radio): array => [
            (string) $radio->attr('value'),
            trim((string) $radio->closest('label')?->text()),
            null !== $radio->attr('checked'),
        ]);
        self::assertSame([[$acmeFoods->toString(), 'Acme Foods', false], [$betaBakery->toString(), 'Beta Bakery', false]], $choices);
        self::assertSame(['Allow', 'Deny'], $page->filter('main form button[type="submit"]')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount(0, $page->filter('main [role="alert"]'));
        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function allowIssuesOneCodeOfThePickedOrganizationAndSendsItWithTheStateToTheRedirectUriStoringOnlyItsHash(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $form = $this->consentForm($this->authorizePath(['scope' => 'read tickets:move']), 'Allow');
        $form->setValues(['organization_id' => $acmeFoods->toString()]);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        $this->assertNotStored();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::CALLBACK.'?code=', $location);
        $answer = self::queryOf($location);
        self::assertSame(['code', 'state'], array_keys($answer));
        self::assertSame(self::STATE, $answer['state']);
        $plainCode = $answer['code'];
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $plainCode);
        $stored = $this->storedCodesOf($acmeFoods);
        self::assertCount(1, $stored);
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        self::assertSame(bin2hex($hashing->hash($plainCode)), $stored[0]['code_hash_hex']);
        self::assertSame(
            [
                'client_id' => $this->clientId,
                'redirect_uri' => self::CALLBACK,
                'code_challenge' => self::CHALLENGE,
                'scopes' => '{read,tickets:move}',
                'user_id' => $omar->id()->toString(),
                'created_at' => '2026-11-17 09:00:30',
                'expires_at' => '2026-11-17 09:01:30',
                'used_at' => null,
            ],
            array_diff_key($stored[0], ['code_hash_hex' => true, 'row_text' => true]),
        );
        self::assertIsString($stored[0]['row_text']);
        self::assertStringNotContainsString($plainCode, $stored[0]['row_text']);
        self::assertSame([], $this->storedCodesOf($betaBakery));
    }

    #[Test]
    public function aLoopbackRedirectUriWithAnyPortGetsTheCodeOnThatPort(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $form = $this->consentForm($this->authorizePath(['redirect_uri' => 'http://127.0.0.1:53127/callback']), 'Allow');
        $form->setValues(['organization_id' => $acmeFoods->toString()]);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('http://127.0.0.1:53127/callback?code=', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('http://127.0.0.1:53127/callback', $this->storedCodesOf($acmeFoods)[0]['redirect_uri'] ?? null);
    }

    #[Test]
    public function anUnknownClientOrAChangedOrUnregisteredRedirectUriGetsAnErrorPageWithNoRedirectAndNoCode(): void
    {
        [$omar] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $cases = [
            'an unknown client' => [['client_id' => str_repeat('a', 32)], AuthorizeOAuthClient::UNKNOWN_CLIENT],
            'no client' => [['client_id' => null], AuthorizeOAuthClient::UNKNOWN_CLIENT],
            'an unregistered redirect URI' => [['redirect_uri' => 'https://evil.example/cb'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI],
            'one character changed' => [['redirect_uri' => 'https://claude.example/api/mcp/auth_callbacc'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI],
            'a trailing slash' => [['redirect_uri' => self::CALLBACK.'/'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI],
            'another case' => [['redirect_uri' => 'https://claude.example/api/mcp/Auth_callback'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI],
            'no redirect URI' => [['redirect_uri' => null], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI],
            'a bad challenge as well' => [['redirect_uri' => 'https://evil.example/cb', 'code_challenge_method' => 'plain'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI],
        ];

        foreach ($cases as $case => [$change, $message]) {
            $page = $this->client->request('GET', $this->authorizePath($change));

            self::assertResponseStatusCodeSame(400, $case);
            self::assertFalse($this->client->getResponse()->headers->has('Location'), $case);
            self::assertSame([$message], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()), $case);
            self::assertCount(0, $page->filter('main form'), $case);
        }

        self::assertSame(0, $this->storedCodeCount());
    }

    /**
     * The form of a good request, posted with its redirect URI changed: the POST checks it again.
     */
    #[Test]
    public function anAllowPostedWithAChangedRedirectUriGetsAnErrorPageAndNoCode(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $form = $this->consentForm($this->authorizePath(), 'Allow');
        $form->setValues(['organization_id' => $acmeFoods->toString(), 'redirect_uri' => 'https://evil.example/cb']);

        $page = $this->client->submit($form);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->client->getResponse()->headers->has('Location'));
        self::assertSame([AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()));
        self::assertSame(0, $this->storedCodeCount());
    }

    /**
     * A self-registered client may put any https address it likes in its link, so a bad request must not send the
     * person there on its own (RFC 9700, section 4.11.2): pinround's page names the host and the way back is a link.
     */
    #[Test]
    public function aRequestWithoutPkceS256OrWithAnotherResponseTypeShowsAnErrorPageWithALinkBackAndNoRedirectOrCode(): void
    {
        [$omar] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $cases = [
            'a plain challenge' => [['code_challenge_method' => 'plain'], 'invalid_request', 'PKCE is required: code_challenge_method is S256.'],
            'no challenge method' => [['code_challenge_method' => null], 'invalid_request', 'PKCE is required: code_challenge_method is S256.'],
            'no challenge' => [['code_challenge' => null], 'invalid_request', 'PKCE is required: code_challenge is 43 to 128 characters of A-Z, a-z, 0-9, ., _, ~ and -.'],
            'a challenge too short' => [['code_challenge' => 'short'], 'invalid_request', 'PKCE is required: code_challenge is 43 to 128 characters of A-Z, a-z, 0-9, ., _, ~ and -.'],
            'response type token' => [['response_type' => 'token'], 'unsupported_response_type', 'Only response_type code is supported.'],
            'no response type' => [['response_type' => null], 'invalid_request', 'response_type is code.'],
            'an unknown scope' => [['scope' => 'read admin'], 'invalid_scope', 'scope is one or more of: read rounds:write comments:write tickets:move reports:write notebooks:write.'],
        ];

        foreach ($cases as $case => [$change, $error, $description]) {
            $page = $this->client->request('GET', $this->authorizePath($change));

            self::assertResponseStatusCodeSame(400, $case);
            $this->assertNotStored();
            self::assertFalse($this->client->getResponse()->headers->has('Location'), $case);
            self::assertSame('This app cannot connect', $page->filter('main h1')->text(), $case);
            self::assertSame([OAuthConsentController::CLIENT_REQUEST_REFUSED], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()), $case);
            self::assertSame("For the app's developer: ".$description, $page->filter('main p.login__hint')->text(), $case);
            self::assertCount(0, $page->filter('main form'), $case);
            $goBack = $page->selectLink('Go back to claude.example');
            self::assertCount(1, $goBack, $case);
            self::assertSame('noreferrer', $goBack->attr('rel'), $case);
            $goBackUrl = (string) $goBack->attr('href');
            self::assertStringStartsWith(self::CALLBACK.'?error=', $goBackUrl, $case);
            self::assertSame(['error' => $error, 'error_description' => $description, 'state' => self::STATE], self::queryOf($goBackUrl), $case);
        }

        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function aStateThatBreaksItsRuleIsLeftOutOfTheLinkBack(): void
    {
        [$omar] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);

        $page = $this->client->request('GET', $this->authorizePath(['state' => str_repeat('s', AuthorizeOAuthClient::MAX_STATE_LENGTH + 1)]));

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->client->getResponse()->headers->has('Location'));
        self::assertSame(
            ['error' => 'invalid_request', 'error_description' => 'state is 1 to 500 printable ASCII characters.'],
            self::queryOf((string) $page->selectLink('Go back to claude.example')->attr('href')),
        );
        self::assertSame(0, $this->storedCodeCount());
    }

    /**
     * The error page of an unknown client or an unregistered address offers no way to it, only Home.
     */
    #[Test]
    public function anErrorPageWithoutARegisteredRedirectUriHasNoLinkBack(): void
    {
        [$omar] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);

        $page = $this->client->request('GET', $this->authorizePath(['redirect_uri' => 'https://evil.example/cb', 'response_type' => 'token']));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['Go to Home'], $page->filter('main a')->each(static fn (Crawler $link): string => $link->text()));
        self::assertCount(0, $page->filter('main p.login__hint'));
        self::assertStringNotContainsString('evil.example', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAllowPostedWithAPlainChallengeGoesBackWithTheErrorAndNoCode(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $form = $this->consentForm($this->authorizePath(), 'Allow');
        $form->setValues(['organization_id' => $acmeFoods->toString(), 'code_challenge_method' => 'plain']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        self::assertSame(['error' => 'invalid_request', 'error_description' => 'PKCE is required: code_challenge_method is S256.', 'state' => self::STATE], self::queryOf((string) $this->client->getResponse()->headers->get('Location')));
        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function denySendsAccessDeniedWithTheStateAndIssuesNoCode(): void
    {
        [$omar] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);

        $this->client->submit($this->consentForm($this->authorizePath(), 'Deny'));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::CALLBACK.'?error=access_denied', $location);
        self::assertSame(['error' => 'access_denied', 'state' => self::STATE], self::queryOf($location));
        self::assertSame(0, $this->storedCodeCount());
    }

    /**
     * Cedar Market is Lena's, Dune Deli the one Omar left; then an unknown id and a malformed one.
     */
    #[Test]
    public function anAllowForAnOrganizationThePersonIsNotInIsNotFoundAndIssuesNoCode(): void
    {
        [$omar, , , $cedarMarket, $duneDeli] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $form = $this->consentForm($this->authorizePath(), 'Allow');
        $fields = $form->getPhpValues();

        foreach (['another person\'s' => $cedarMarket->toString(), 'one he left' => $duneDeli->toString(), 'unknown' => '0197a3c0-0000-7000-8000-000000000fff', 'malformed' => 'not-an-organization', 'none' => ''] as $case => $organizationId) {
            $this->client->request('POST', '/oauth/authorize', ['organization_id' => $organizationId] + $fields);

            self::assertResponseStatusCodeSame(404, $case);
            self::assertFalse($this->client->getResponse()->headers->has('Location'), $case);
        }

        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenShowsThePageAgainWith422AndIssuesNoCode(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $fields = $this->consentForm($this->authorizePath(), 'Allow')->getPhpValues();
        $fields['organization_id'] = $acmeFoods->toString();
        $pickerCsrfToken = $this->client->request('GET', '/org/choose')->filter('main input[name="_csrf_token"]')->first()->attr('value');
        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($pickerCsrfToken);

        foreach (['missing' => null, 'forged' => 'forged', 'of another form' => $pickerCsrfToken] as $case => $csrfToken) {
            foreach (['allow', 'deny'] as $decision) {
                $page = $this->client->request('POST', '/oauth/authorize', ['_csrf_token' => $csrfToken, 'decision' => $decision] + $fields);

                self::assertResponseStatusCodeSame(422, $case.', '.$decision);
                self::assertFalse($this->client->getResponse()->headers->has('Location'), $case);
                self::assertSame([LoginController::FORM_EXPIRED], $page->filter('main [role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()), $case);
                self::assertCount(1, $page->filter('main form input[name="_csrf_token"]'), $case);
            }
        }

        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function withoutARecentTwoStepProofThePageAndItsAllowSendToConfirmItsYouAndIssueNoCode(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $this->logInWithACode($this->client, $this->clock, $omar);
        $form = $this->consentForm($this->authorizePath(), 'Allow');
        $form->setValues(['organization_id' => $acmeFoods->toString()]);
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);

        $authorizePath = $this->authorizePath();
        $this->client->request('GET', $authorizePath);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/me/confirm?', $location);
        $returnPath = self::queryOf($location)['return'] ?? '';
        self::assertStringStartsWith('/oauth/authorize?', $returnPath);
        self::assertSame(self::queryOf($authorizePath), self::queryOf($returnPath));

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('/me/confirm?', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function withoutTwoStepLoginThePageSendsToItsSetupAndIssuesNoCode(): void
    {
        [$omar] = $this->omarAndHisOrganizations();
        $this->logInWithThePassword($this->client, $omar);

        $this->client->request('GET', $this->authorizePath());

        self::assertResponseRedirects('/me/security/two-step', 302);
        self::assertSame(0, $this->storedCodeCount());
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPageAndGetsNoCode(): void
    {
        [, $acmeFoods] = $this->omarAndHisOrganizations();

        $this->client->request('GET', $this->authorizePath());
        self::assertResponseRedirects('/login', 302);
        $this->client->request('POST', '/oauth/authorize', [
            'response_type' => 'code', 'client_id' => $this->clientId, 'redirect_uri' => self::CALLBACK, 'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256', 'state' => self::STATE, 'organization_id' => $acmeFoods->toString(), 'decision' => 'allow',
        ]);
        self::assertResponseRedirects('/login', 302);

        self::assertSame(0, $this->storedCodeCount());
    }

    /**
     * @return array{User, OrganizationId, OrganizationId, OrganizationId, OrganizationId} Omar, Acme Foods, Beta
     *                                                                                     Bakery, Cedar Market and
     *                                                                                     Dune Deli
     */
    private function omarAndHisOrganizations(): array
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $cedarMarket = $this->createOrganization('Cedar Market');
        $duneDeli = $this->createOrganization('Dune Deli');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);
        $this->addMembership($omar, $duneDeli);
        $this->removeMembership($omar, $duneDeli);
        $this->addMembership($lena, $cedarMarket);

        return [$omar, $acmeFoods, $betaBakery, $cedarMarket, $duneDeli];
    }

    /**
     * @param array<string, string|null> $change a null value leaves the parameter out
     */
    private function authorizePath(array $change = []): string
    {
        $parameters = array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => self::STATE,
            ...$change,
        ], static fn (?string $value): bool => null !== $value);

        return '/oauth/authorize?'.http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);
    }

    private function consentForm(string $authorizePath, string $button): Form
    {
        $page = $this->client->request('GET', $authorizePath);
        self::assertResponseStatusCodeSame(200);

        return $page->selectButton($button)->form();
    }

    /**
     * @return list<array<string, mixed>> the codes stored in the organization, with their hash in hex and the whole row
     *                                    as JSON text
     */
    private function storedCodesOf(OrganizationId $organizationId): array
    {
        return $this->readAs($organizationId, static fn (Connection $migrator): array => $migrator->fetchAllAssociative(
            "SELECT row_to_json(c)::text AS row_text, encode(code_hash, 'hex') AS code_hash_hex, client_id, redirect_uri, code_challenge,
                    scopes::text AS scopes, user_id::text AS user_id,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at,
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at, used_at
             FROM idn_oauth_authorization_code c",
        ));
    }

    /**
     * The codes of every organization this test made, so a code saved under the wrong one is counted too.
     */
    private function storedCodeCount(): int
    {
        self::assertNotSame([], $this->createdOrganizationIds);
        $count = 0;
        foreach ($this->createdOrganizationIds as $organizationId) {
            $count += \count($this->storedCodesOf(new OrganizationId($organizationId)));
        }

        return $count;
    }

    /**
     * @return array<string, string>
     */
    private static function queryOf(string $url): array
    {
        $query = parse_url($url, \PHP_URL_QUERY);
        self::assertIsString($query);
        $fields = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            $fields[rawurldecode($name)] = rawurldecode($value);
        }

        return $fields;
    }

    private function assertNotStored(): void
    {
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }
}
