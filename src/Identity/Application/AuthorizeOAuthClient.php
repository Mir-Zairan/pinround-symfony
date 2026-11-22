<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\MemberOrganization;
use App\Identity\Api\MemberOrganizations;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\InvalidOAuthRedirectUri;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthRedirectUri;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The authorize step of the OAuth code flow with PKCE (ADR-0050, RFC 6749 section 4.1, RFC 7636): checks an authorize
 * request, and on the person's Allow issues a code for the one organization they picked, bound to the client, the
 * redirect URI, the S256 challenge and the scopes. It does not check who asks: the caller has the voter's answer and the
 * recent password and two-step proof. The same check() runs on the consent page and on its Allow and Deny, so a
 * posted form gets no further than the request it came from could.
 */
final readonly class AuthorizeOAuthClient
{
    /** The longest client_id looked up; a registered one has 32 characters. */
    public const int MAX_CLIENT_ID_LENGTH = 64;
    /** The longest state sent back, so the authorize URL still fits the way back from "Confirm it's you". */
    public const int MAX_STATE_LENGTH = 500;
    /** Every scope name, space-separated, fits with room to spare. */
    public const int MAX_SCOPE_LENGTH = 200;

    public const string UNKNOWN_CLIENT = 'pinround does not know this app. Start connecting again from the app.';
    public const string UNREGISTERED_REDIRECT_URI = 'This app asked to send you to an address it did not register, so pinround stopped here. Start connecting again from the app.';

    public const string ACCESS_DENIED = 'access_denied';
    public const string TICKED_SCOPE_NOT_OFFERED = 'An extra scope must be one the consent page offered.';

    // RFC 6749 appendix A.5: state is one or more printable ASCII characters.
    private const string STATE_SHAPE = '/\A[\x20-\x7E]{1,%d}\z/';

    public function __construct(
        private OAuthClients $clients,
        private OAuthAuthorizationCodes $codes,
        private ApiTokenHashing $hashing,
        private MemberOrganizations $memberOrganizations,
    ) {
    }

    /**
     * Checks the client_id and redirect_uri first, as RFC 6749 section 4.1.2.1 asks, then state, response_type,
     * code_challenge_method, code_challenge and scope. An absent or empty scope asks for the scopes a token made for
     * Claude Code starts with (ApiTokenScope::claudeCodeDefaults).
     *
     * @param array<array-key, mixed> $parameters the query of the authorize request, or the consent form's fields
     *
     * @throws OAuthAuthorizationRefused at the first rule the request breaks
     */
    public function check(array $parameters): OAuthAuthorizationRequest
    {
        return $this->checked($parameters)[0];
    }

    /**
     * The organizations the person may pick on the consent page. Clears the entity manager first; call it with no
     * transaction open.
     *
     * @return list<MemberOrganization> in id order; empty when the person is in none
     *
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function organizationsOf(UserId $userId): array
    {
        return $this->memberOrganizations->of($userId);
    }

    /**
     * The person allowed the request: checks it again, checks that they are a member of the posted organization now,
     * then saves a code that lasts OAuthAuthorizationCode::MAX_LIFETIME_SECONDS, in its own transaction. Call it with
     * no transaction open; the entity manager is cleared.
     *
     * The scopes the code carries are the ones the app asked for plus any the person ticked on the page from
     * optionalScopesOf() (ADR-0039: reports:write and notebooks:write only ever by the person's own tick).
     *
     * @param array<array-key, mixed> $parameters     the consent form's fields; `extra_scopes` holds the ticked ones
     * @param string                  $organizationId as posted, not yet checked
     *
     * @return string the redirect URI with the plain code and the state; the code is in it only, so send it at once
     *
     * @throws OAuthAuthorizationRefused        when the request breaks a rule of check(), or a ticked scope is not one
     *                                          the page offered; nothing is saved
     * @throws OAuthConsentOrganizationNotFound when the organization id is malformed or not one of the person's
     *                                          organizations now; nothing is saved
     * @throws TenantSwitchRefused              when a transaction is open; nothing is saved
     */
    public function allow(array $parameters, UserId $userId, string $organizationId, \DateTimeImmutable $issuedAt): string
    {
        [$request, $client] = $this->checked($parameters);
        $scopes = [...ApiTokenScope::fromValues($request->scopeNames), ...$this->tickedOptionalScopesOf($request, $parameters['extra_scopes'] ?? [])];
        $scopes = array_values(array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $scope): bool => \in_array($scope, $scopes, true)));
        $pickedOrganizationId = $this->memberOrganizationOf($userId, $organizationId);
        $issued = OAuthAuthorizationCode::issue(
            $this->codes->nextIdentity(),
            $pickedOrganizationId,
            $userId,
            $client,
            $request->redirectUri,
            $request->codeChallenge,
            OAuthClient::CODE_CHALLENGE_METHOD,
            $scopes,
            $this->hashing,
            $issuedAt,
        );
        $this->codes->add($issued->code());

        return self::answerUrl($request->redirectUri, ['code' => $issued->reveal(), 'state' => $request->state]);
    }

    /**
     * The scopes the consent page offers as unticked boxes: every scope the app did not ask for. A token page offers the
     * same ones; the person may tick any of them, and only those (allow() refuses anything else).
     *
     * @return list<string> scope names in ApiTokenScope's order
     */
    public static function optionalScopesOf(OAuthAuthorizationRequest $request): array
    {
        return array_values(array_map(
            static fn (ApiTokenScope $scope): string => $scope->value,
            array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $scope): bool => !\in_array($scope->value, $request->scopeNames, true)),
        ));
    }

    /**
     * @return list<ApiTokenScope>
     *
     * @throws OAuthAuthorizationRefused when the field is not a list of offered scope names
     */
    private function tickedOptionalScopesOf(OAuthAuthorizationRequest $request, mixed $ticked): array
    {
        $offered = self::optionalScopesOf($request);
        if (!\is_array($ticked) || \count($ticked) > \count($offered)) {
            throw self::notOffered($request);
        }
        $scopes = [];
        foreach ($ticked as $scopeName) {
            if (!\is_string($scopeName) || !\in_array($scopeName, $offered, true)) {
                throw self::notOffered($request);
            }
            $scopes[] = ApiTokenScope::from($scopeName);
        }

        return $scopes;
    }

    private static function notOffered(OAuthAuthorizationRequest $request): OAuthAuthorizationRefused
    {
        return OAuthAuthorizationRefused::toClient(new OAuthRedirectUri($request->redirectUri), OAuthAuthorizationRefused::INVALID_SCOPE, self::TICKED_SCOPE_NOT_OFFERED, $request->state);
    }

    /**
     * The person denied the request.
     *
     * @param array<array-key, mixed> $parameters the consent form's fields
     *
     * @return string the redirect URI with error access_denied and the state
     *
     * @throws OAuthAuthorizationRefused when the request breaks a rule of check()
     */
    public function deny(array $parameters): string
    {
        $request = $this->check($parameters);

        return self::answerUrl($request->redirectUri, ['error' => self::ACCESS_DENIED, 'state' => $request->state]);
    }

    /**
     * $redirectUri with the fields added to its query, which it keeps (RFC 6749, section 4.1.2). A null field is left out.
     *
     * @param array<string, string|null> $fields
     */
    public static function answerUrl(string $redirectUri, array $fields): string
    {
        $query = http_build_query(array_filter($fields, static fn (?string $value): bool => null !== $value), '', '&', \PHP_QUERY_RFC3986);
        if (!str_contains($redirectUri, '?')) {
            return $redirectUri.'?'.$query;
        }

        return $redirectUri.(str_ends_with($redirectUri, '?') || str_ends_with($redirectUri, '&') ? '' : '&').$query;
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array{OAuthAuthorizationRequest, OAuthClient}
     */
    private function checked(array $parameters): array
    {
        $client = $this->clientOf(self::textOf($parameters, 'client_id'));
        $redirectUri = self::registeredRedirectUriOf($client, self::textOf($parameters, 'redirect_uri'));

        $state = self::textOf($parameters, 'state');
        if ('' === $state) {
            $state = null;
        }
        if (null !== $state && (false === $state || 1 !== preg_match(\sprintf(self::STATE_SHAPE, self::MAX_STATE_LENGTH), $state))) {
            throw OAuthAuthorizationRefused::toClient($redirectUri, OAuthAuthorizationRefused::INVALID_REQUEST, \sprintf('state is 1 to %d printable ASCII characters.', self::MAX_STATE_LENGTH), null);
        }
        $refuse = static fn (string $error, string $description): OAuthAuthorizationRefused => OAuthAuthorizationRefused::toClient($redirectUri, $error, $description, $state);

        $responseType = self::textOf($parameters, 'response_type');
        if (null === $responseType || false === $responseType || '' === $responseType) {
            throw $refuse(OAuthAuthorizationRefused::INVALID_REQUEST, 'response_type is code.');
        }
        if (OAuthClient::RESPONSE_TYPE !== $responseType) {
            throw $refuse(OAuthAuthorizationRefused::UNSUPPORTED_RESPONSE_TYPE, 'Only response_type code is supported.');
        }
        if (OAuthClient::CODE_CHALLENGE_METHOD !== self::textOf($parameters, 'code_challenge_method')) {
            throw $refuse(OAuthAuthorizationRefused::INVALID_REQUEST, 'PKCE is required: code_challenge_method is S256.');
        }
        $codeChallenge = self::textOf($parameters, 'code_challenge');
        if (!\is_string($codeChallenge) || 1 !== preg_match(OAuthAuthorizationCode::CODE_CHALLENGE_SHAPE, $codeChallenge)) {
            throw $refuse(OAuthAuthorizationRefused::INVALID_REQUEST, 'PKCE is required: code_challenge is 43 to 128 characters of A-Z, a-z, 0-9, ., _, ~ and -.');
        }
        $scopes = self::scopesOf(self::textOf($parameters, 'scope'));
        if (null === $scopes) {
            throw $refuse(OAuthAuthorizationRefused::INVALID_SCOPE, 'scope is one or more of: '.implode(' ', array_column(ApiTokenScope::cases(), 'value')).'.');
        }

        return [
            new OAuthAuthorizationRequest(
                $client->clientId(),
                $client->clientName(),
                $redirectUri->toString(),
                $redirectUri->host(),
                $codeChallenge,
                $state,
                array_map(static fn (ApiTokenScope $scope): string => $scope->value, $scopes),
            ),
            $client,
        ];
    }

    /**
     * @throws OAuthAuthorizationRefused without a redirect
     */
    private function clientOf(string|false|null $clientId): OAuthClient
    {
        if (!\is_string($clientId) || '' === $clientId || \strlen($clientId) > self::MAX_CLIENT_ID_LENGTH) {
            throw OAuthAuthorizationRefused::withoutRedirect(self::UNKNOWN_CLIENT);
        }

        return $this->clients->byClientId($clientId) ?? throw OAuthAuthorizationRefused::withoutRedirect(self::UNKNOWN_CLIENT);
    }

    /**
     * @throws OAuthAuthorizationRefused without a redirect
     */
    private static function registeredRedirectUriOf(OAuthClient $client, string|false|null $redirectUri): OAuthRedirectUri
    {
        if (!\is_string($redirectUri)) {
            throw OAuthAuthorizationRefused::withoutRedirect(self::UNREGISTERED_REDIRECT_URI);
        }
        try {
            $requested = new OAuthRedirectUri($redirectUri);
        } catch (InvalidOAuthRedirectUri) {
            throw OAuthAuthorizationRefused::withoutRedirect(self::UNREGISTERED_REDIRECT_URI);
        }
        foreach ($client->redirectUris() as $registeredUri) {
            if ($requested->matchesRegistered($registeredUri)) {
                return $requested;
            }
        }

        throw OAuthAuthorizationRefused::withoutRedirect(self::UNREGISTERED_REDIRECT_URI);
    }

    /**
     * @return list<ApiTokenScope>|null in the order of the cases, repeats once; null when the parameter is malformed,
     *                                  too long or names an unknown scope
     */
    private static function scopesOf(string|false|null $scope): ?array
    {
        if (false === $scope || (null !== $scope && \strlen($scope) > self::MAX_SCOPE_LENGTH)) {
            return null;
        }
        if (null === $scope || '' === trim($scope, ' ')) {
            return ApiTokenScope::claudeCodeDefaults();
        }
        $askedScopes = [];
        foreach (explode(' ', trim($scope, ' ')) as $scopeName) {
            if ('' === $scopeName) {
                continue;
            }
            $askedScope = ApiTokenScope::tryFrom($scopeName);
            if (null === $askedScope) {
                return null;
            }
            $askedScopes[] = $askedScope;
        }

        return array_values(array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $scope): bool => \in_array($scope, $askedScopes, true)));
    }

    /**
     * @throws OAuthConsentOrganizationNotFound
     */
    private function memberOrganizationOf(UserId $userId, string $organizationId): OrganizationId
    {
        try {
            $postedOrganizationId = new OrganizationId($organizationId);
        } catch (\InvalidArgumentException) {
            throw new OAuthConsentOrganizationNotFound();
        }
        if (!$this->memberOrganizations->isMember($userId, $postedOrganizationId)) {
            throw new OAuthConsentOrganizationNotFound();
        }

        return $postedOrganizationId;
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return string|false|null null when the parameter is absent; false when it is sent but is not one string, such as
     *                           name[]=x
     */
    private static function textOf(array $parameters, string $name): string|false|null
    {
        $value = $parameters[$name] ?? null;
        if (null === $value || \is_string($value)) {
            return $value;
        }

        return false;
    }
}
