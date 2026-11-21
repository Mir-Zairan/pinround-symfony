<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\OAuthDiscovery;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The OAuth discovery documents (ADR-0050): GET /.well-known/oauth-authorization-server (RFC 8414) and
 * GET /.well-known/oauth-protected-resource for /mcp (RFC 9728). Public by design (security.yaml): an MCP client reads
 * them before it has a token. The same for everyone and free of user data, so shared caches may keep them for an hour;
 * no database is read, so they run without the request transaction. The URLs are absolute on the request's origin, as
 * TokenController's /mcp address is.
 */
final class OAuthDiscoveryController extends AbstractController
{
    public const string AUTHORIZATION_SERVER_ROUTE = 'identity_oauth_authorization_server_metadata';
    public const string PROTECTED_RESOURCE_ROUTE = 'identity_oauth_protected_resource_metadata';
    private const int MAX_AGE_SECONDS = 3600;

    public function __construct(private readonly OAuthDiscovery $discovery)
    {
    }

    #[Route(OAuthDiscovery::AUTHORIZATION_SERVER_METADATA_PATH, name: self::AUTHORIZATION_SERVER_ROUTE, methods: ['GET'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function authorizationServer(Request $request): JsonResponse
    {
        return self::cacheable($this->discovery->authorizationServerMetadata($request->getSchemeAndHttpHost()));
    }

    #[Route(OAuthDiscovery::PROTECTED_RESOURCE_METADATA_PATH, name: self::PROTECTED_RESOURCE_ROUTE, methods: ['GET'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function protectedResource(Request $request): JsonResponse
    {
        return self::cacheable($this->discovery->protectedResourceMetadata($request->getSchemeAndHttpHost()));
    }

    /**
     * @param array<string, string|list<string>> $document
     */
    private static function cacheable(array $document): JsonResponse
    {
        $response = new JsonResponse($document);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_SLASHES);
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
