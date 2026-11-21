<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\MemberOrganization;
use App\Identity\Application\AuthorizeOAuthClient;
use App\Identity\Application\OAuthAuthorizationRefused;
use App\Identity\Application\OAuthConsentOrganizationNotFound;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthorizeOAuthClientTest extends TestCase
{
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000f21';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f11';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000f12';
    private const string CEDAR_MARKET = '0197a3c0-0000-7000-8000-000000000f13';
    private const string ISSUED_AT = '2027-03-15 10:00:00 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback?from=pinround';
    private const string LOOPBACK_CALLBACK = 'http://127.0.0.1/callback';
    /** The S256 challenge of RFC 7636 Appendix B. */
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const string STATE = 'af0ifjsldkj+/= &?#';

    private InMemoryOAuthClients $clients;
    private InMemoryOAuthAuthorizationCodes $codes;
    private AuthorizeOAuthClient $authorize;
    private OAuthClient $client;

    protected function setUp(): void
    {
        $this->clients = new InMemoryOAuthClients();
        $this->codes = new InMemoryOAuthAuthorizationCodes();
        $this->client = OAuthClient::register('Claude', [self::CALLBACK, self::LOOPBACK_CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::ISSUED_AT));
        $this->clients->add($this->client);
        $this->authorize = new AuthorizeOAuthClient(
            $this->clients,
            $this->codes,
            new HmacApiTokenHashing(self::PEPPER),
            new FixedMemberOrganizations([self::OMAR => [
                new MemberOrganization(new OrganizationId(self::BETA_BAKERY), 'Beta Bakery'),
                new MemberOrganization(new OrganizationId(self::ACME_FOODS), 'Acme Foods'),
            ]]),
        );
    }

    #[Test]
    public function aRequestWithEveryRuleMetIsCheckedAsGiven(): void
    {
        $request = $this->authorize->check($this->parameters(['scope' => 'notebooks:write read read']));

        self::assertSame($this->client->clientId(), $request->clientId);
        self::assertSame('Claude', $request->clientName);
        self::assertSame(self::CALLBACK, $request->redirectUri);
        self::assertSame('claude.example', $request->redirectHost);
        self::assertSame(self::CHALLENGE, $request->codeChallenge);
        self::assertSame(self::STATE, $request->state);
        self::assertSame(['read', 'notebooks:write'], $request->scopeNames);
        self::assertSame('read notebooks:write', $request->scope());
    }

    #[Test]
    public function anAbsentOrEmptyScopeAsksForTheClaudeCodeDefaultsAndAnEmptyStateIsNone(): void
    {
        $defaults = array_map(static fn (ApiTokenScope $scope): string => $scope->value, ApiTokenScope::claudeCodeDefaults());

        self::assertSame($defaults, $this->authorize->check($this->parameters(['scope' => null]))->scopeNames);
        self::assertSame($defaults, $this->authorize->check($this->parameters(['scope' => '  ']))->scopeNames);
        self::assertNull($this->authorize->check($this->parameters(['state' => '']))->state);
        self::assertNull($this->authorize->check($this->parameters(['state' => null]))->state);
    }

    #[Test]
    public function aLoopbackRedirectUriMatchesWithAnyPortAndIsKeptWithItsPort(): void
    {
        $request = $this->authorize->check($this->parameters(['redirect_uri' => 'http://127.0.0.1:53127/callback']));

        self::assertSame('http://127.0.0.1:53127/callback', $request->redirectUri);
        self::assertSame('127.0.0.1', $request->redirectHost);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> the changed parameters and the message
     */
    public static function requestsAnsweredWithoutARedirect(): iterable
    {
        yield 'no client_id' => [['client_id' => null], AuthorizeOAuthClient::UNKNOWN_CLIENT];
        yield 'an empty client_id' => [['client_id' => ''], AuthorizeOAuthClient::UNKNOWN_CLIENT];
        yield 'an unknown client_id' => [['client_id' => str_repeat('a', 32)], AuthorizeOAuthClient::UNKNOWN_CLIENT];
        yield 'a client_id that is a list' => [['client_id' => ['x']], AuthorizeOAuthClient::UNKNOWN_CLIENT];
        yield 'a client_id over the cap' => [['client_id' => str_repeat('a', AuthorizeOAuthClient::MAX_CLIENT_ID_LENGTH + 1)], AuthorizeOAuthClient::UNKNOWN_CLIENT];
        yield 'no redirect_uri' => [['redirect_uri' => null], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'a redirect_uri that is a list' => [['redirect_uri' => [self::CALLBACK]], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'an unregistered redirect_uri' => [['redirect_uri' => 'https://evil.example/cb'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'one character changed' => [['redirect_uri' => 'https://claude.example/api/mcp/auth_callbacK?from=pinround'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'a trailing slash' => [['redirect_uri' => 'https://claude.example/api/mcp/auth_callback/?from=pinround'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'a host in another case' => [['redirect_uri' => 'https://Claude.example/api/mcp/auth_callback?from=pinround'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'the query left out' => [['redirect_uri' => 'https://claude.example/api/mcp/auth_callback'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'a redirect_uri that breaks the rule' => [['redirect_uri' => self::CALLBACK.'#x'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'a loopback redirect_uri on another host' => [['redirect_uri' => 'http://localhost:53127/callback'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'a loopback redirect_uri on another path' => [['redirect_uri' => 'http://127.0.0.1:53127/callback2'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
        yield 'an unknown client and a bad challenge' => [['client_id' => str_repeat('a', 32), 'code_challenge_method' => 'plain'], AuthorizeOAuthClient::UNKNOWN_CLIENT];
        yield 'an unregistered redirect_uri and a bad response_type' => [['redirect_uri' => 'https://evil.example/cb', 'response_type' => 'token'], AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[Test]
    #[DataProvider('requestsAnsweredWithoutARedirect')]
    public function anUnknownClientOrUnregisteredRedirectUriIsRefusedWithoutARedirectAndNoCode(array $change, string $message): void
    {
        $refusal = $this->refusalOfAllow($this->parameters($change));

        self::assertNull($refusal->errorRedirectUrl);
        self::assertSame($message, $refusal->getMessage());
        self::assertSame([], $this->codes->all());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, bool}> the changed parameters, the error,
     *                                                                             its description and whether the state
     *                                                                             goes back
     */
    public static function requestsAnsweredWithAnError(): iterable
    {
        $pkceMethod = 'PKCE is required: code_challenge_method is S256.';
        $pkceChallenge = 'PKCE is required: code_challenge is 43 to 128 characters of A-Z, a-z, 0-9, ., _, ~ and -.';
        $scope = 'scope is one or more of: read rounds:write comments:write tickets:move reports:write notebooks:write.';
        $state = 'state is 1 to 500 printable ASCII characters.';

        yield 'no response_type' => [['response_type' => null], 'invalid_request', 'response_type is code.', true];
        yield 'response_type token' => [['response_type' => 'token'], 'unsupported_response_type', 'Only response_type code is supported.', true];
        yield 'response_type in capitals' => [['response_type' => 'CODE'], 'unsupported_response_type', 'Only response_type code is supported.', true];
        yield 'no code_challenge_method' => [['code_challenge_method' => null], 'invalid_request', $pkceMethod, true];
        yield 'code_challenge_method plain' => [['code_challenge_method' => 'plain'], 'invalid_request', $pkceMethod, true];
        yield 'code_challenge_method in lowercase' => [['code_challenge_method' => 's256'], 'invalid_request', $pkceMethod, true];
        yield 'no code_challenge' => [['code_challenge' => null], 'invalid_request', $pkceChallenge, true];
        yield 'a code_challenge of 42 characters' => [['code_challenge' => str_repeat('a', 42)], 'invalid_request', $pkceChallenge, true];
        yield 'a code_challenge of 129 characters' => [['code_challenge' => str_repeat('a', 129)], 'invalid_request', $pkceChallenge, true];
        yield 'a code_challenge with a plus' => [['code_challenge' => str_repeat('a', 42).'+'], 'invalid_request', $pkceChallenge, true];
        yield 'a code_challenge with a line break at the end' => [['code_challenge' => self::CHALLENGE."\n"], 'invalid_request', $pkceChallenge, true];
        yield 'a code_challenge that is a list' => [['code_challenge' => [self::CHALLENGE]], 'invalid_request', $pkceChallenge, true];
        yield 'an unknown scope' => [['scope' => 'read admin'], 'invalid_scope', $scope, true];
        yield 'a scope in capitals' => [['scope' => 'READ'], 'invalid_scope', $scope, true];
        yield 'a scope over the cap' => [['scope' => str_repeat('read ', 40).'read'], 'invalid_scope', $scope, true];
        yield 'a scope that is a list' => [['scope' => ['read']], 'invalid_scope', $scope, true];
        yield 'a state over the cap' => [['state' => str_repeat('s', AuthorizeOAuthClient::MAX_STATE_LENGTH + 1)], 'invalid_request', $state, false];
        yield 'a state with a line break' => [['state' => "abc\r\nLocation: x"], 'invalid_request', $state, false];
        yield 'a state with a non-ASCII letter' => [['state' => 'café'], 'invalid_request', $state, false];
        yield 'a state that is a list' => [['state' => ['abc']], 'invalid_request', $state, false];
        yield 'a bad state and a bad response_type' => [['state' => "a\tb", 'response_type' => 'token'], 'invalid_request', $state, false];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[Test]
    #[DataProvider('requestsAnsweredWithAnError')]
    public function aRequestThatBreaksARuleGoesBackToTheClientWithTheErrorAndNoCode(array $change, string $error, string $description, bool $isStateSentBack): void
    {
        $refusal = $this->refusalOfAllow($this->parameters($change));

        self::assertSame($description, $refusal->getMessage());
        self::assertNotNull($refusal->errorRedirectUrl);
        self::assertStringStartsWith(self::CALLBACK.'&', $refusal->errorRedirectUrl);
        $expected = ['from' => 'pinround', 'error' => $error, 'error_description' => $description];
        if ($isStateSentBack) {
            $expected['state'] = self::STATE;
        }
        self::assertSame($expected, self::queryOf($refusal->errorRedirectUrl));
        self::assertSame([], $this->codes->all());
    }

    #[Test]
    public function aStateOfTheMaximumLengthIsSentBack(): void
    {
        $longestState = str_repeat('~', AuthorizeOAuthClient::MAX_STATE_LENGTH);

        self::assertSame($longestState, $this->authorize->check($this->parameters(['state' => $longestState]))->state);
    }

    #[Test]
    public function allowSavesOneCodeOfThePickedOrganizationAndSendsItWithTheStateToTheRedirectUri(): void
    {
        $answerUrl = $this->authorize->allow($this->parameters(['scope' => 'tickets:move read']), new UserId(self::OMAR), self::ACME_FOODS, new \DateTimeImmutable(self::ISSUED_AT));

        self::assertStringStartsWith(self::CALLBACK.'&', $answerUrl);
        $answer = self::queryOf($answerUrl);
        self::assertSame(['from', 'code', 'state'], array_keys($answer));
        self::assertSame('pinround', $answer['from']);
        self::assertSame(self::STATE, $answer['state']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $answer['code']);
        $saved = $this->codes->all();
        self::assertCount(1, $saved);
        $code = $saved[0];
        self::assertSame(hash_hmac('sha256', $answer['code'], self::PEPPER, true), $code->codeHash());
        self::assertSame(self::ACME_FOODS, $code->organizationId()->toString());
        self::assertSame(self::OMAR, $code->userId()->toString());
        self::assertSame($this->client->clientId(), $code->clientId());
        self::assertSame(self::CALLBACK, $code->redirectUri());
        self::assertSame(self::CHALLENGE, $code->codeChallenge());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::TicketsMove], $code->scopes());
        self::assertSame('2027-03-15 10:01:00', $code->expiresAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function allowOnALoopbackPortSendsTheCodeToThatPort(): void
    {
        $answerUrl = $this->authorize->allow($this->parameters(['redirect_uri' => 'http://127.0.0.1:53127/callback', 'state' => null]), new UserId(self::OMAR), self::BETA_BAKERY, new \DateTimeImmutable(self::ISSUED_AT));

        self::assertStringStartsWith('http://127.0.0.1:53127/callback?code=', $answerUrl);
        self::assertSame(['code'], array_keys(self::queryOf($answerUrl)));
        self::assertSame('http://127.0.0.1:53127/callback', $this->codes->all()[0]->redirectUri());
    }

    /**
     * Cedar Market exists but Omar is not in it; the others are an unknown id, a malformed one, one in capitals with a
     * line break, and an empty one.
     *
     * @return iterable<string, array{string}>
     */
    public static function organizationsOmarIsNotIn(): iterable
    {
        yield 'another organization' => [self::CEDAR_MARKET];
        yield 'an unknown id' => ['0197a3c0-0000-7000-8000-000000000fff'];
        yield 'a malformed id' => ['not-an-organization'];
        yield 'his own with a line break' => [self::ACME_FOODS."\n"];
        yield 'an empty id' => [''];
    }

    #[Test]
    #[DataProvider('organizationsOmarIsNotIn')]
    public function allowForAnOrganizationThePersonIsNotInIsNotFoundAndSavesNoCode(string $organizationId): void
    {
        try {
            $this->authorize->allow($this->parameters(), new UserId(self::OMAR), $organizationId, new \DateTimeImmutable(self::ISSUED_AT));
            self::fail('A code was issued for an organization Omar is not in.');
        } catch (OAuthConsentOrganizationNotFound $notFound) {
            self::assertSame('You are not a member of this organization.', $notFound->getMessage());
        }

        self::assertSame([], $this->codes->all());
    }

    #[Test]
    public function aPersonInNoOrganizationGetsNoCode(): void
    {
        $this->expectException(OAuthConsentOrganizationNotFound::class);

        try {
            $this->authorize->allow($this->parameters(), new UserId('0197a3c0-0000-7000-8000-000000000f22'), self::ACME_FOODS, new \DateTimeImmutable(self::ISSUED_AT));
        } finally {
            self::assertSame([], $this->codes->all());
        }
    }

    #[Test]
    public function denySendsAccessDeniedWithTheStateAndSavesNoCode(): void
    {
        $answerUrl = $this->authorize->deny($this->parameters());

        self::assertSame(['from' => 'pinround', 'error' => 'access_denied', 'state' => self::STATE], self::queryOf($answerUrl));
        self::assertSame([], $this->codes->all());
    }

    #[Test]
    public function denyOfARequestWithAnUnregisteredRedirectUriSendsNothingThere(): void
    {
        try {
            $this->authorize->deny($this->parameters(['redirect_uri' => 'https://evil.example/cb']));
            self::fail('A deny went to an unregistered redirect URI.');
        } catch (OAuthAuthorizationRefused $refusal) {
            self::assertNull($refusal->errorRedirectUrl);
            self::assertSame(AuthorizeOAuthClient::UNREGISTERED_REDIRECT_URI, $refusal->getMessage());
        }
    }

    #[Test]
    public function theAnswerKeepsTheRedirectUrisOwnQuery(): void
    {
        self::assertSame('https://x.example/cb?code=a%20b', AuthorizeOAuthClient::answerUrl('https://x.example/cb', ['code' => 'a b', 'state' => null]));
        self::assertSame('https://x.example/cb?x=1&code=c', AuthorizeOAuthClient::answerUrl('https://x.example/cb?x=1', ['code' => 'c']));
        self::assertSame('https://x.example/cb?code=c', AuthorizeOAuthClient::answerUrl('https://x.example/cb?', ['code' => 'c']));
        self::assertSame('https://x.example/cb?x=1&code=c', AuthorizeOAuthClient::answerUrl('https://x.example/cb?x=1&', ['code' => 'c']));
    }

    /**
     * @param array<string, mixed> $change a null value leaves the parameter out
     *
     * @return array<string, mixed>
     */
    private function parameters(array $change = []): array
    {
        $parameters = [
            'response_type' => 'code',
            'client_id' => $this->client->clientId(),
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => self::STATE,
            'scope' => 'read rounds:write',
            ...$change,
        ];

        return array_filter($parameters, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function refusalOfAllow(array $parameters): OAuthAuthorizationRefused
    {
        try {
            $this->authorize->allow($parameters, new UserId(self::OMAR), self::ACME_FOODS, new \DateTimeImmutable(self::ISSUED_AT));
        } catch (OAuthAuthorizationRefused $refusal) {
            return $refusal;
        }
        self::fail('The request was allowed.');
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
}
