<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates every request of the stateless api_token firewall (/mcp and /api/v1, ADR-0021) by its
 * "Authorization: Bearer pr_…" header alone; cookies are never read there. A live token (resolve_api_token: neither
 * revoked nor expired by the database clock) makes the request its user's, with the token's organization as tenant
 * and an ApiTokenAuthentication as security token. Anything else, a missing header included, gets the same 401 at
 * once, so a caller never learns which check failed. The plain token is hashed and dropped: it is in no exception,
 * passport or log record. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final class TokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string REFUSAL_MESSAGE = 'Send a valid personal access token as "Authorization: Bearer pr_…".';

    // base64url of ApiToken's 32 random bytes is 43 characters; the scheme name is case-insensitive (RFC 7235).
    private const string BEARER_TOKEN = '/\A(?i:bearer) ('.ApiToken::PLAIN_PREFIX.'[A-Za-z0-9_-]{43})\z/';

    private const string ACCESS_ATTRIBUTE = 'identity.api_token_access';

    public function __construct(
        private readonly ApiTokens $apiTokens,
        private readonly ApiTokenHashing $hashing,
        private readonly SecurityUserProvider $userProvider,
        private readonly TenantContext $tenantContext,
        private readonly Transactions $transactions,
    ) {
    }

    /**
     * Always, so a request without the header is refused here and not left to the access rules.
     */
    public function supports(Request $request): bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $owner = $this->apiTokens->ownerOfLiveToken($this->hashing->hash(self::presentedToken($request)))
            ?? throw new BadCredentialsException();

        $this->tenantContext->set($owner->organizationId);
        $token = $this->transactions->run(fn (): ?ApiToken => $this->apiTokens->byIdForOwner($owner->tokenId, $owner->userId))
            ?? throw new BadCredentialsException();

        $passport = new SelfValidatingPassport(new UserBadge(
            $owner->userId->toString(),
            fn (): SecurityUser => $this->userProvider->loadUserById($owner->userId),
        ));
        $passport->setAttribute(self::ACCESS_ATTRIBUTE, new ApiTokenAccess(
            $token->id(),
            $token->organizationId(),
            array_map(static fn (ApiTokenScope $scope): string => $scope->value, $token->scopes()),
        ));

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        $access = $passport->getAttribute(self::ACCESS_ATTRIBUTE);
        if (!$user instanceof SecurityUser || !$access instanceof ApiTokenAccess) {
            throw new \LogicException('A token login has a SecurityUser and the token\'s access.');
        }

        return new ApiTokenAuthentication($user, $access);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::refusal();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::refusal();
    }

    /**
     * @throws BadCredentialsException when the header is missing or is no Bearer token of the pr_ shape
     */
    private static function presentedToken(Request $request): string
    {
        if (1 !== preg_match(self::BEARER_TOKEN, (string) $request->headers->get('Authorization'), $match)) {
            throw new BadCredentialsException();
        }

        return $match[1];
    }

    private static function refusal(): JsonResponse
    {
        return new JsonResponse(['error' => self::REFUSAL_MESSAGE], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
