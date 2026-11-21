<?php

declare(strict_types=1);

namespace App\Identity\UI;

use App\Identity\Application\OAuthDiscovery;
use App\SharedKernel\Infrastructure\Http\ExtensionOrigins;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS for the OAuth endpoints the browser extension connects through (ADR-0050): client registration, the token and
 * revocation endpoints and the authorization server metadata. The extension has no host permissions, so it reads these
 * answers only through CORS; like /api/v1 (RestRequestGuard), only an origin of EXTENSION_ORIGINS may, by its exact
 * origin and never "*", and without credentials, as no endpoint here reads a cookie. A preflight from an allowed origin
 * is answered before the router (32), as no route takes OPTIONS; one from any other origin gets the router's 405. Any
 * other origin's request still runs, as a client without a browser sends none, but its answer carries no CORS header.
 * Holds no state (ADR-0008).
 */
final readonly class OAuthEndpointCors
{
    public const string ALLOWED_HEADERS = 'Content-Type';
    public const string EXPOSED_HEADERS = 'Retry-After';

    /** Each endpoint's methods, for Access-Control-Allow-Methods. */
    private const array METHODS_BY_PATH = [
        OAuthDiscovery::REGISTRATION_PATH => 'POST',
        OAuthDiscovery::TOKEN_PATH => 'POST',
        OAuthDiscovery::REVOCATION_PATH => 'POST',
        OAuthDiscovery::AUTHORIZATION_SERVER_METADATA_PATH => 'GET',
    ];
    // Before the router (32), which answers OPTIONS on these routes with 405, and the firewall (8).
    private const int PREFLIGHT_PRIORITY = 40;
    private const int PREFLIGHT_MAX_AGE_SECONDS = 600;

    public function __construct(private ExtensionOrigins $extensionOrigins)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::PREFLIGHT_PRIORITY)]
    public function answerPreflight(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $methods = self::methodsOf($request);
        if (!$event->isMainRequest() || null === $methods || Request::METHOD_OPTIONS !== $request->getMethod() || null === $this->extensionOrigins->allowedOriginOf($request)) {
            return;
        }
        $event->setResponse(new Response(null, Response::HTTP_NO_CONTENT, [
            'Access-Control-Allow-Methods' => $methods,
            'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
            'Access-Control-Max-Age' => (string) self::PREFLIGHT_MAX_AGE_SECONDS,
        ]));
    }

    /**
     * Every answer of these endpoints, refusals and 429s included, so the extension can read why. Vary: Origin on all
     * of them, as the metadata document is cached publicly and must not be served to another origin with this one's
     * Access-Control-Allow-Origin.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function addCorsHeaders(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === self::methodsOf($event->getRequest())) {
            return;
        }
        $response = $event->getResponse();
        $response->setVary('Origin', false);
        $origin = $this->extensionOrigins->allowedOriginOf($event->getRequest());
        if (null === $origin) {
            return;
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
    }

    private static function methodsOf(Request $request): ?string
    {
        return self::METHODS_BY_PATH[$request->getPathInfo()] ?? null;
    }
}
