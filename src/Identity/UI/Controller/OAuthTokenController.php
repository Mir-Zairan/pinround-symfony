<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\ExchangeOAuthAuthorizationCode;
use App\Identity\Application\IssuedOAuthTokens;
use App\Identity\Application\OAuthDiscovery;
use App\Identity\Application\OAuthTokenParameters;
use App\Identity\Application\OAuthTokenRefused;
use App\Identity\Application\OAuthTokenRequestLimiter;
use App\Identity\Application\RefreshOAuthTokens;
use App\Identity\Application\RevokeOAuthToken;
use App\Identity\Application\TooManyOAuthTokenRequests;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The token endpoint, POST /oauth/token (RFC 6749 section 3.2), and the revocation endpoint, POST /oauth/revoke
 * (RFC 7009), of pinround's OAuth server (ADR-0050). Public by design (security.yaml): a public client has no secret,
 * and the code with its PKCE verifier, or the refresh token, is the proof; the request acts for nobody before that, so
 * it needs no form token. Both take form fields and are counted per IP (oauth_token_per_ip) before anything is read.
 * Errors are RFC 6749 section 5.2 JSON with status 400, a refused rate 429 with a Retry-After in seconds; every answer
 * is "Cache-Control: no-store" (RFC 6749 section 5.1). The routes run without the request transaction, as the code's
 * use and the tokens' writes each open their own under the grant's organization.
 */
final class OAuthTokenController extends AbstractController
{
    public const string TOKEN_ROUTE = 'identity_oauth_token';
    public const string REVOKE_ROUTE = 'identity_oauth_revoke';
    public const string TOKEN_TYPE = 'Bearer';
    public const string GRANT_TYPE_REQUIRED = 'grant_type is required, once.';
    public const string UNSUPPORTED_GRANT_TYPE = 'grant_type is authorization_code or refresh_token.';

    private const int MAX_GRANT_TYPE_LENGTH = 64;

    public function __construct(
        private readonly ExchangeOAuthAuthorizationCode $exchangeOAuthAuthorizationCode,
        private readonly RefreshOAuthTokens $refreshOAuthTokens,
        private readonly RevokeOAuthToken $revokeOAuthToken,
        private readonly OAuthTokenRequestLimiter $limiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(OAuthDiscovery::TOKEN_PATH, name: self::TOKEN_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function token(Request $request): JsonResponse
    {
        $parameters = $request->request->all();
        try {
            $this->limiter->consume((string) $request->getClientIp());
            $grantType = OAuthTokenParameters::optional($parameters, 'grant_type', self::MAX_GRANT_TYPE_LENGTH)
                ?? throw OAuthTokenRefused::invalidRequest(self::GRANT_TYPE_REQUIRED);
            $issued = match ($grantType) {
                'authorization_code' => ($this->exchangeOAuthAuthorizationCode)($parameters, $this->clock->now()),
                'refresh_token' => ($this->refreshOAuthTokens)($parameters, $this->clock->now()),
                default => throw new OAuthTokenRefused(OAuthTokenRefused::UNSUPPORTED_GRANT_TYPE, self::UNSUPPORTED_GRANT_TYPE),
            };
        } catch (TooManyOAuthTokenRequests $refusal) {
            return self::tooManyRequests($refusal);
        } catch (OAuthTokenRefused $refusal) {
            return self::refused($refusal);
        }

        return self::notStored(self::tokenAnswerOf($issued), Response::HTTP_OK);
    }

    /**
     * 200 with an empty body whether or not a token was revoked (RFC 7009 section 2.2).
     */
    #[Route(OAuthDiscovery::REVOCATION_PATH, name: self::REVOKE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function revoke(Request $request): JsonResponse
    {
        try {
            $this->limiter->consume((string) $request->getClientIp());
            ($this->revokeOAuthToken)($request->request->all(), $this->clock->now());
        } catch (TooManyOAuthTokenRequests $refusal) {
            return self::tooManyRequests($refusal);
        } catch (OAuthTokenRefused $refusal) {
            return self::refused($refusal);
        }

        return self::notStored(new \stdClass(), Response::HTTP_OK);
    }

    /**
     * @return array<string, int|string>
     */
    private static function tokenAnswerOf(IssuedOAuthTokens $issued): array
    {
        $answer = [
            'access_token' => $issued->revealAccessToken(),
            'token_type' => self::TOKEN_TYPE,
            'expires_in' => $issued->expiresInSeconds,
        ];
        $refreshToken = $issued->revealRefreshToken();
        if (null !== $refreshToken) {
            $answer['refresh_token'] = $refreshToken;
        }

        return $answer + ['scope' => implode(' ', $issued->scopeNames)];
    }

    private static function refused(OAuthTokenRefused $refusal): JsonResponse
    {
        return self::notStored(['error' => $refusal->error, 'error_description' => $refusal->getMessage()], Response::HTTP_BAD_REQUEST);
    }

    private static function tooManyRequests(TooManyOAuthTokenRequests $refusal): JsonResponse
    {
        $answer = self::notStored(
            ['error' => OAuthClientRegistrationController::TOO_MANY_REQUESTS, 'error_description' => \sprintf('Too many token requests from this address. Try again in %d minutes.', $refusal->minutesToWait)],
            Response::HTTP_TOO_MANY_REQUESTS,
        );
        $answer->headers->set('Retry-After', (string) ($refusal->minutesToWait * 60));

        return $answer;
    }

    /**
     * @param array<string, int|string>|\stdClass $answer
     */
    private static function notStored(array|\stdClass $answer, int $status): JsonResponse
    {
        $response = new JsonResponse($answer, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
