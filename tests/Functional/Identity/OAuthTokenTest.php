<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\ExchangeOAuthAuthorizationCode;
use App\Identity\Application\RefreshOAuthTokens;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\OAuthTokenController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Organization\LogsInAdmins;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * The token endpoint, POST /oauth/token, and the revocation endpoint, POST /oauth/revoke (ADR-0050), through the real
 * firewall and database; the access tokens they give are tried on the test-only probe /mcp/_test/token-probe. The
 * resolvers of codes, refresh tokens and access tokens compare expiry with the database clock, so the codes are issued
 * on 20 Mar 2099 and the requests run with the clock frozen then; the journey through the real consent page starts on
 * 17 Nov 2026, as a clock ahead of the wall clock would end the session, and its code is moved to 2099 before the trade.
 * Omar is a member of Acme Foods and Beta Bakery.
 */
final class OAuthTokenTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;
    use TurnsOnTwoStepLogin;

    private const string NOW = '2099-03-20 09:00:00 UTC';
    private const string CONSENT_DAY = '2026-11-17 09:00:00 UTC';
    private const string TOKEN_PROBE = '/mcp/_test/token-probe';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    private const string OTHER_CALLBACK = 'http://127.0.0.1/callback';
    /** The verifier and S256 challenge of RFC 7636 Appendix B. */
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const string ACCESS_TOKEN_SHAPE = '/\Apr_[A-Za-z0-9_-]{43}\z/';
    private const string REFRESH_TOKEN_SHAPE = '/\Aprr_[A-Za-z0-9_-]{43}\z/';

    private KernelBrowser $client;
    private MockClock $clock;
    private string $clientId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->clientId = $this->registerClient($this->client, 'Claude', [self::CALLBACK, self::OTHER_CALLBACK], ['authorization_code', 'refresh_token']);
    }

    protected function tearDown(): void
    {
        $this->removeOAuthClientsAndCodes();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aCodeFromTheConsentPageIsTradedForAnAccessTokenThatWorksAsThePersonInThePickedOrganization(): void
    {
        [$omar, $acmeFoods, $betaBakery] = $this->omarAndHisOrganizations();
        $this->clock->modify(self::CONSENT_DAY);
        $this->logInWithACode($this->client, $this->clock, $omar);
        $consentPage = $this->client->request('GET', '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => 'read tickets:move',
        ], '', '&', \PHP_QUERY_RFC3986));
        self::assertResponseStatusCodeSame(200);
        $form = $consentPage->selectButton('Allow')->form();
        $form->setValues(['organization_id' => $betaBakery->toString()]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('~\A'.preg_quote(self::CALLBACK, '~').'\?code=([A-Za-z0-9_-]{43})&state=xyz\z~', $location, $match), $location);
        $code = $match[1];
        // The resolver reads the database clock, which is past 17 Nov 2026; the code keeps its 60 seconds.
        $this->readAs($betaBakery, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE idn_oauth_authorization_code SET created_at = '2099-03-20 09:00:00+00', expires_at = '2099-03-20 09:01:00+00'",
        ));
        $this->clock->modify('2099-03-20 09:00:30 UTC');

        $answer = $this->requestTokens(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $this->clientId, 'code_verifier' => self::VERIFIER]);

        self::assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($answer));
        self::assertSame(['Bearer', 3600, 'read tickets:move'], [$answer['token_type'], $answer['expires_in'], $answer['scope']]);
        self::assertSame('no-cache', $this->client->getResponse()->headers->get('Pragma'));
        $accessToken = self::stringOf($answer, 'access_token');
        self::assertMatchesRegularExpression(self::ACCESS_TOKEN_SHAPE, $accessToken);
        self::assertMatchesRegularExpression(self::REFRESH_TOKEN_SHAPE, self::stringOf($answer, 'refresh_token'));
        $actor = $this->probeWith($accessToken);
        self::assertSame(
            ['userId' => $omar->id()->toString(), 'organizationId' => $betaBakery->toString(), 'scopes' => ['read', 'tickets:move'], 'visibleTokenOrganizationIds' => [$betaBakery->toString()]],
            array_diff_key($actor, ['tokenId' => true]),
        );
        self::assertSame(['access' => 0, 'refresh' => 0], $this->tokenRowsOf($acmeFoods));
        self::assertSame(['access' => 1, 'refresh' => 1], $this->tokenRowsOf($betaBakery));
        self::assertSame('2099-03-20 09:00:30', $this->codeUsedAt($betaBakery));
    }

    /**
     * REACH: a request with the code and the wrong verifier, or another redirect_uri or client_id, is refused, and the
     * code is used up by it, so the right request after it is refused too.
     */
    #[Test]
    public function aCodeTradedWithTheWrongVerifierRedirectUriOrClientIsRefusedIssuesNothingAndIsUsedUp(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $otherClientId = $this->registerClient($this->client, 'Other app', [self::CALLBACK], ['authorization_code', 'refresh_token']);
        $cases = [
            'a wrong verifier' => [['code_verifier' => str_repeat('a', 43)], ExchangeOAuthAuthorizationCode::WRONG_VERIFIER],
            'another redirect_uri' => [['redirect_uri' => self::OTHER_CALLBACK], ExchangeOAuthAuthorizationCode::OTHER_REDIRECT_URI],
            'a redirect_uri with a query' => [['redirect_uri' => 'https://claude.example/api/mcp/auth_callback?x=1'], ExchangeOAuthAuthorizationCode::OTHER_REDIRECT_URI],
            'another client_id' => [['client_id' => $otherClientId], ExchangeOAuthAuthorizationCode::OTHER_CLIENT],
        ];

        foreach ($cases as $case => [$change, $description]) {
            $code = $this->issueCode($omar, $acmeFoods);

            $this->assertTokenRequestRefused([...$this->codeTrade($code), ...$change], 'invalid_grant', $description, $case);
            $this->assertTokenRequestRefused($this->codeTrade($code), 'invalid_grant', ExchangeOAuthAuthorizationCode::UNUSABLE_CODE, $case.', then the right request');
        }

        self::assertSame(['access' => 0, 'refresh' => 0], $this->tokenRowsOf($acmeFoods));
    }

    #[Test]
    public function aCodeIsTradedOnceAndNeverAgain(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $code = $this->issueCode($omar, $acmeFoods);
        $this->requestTokens($this->codeTrade($code));

        $this->assertTokenRequestRefused($this->codeTrade($code), 'invalid_grant', ExchangeOAuthAuthorizationCode::UNUSABLE_CODE, 'reused');

        self::assertSame(['access' => 1, 'refresh' => 1], $this->tokenRowsOf($acmeFoods));
    }

    /**
     * The database clock still finds the code; the request's own clock says it is over.
     */
    #[Test]
    public function aCodeTradedFromTheSecondItExpiresIsRefusedAndStaysUnused(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $code = $this->issueCode($omar, $acmeFoods);
        $this->clock->sleep(OAuthAuthorizationCode::MAX_LIFETIME_SECONDS);

        $this->assertTokenRequestRefused($this->codeTrade($code), 'invalid_grant', ExchangeOAuthAuthorizationCode::UNUSABLE_CODE, 'expired');

        self::assertSame(['access' => 0, 'refresh' => 0], $this->tokenRowsOf($acmeFoods));
        self::assertNull($this->codeUsedAt($acmeFoods));
    }

    #[Test]
    public function anUnknownMalformedOrMissingCodeOrClientIsRefusedAndIssuesNothing(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $code = $this->issueCode($omar, $acmeFoods);
        $cases = [
            'an unknown code' => [['code' => str_repeat('A', 43)], 'invalid_grant', ExchangeOAuthAuthorizationCode::UNUSABLE_CODE],
            'a code of another shape' => [['code' => $code.'x'], 'invalid_grant', ExchangeOAuthAuthorizationCode::UNUSABLE_CODE],
            'no code' => [['code' => null], 'invalid_request', 'code is required, once.'],
            'two codes' => [['code' => [$code, $code]], 'invalid_request', 'code is required, once.'],
            'an unknown client_id' => [['client_id' => str_repeat('a', 32)], 'invalid_client', 'pinround does not know this client_id.'],
            'no verifier' => [['code_verifier' => null], 'invalid_request', 'code_verifier is required, once.'],
            'a verifier too short' => [['code_verifier' => str_repeat('a', 42)], 'invalid_request', 'code_verifier is 43 to 128 characters of A-Z, a-z, 0-9, ".", "_", "~" and "-".'],
        ];

        foreach ($cases as $case => [$change, $error, $description]) {
            $this->assertTokenRequestRefused(array_filter([...$this->codeTrade($code), ...$change], static fn (mixed $value): bool => null !== $value), $error, $description, $case);
        }

        self::assertSame(['access' => 0, 'refresh' => 0], $this->tokenRowsOf($acmeFoods));
        // None of them got as far as using the code up, so it still trades.
        $this->requestTokens($this->codeTrade($code));
    }

    #[Test]
    public function aMissingOrUnsupportedGrantTypeIsRefusedAndIssuesNothing(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $code = $this->issueCode($omar, $acmeFoods);
        $trade = $this->codeTrade($code);
        unset($trade['grant_type']);

        $this->assertTokenRequestRefused($trade, 'invalid_request', OAuthTokenController::GRANT_TYPE_REQUIRED, 'no grant_type');
        $this->assertTokenRequestRefused(['grant_type' => ['authorization_code', 'refresh_token']] + $trade, 'invalid_request', OAuthTokenController::GRANT_TYPE_REQUIRED, 'two grant types');
        $this->assertTokenRequestRefused(['grant_type' => 'password'] + $trade, 'unsupported_grant_type', OAuthTokenController::UNSUPPORTED_GRANT_TYPE, 'password');
        $this->assertTokenRequestRefused(['grant_type' => 'client_credentials'] + $trade, 'unsupported_grant_type', OAuthTokenController::UNSUPPORTED_GRANT_TYPE, 'client_credentials');

        self::assertSame(['access' => 0, 'refresh' => 0], $this->tokenRowsOf($acmeFoods));
        self::assertNull($this->codeUsedAt($acmeFoods));
    }

    /**
     * A client registered without the refresh_token grant gets no refresh token, though one is kept for its family.
     */
    #[Test]
    public function aClientWithoutTheRefreshGrantGetsAnAccessTokenOnly(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $accessOnlyClientId = $this->registerClient($this->client, 'Access only', [self::CALLBACK]);
        $code = $this->issueCode($omar, $acmeFoods, $accessOnlyClientId);

        $answer = $this->requestTokens(['client_id' => $accessOnlyClientId] + $this->codeTrade($code));

        self::assertSame(['access_token', 'token_type', 'expires_in', 'scope'], array_keys($answer));
        self::assertSame(200, $this->probe(self::stringOf($answer, 'access_token')));
    }

    /**
     * Each refresh gives a new pair of the same grant and stops the access token before it.
     */
    #[Test]
    public function aRefreshTokenIsTradedForANewPairAndStopsTheAccessTokenBeforeIt(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $first = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        $this->clock->sleep(600);

        $second = $this->requestTokens(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId]);

        self::assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($second));
        self::assertSame('read tickets:move', $second['scope']);
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertSame(401, $this->probe(self::stringOf($first, 'access_token')));
        self::assertSame($omar->id()->toString(), $this->probeWith(self::stringOf($second, 'access_token'))['userId'] ?? null);
        self::assertSame(['access' => 2, 'refresh' => 2], $this->tokenRowsOf($acmeFoods));

        $third = $this->requestTokens(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($second, 'refresh_token'), 'client_id' => $this->clientId, 'scope' => 'read']);

        self::assertSame('read tickets:move', $third['scope']);
        self::assertSame(401, $this->probe(self::stringOf($second, 'access_token')));
        self::assertSame(200, $this->probe(self::stringOf($third, 'access_token')));
    }

    /**
     * RFC 9700 section 4.14.2: either the client or a thief holds a copy of a traded token, so the whole family stops.
     */
    #[Test]
    public function aTradedRefreshTokenThatComesBackIsRefusedAndRevokesItsWholeFamily(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $first = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        $second = $this->requestTokens(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId]);
        $otherFamily = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));

        $this->assertTokenRequestRefused(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId], 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'reused');

        self::assertSame(401, $this->probe(self::stringOf($second, 'access_token')));
        $this->assertTokenRequestRefused(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($second, 'refresh_token'), 'client_id' => $this->clientId], 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'the new one after the reuse');
        self::assertSame(['access' => 3, 'refresh' => 3], $this->tokenRowsOf($acmeFoods));
        self::assertSame(200, $this->probe(self::stringOf($otherFamily, 'access_token')));
        $this->requestTokens(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($otherFamily, 'refresh_token'), 'client_id' => $this->clientId]);
    }

    /**
     * The audit log records ApiTokenIssued and ApiTokenRevoked with the person as actor. A connect and a sign-out are
     * the person's; the hourly rotation and a reuse detection are nobody's, so they publish neither.
     */
    #[Test]
    public function onlyTheConnectAndTheSignOutPublishTokenEventsNeverARotationOrAReuse(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $first = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        self::assertSame(['ApiTokenIssued'], $this->tokenEventsOf($acmeFoods), 'the connect');

        $second = $this->requestTokens(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId]);
        self::assertSame(401, $this->probe(self::stringOf($first, 'access_token')));
        self::assertSame(['ApiTokenIssued'], $this->tokenEventsOf($acmeFoods), 'the rotation');

        $this->assertTokenRequestRefused(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId], 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'reused');
        self::assertSame(401, $this->probe(self::stringOf($second, 'access_token')));
        self::assertSame(['ApiTokenIssued'], $this->tokenEventsOf($acmeFoods), 'the reuse');

        $other = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        $this->revoke(['token' => self::stringOf($other, 'refresh_token'), 'client_id' => $this->clientId]);
        self::assertSame(401, $this->probe(self::stringOf($other, 'access_token')));
        self::assertSame(['ApiTokenIssued', 'ApiTokenIssued', 'ApiTokenRevoked'], $this->tokenEventsOf($acmeFoods), 'the sign-out');
    }

    #[Test]
    public function aRefreshTokenOfAnotherClientUnknownOrAskingForMoreScopesIsRefusedAndIssuesNothing(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $otherClientId = $this->registerClient($this->client, 'Other app', [self::CALLBACK], ['authorization_code', 'refresh_token']);
        $first = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        $refresh = ['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId];

        $this->assertTokenRequestRefused(['client_id' => $otherClientId] + $refresh, 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'another client');
        $this->assertTokenRequestRefused(['refresh_token' => 'prr_'.str_repeat('A', 43)] + $refresh, 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'unknown');
        $this->assertTokenRequestRefused(['refresh_token' => self::stringOf($first, 'access_token')] + $refresh, 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'an access token');
        $this->assertTokenRequestRefused(['scope' => 'read rounds:write'] + $refresh, 'invalid_scope', RefreshOAuthTokens::SCOPE_NOT_GRANTED, 'more scopes');

        self::assertSame(['access' => 1, 'refresh' => 1], $this->tokenRowsOf($acmeFoods));
        self::assertSame(200, $this->probe(self::stringOf($first, 'access_token')));
        // None of them used the token up.
        $this->requestTokens($refresh);
    }

    #[Test]
    public function afterThePersonLeavesTheOrganizationNeitherTheAccessNorTheRefreshTokenWorks(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $first = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        self::assertSame(200, $this->probe(self::stringOf($first, 'access_token')));

        $this->removeMembership($omar, $acmeFoods);

        self::assertSame(401, $this->probe(self::stringOf($first, 'access_token')));
        $this->assertTokenRequestRefused(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($first, 'refresh_token'), 'client_id' => $this->clientId], 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'a leaver');
        self::assertSame(['access' => 1, 'refresh' => 1], $this->tokenRowsOf($acmeFoods));
    }

    /**
     * REACH: only the token's own client revokes it; another client is told the same 200 and the token keeps working.
     */
    #[Test]
    public function revokingAnAccessTokenStopsItAloneAndOnlyForItsOwnClient(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $otherClientId = $this->registerClient($this->client, 'Other app', [self::CALLBACK], ['authorization_code', 'refresh_token']);
        $tokens = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        $accessToken = self::stringOf($tokens, 'access_token');

        $this->revoke(['token' => $accessToken, 'client_id' => $otherClientId]);
        self::assertSame(200, $this->probe($accessToken));

        $this->revoke(['token' => $accessToken, 'client_id' => $this->clientId]);

        self::assertSame(401, $this->probe($accessToken));
        $renewed = $this->requestTokens(['grant_type' => 'refresh_token', 'refresh_token' => self::stringOf($tokens, 'refresh_token'), 'client_id' => $this->clientId]);
        self::assertSame(200, $this->probe(self::stringOf($renewed, 'access_token')));
    }

    #[Test]
    public function revokingARefreshTokenStopsItsFamilyAndOnlyForItsOwnClient(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $otherClientId = $this->registerClient($this->client, 'Other app', [self::CALLBACK], ['authorization_code', 'refresh_token']);
        $tokens = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        $refreshToken = self::stringOf($tokens, 'refresh_token');

        $this->revoke(['token' => $refreshToken, 'client_id' => $otherClientId, 'token_type_hint' => 'refresh_token']);
        self::assertSame(200, $this->probe(self::stringOf($tokens, 'access_token')));

        $this->revoke(['token' => $refreshToken, 'client_id' => $this->clientId]);

        self::assertSame(401, $this->probe(self::stringOf($tokens, 'access_token')));
        $this->assertTokenRequestRefused(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $this->clientId], 'invalid_grant', RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN, 'revoked');
    }

    /**
     * RFC 7009 section 2.2: an unknown or malformed token gets 200, as a revoked one does; a request without the
     * fields is refused.
     */
    #[Test]
    public function revokingAnUnknownTokenAnswers200AndARequestWithoutTokenOrClientIsRefused(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $tokens = $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));

        foreach (['pr_'.str_repeat('A', 43), 'prr_'.str_repeat('A', 43), 'not a token'] as $unknownToken) {
            $this->revoke(['token' => $unknownToken, 'client_id' => $this->clientId]);
        }
        self::assertSame(200, $this->probe(self::stringOf($tokens, 'access_token')));

        foreach ([
            'no token' => [['client_id' => $this->clientId], 'invalid_request', 'token is required, once.'],
            'no client' => [['token' => self::stringOf($tokens, 'access_token')], 'invalid_request', 'client_id is required, once.'],
            'an unknown client' => [['token' => self::stringOf($tokens, 'access_token'), 'client_id' => str_repeat('a', 32)], 'invalid_client', 'pinround does not know this client_id.'],
        ] as $case => [$fields, $error, $description]) {
            $this->client->request('POST', '/oauth/revoke', $fields);

            self::assertResponseStatusCodeSame(400, $case);
            $this->assertNotStored();
            self::assertSame(['error' => $error, 'error_description' => $description], $this->answer(), $case);
        }
        self::assertSame(200, $this->probe(self::stringOf($tokens, 'access_token')));
    }

    #[Test]
    public function theCleanupRemovesTheRefreshTokensToo(): void
    {
        [$omar, $acmeFoods] = $this->omarAndHisOrganizations();
        $this->requestTokens($this->codeTrade($this->issueCode($omar, $acmeFoods)));
        self::assertSame(1, $this->tokenRowsOf($acmeFoods)['refresh']);

        $this->removeOAuthClientsAndCodes();

        self::assertSame(0, $this->tokenRowsOf($acmeFoods)['refresh']);
        self::assertSame(0, $this->readAs($acmeFoods, static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT count(*) FROM idn_oauth_authorization_code')));
    }

    /**
     * @return array{User, OrganizationId, OrganizationId} Omar, Acme Foods and Beta Bakery
     */
    private function omarAndHisOrganizations(): array
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);

        return [$omar, $acmeFoods, $betaBakery];
    }

    /**
     * A code issued now for read and tickets:move, as the consent page would issue it.
     *
     * @return string the plain code
     */
    private function issueCode(User $user, OrganizationId $organizationId, ?string $clientId = null): string
    {
        return $this->issueAuthorizationCode($user, $organizationId, $clientId ?? $this->clientId, self::CALLBACK, self::CHALLENGE, [ApiTokenScope::Read, ApiTokenScope::TicketsMove], $this->clock->now());
    }

    /**
     * @return array<string, string> the right authorization_code request for $code
     */
    private function codeTrade(string $code): array
    {
        return ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $this->clientId, 'code_verifier' => self::VERIFIER];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<array-key, mixed> the 200 answer
     */
    private function requestTokens(array $fields): array
    {
        $this->client->request('POST', '/oauth/token', $fields);

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->assertNotStored();

        return $this->answer();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function assertTokenRequestRefused(array $fields, string $error, string $description, string $case): void
    {
        $this->client->request('POST', '/oauth/token', $fields);

        self::assertResponseStatusCodeSame(400, $case);
        $this->assertNotStored();
        self::assertSame(['error' => $error, 'error_description' => $description], $this->answer(), $case);
    }

    /**
     * @param array<string, string> $fields
     */
    private function revoke(array $fields): void
    {
        $this->client->request('POST', '/oauth/revoke', $fields);

        self::assertResponseStatusCodeSame(200);
        $this->assertNotStored();
        self::assertSame('{}', $this->client->getResponse()->getContent());
    }

    /**
     * @return int the probe's status
     */
    private function probe(string $accessToken): int
    {
        $this->client->request('GET', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<array-key, mixed> who the probe saw
     */
    private function probeWith(string $accessToken): array
    {
        self::assertSame(200, $this->probe($accessToken));

        return $this->answer();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }

    /**
     * @param array<array-key, mixed> $answer
     */
    private static function stringOf(array $answer, string $key): string
    {
        self::assertIsString($answer[$key] ?? null, $key);

        return $answer[$key];
    }

    /**
     * @return array{access: int, refresh: int} the access and refresh token rows of the organization, revoked ones too
     */
    private function tokenRowsOf(OrganizationId $organizationId): array
    {
        $counts = $this->readAs($organizationId, static fn (Connection $migrator): array|false => $migrator->fetchAssociative(
            'SELECT (SELECT count(*) FROM idn_api_token) AS access, (SELECT count(*) FROM idn_oauth_refresh_token) AS refresh',
        ));
        self::assertIsArray($counts);
        self::assertIsInt($counts['access']);
        self::assertIsInt($counts['refresh']);

        return ['access' => $counts['access'], 'refresh' => $counts['refresh']];
    }

    /**
     * @return list<string> the short names of the ApiTokenIssued and ApiTokenRevoked events waiting in the outbox for
     *                      the organization, in the order they were published
     */
    private function tokenEventsOf(OrganizationId $organizationId): array
    {
        $bodies = $this->readAs($organizationId, static fn (Connection $migrator): array => $migrator->fetchFirstColumn(
            "SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 ORDER BY id",
            [$organizationId->toString()],
        ));
        $events = [];
        foreach ($bodies as $body) {
            self::assertIsString($body);
            foreach (['ApiTokenIssued', 'ApiTokenRevoked'] as $event) {
                // The class name right after its namespace backslash, which the body may hold escaped or not.
                if (str_contains($body, '\\'.$event)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @return string|null when the organization's one code was used, in UTC
     */
    private function codeUsedAt(OrganizationId $organizationId): ?string
    {
        $usedAt = $this->readAs($organizationId, static fn (Connection $migrator): array => $migrator->fetchFirstColumn(
            "SELECT to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_oauth_authorization_code",
        ));
        self::assertCount(1, $usedAt);
        self::assertTrue(null === $usedAt[0] || \is_string($usedAt[0]));

        return $usedAt[0];
    }

    private function assertNotStored(): void
    {
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }
}
