<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\OAuthClientRegistrationRefused;
use App\Identity\Application\OAuthDiscovery;
use App\Identity\Application\RegisteredOAuthClient;
use App\Identity\Application\RegisterOAuthClient;
use App\Identity\Application\TooManyOAuthClientRegistrations;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /oauth/register, dynamic client registration of public clients (RFC 7591, ADR-0050). Public by design
 * (security.yaml): a client registers before anyone logs in, and the request acts for nobody, so it needs no form
 * token. Answers 201 with the stored metadata, 400 with an RFC 7591 error and error_description, or 429 with a
 * Retry-After in seconds past oauth_client_registration_per_ip; never cached (RFC 7591, section 3.2.1). The client is
 * stored in the request transaction.
 */
final class OAuthClientRegistrationController extends AbstractController
{
    public const string ROUTE = 'identity_oauth_register';
    public const string TOO_MANY_REQUESTS = 'too_many_requests';

    public function __construct(private readonly RegisterOAuthClient $registerOAuthClient, private readonly ClockInterface $clock)
    {
    }

    #[Route(OAuthDiscovery::REGISTRATION_PATH, name: self::ROUTE, methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        // One byte over the cap is enough for RegisterOAuthClient to refuse a body that is too large.
        $requestBody = stream_get_contents($request->getContent(true), RegisterOAuthClient::MAX_REQUEST_BYTES + 1);
        try {
            $client = ($this->registerOAuthClient)((string) $request->getClientIp(), false === $requestBody ? '' : $requestBody, $this->clock->now());
        } catch (TooManyOAuthClientRegistrations $refusal) {
            $answer = self::uncached(
                ['error' => self::TOO_MANY_REQUESTS, 'error_description' => \sprintf('Too many client registrations from this address. Try again in %d minutes.', $refusal->minutesToWait)],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $answer->headers->set('Retry-After', (string) ($refusal->minutesToWait * 60));

            return $answer;
        } catch (OAuthClientRegistrationRefused $refusal) {
            return self::uncached(['error' => $refusal->error, 'error_description' => $refusal->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return self::uncached(self::metadataOf($client), Response::HTTP_CREATED);
    }

    /**
     * @return array<string, int|string|list<string>>
     */
    private static function metadataOf(RegisteredOAuthClient $client): array
    {
        $metadata = [
            'client_id' => $client->clientId,
            'client_id_issued_at' => $client->issuedAt->getTimestamp(),
        ];
        if (null !== $client->clientName) {
            $metadata['client_name'] = $client->clientName;
        }

        return $metadata + [
            'redirect_uris' => $client->redirectUris,
            'grant_types' => $client->grantTypes,
            'response_types' => $client->responseTypes,
            'token_endpoint_auth_method' => $client->tokenEndpointAuthMethod,
        ];
    }

    /**
     * @param array<string, int|string|list<string>> $answer
     */
    private static function uncached(array $answer, int $status): JsonResponse
    {
        $response = new JsonResponse($answer, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
