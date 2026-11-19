<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Rest;

use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\AgentApi\Infrastructure\Mcp\ToolCallsLimited;
use App\Identity\Api\CurrentApiToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The request checks of P09.T7 on every request under /api/v1, the other path the api_token firewall guards.
 *
 * Before the router (32) and the firewall (8), so no token is looked up for them: a Host other than DEFAULT_URI's gets 400; an Origin
 * header that is not one of EXTENSION_ORIGINS 403 (CORS lets only the browser extension call from a browser; a client
 * with no Origin, such as curl, is not affected); a CORS preflight (OPTIONS) from an allowed origin is answered here
 * with 204, as it carries no token; a body over 1 MB 413. After EnterChosenOrganization (7) and the request
 * transaction's BEGIN (6): ToolCallLimiter spends the call, the same limits as /mcp, and a spent one gets 429 with
 * Retry-After. Every answer to an allowed origin carries its CORS headers. Holds no state (ADR-0008).
 */
final readonly class RestRequestGuard
{
    public const string UNKNOWN_HOST = 'This server does not answer for that host name.';
    public const string FOREIGN_ORIGIN = 'Only the pinround browser extension may call this API from a browser.';
    public const string BODY_TOO_LARGE = 'The request is over 1 MB. Upload screenshots to the put_url of POST /api/v1/uploads, not inside a request.';
    public const string ALLOWED_HEADERS = 'Authorization, Content-Type, Idempotency-Key';
    public const string EXPOSED_HEADERS = 'Retry-After, Idempotent-Replayed';

    private const string API_PATH = '#\A/api/v1(?:/|\z)#';
    // Before the router (32): no route answers a CORS preflight (OPTIONS), and none needs to.
    private const int CHECK_PRIORITY = 40;
    private const int LIMIT_PRIORITY = 5;
    private const int PREFLIGHT_MAX_AGE_SECONDS = 600;

    public function __construct(
        private ToolCallLimiter $toolCallLimiter,
        private CurrentApiToken $currentApiToken,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $publicBaseUrl,
        #[Autowire(env: 'default::EXTENSION_ORIGINS')]
        private ?string $extensionOrigins,
    ) {
    }

    /**
     * @throws \LogicException when DEFAULT_URI has no host, so every request fails instead of being let in
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::CHECK_PRIORITY)]
    public function refuseUnsafeRequest(RequestEvent $event): void
    {
        if (!self::isMainApiRequest($event)) {
            return;
        }
        $request = $event->getRequest();
        $allowedHost = parse_url($this->publicBaseUrl, \PHP_URL_HOST);
        if (!\is_string($allowedHost) || '' === $allowedHost) {
            throw new \LogicException('DEFAULT_URI must be the public base URL, with a host.');
        }
        if ($request->getHost() !== strtolower($allowedHost)) {
            $event->setResponse(self::refusal(self::UNKNOWN_HOST, Response::HTTP_BAD_REQUEST));

            return;
        }
        $origins = $request->headers->all('Origin');
        if (\count($origins) > 1 || (1 === \count($origins) && !$this->isAllowedOrigin((string) $origins[0]))) {
            $event->setResponse(self::refusal(self::FOREIGN_ORIGIN, Response::HTTP_FORBIDDEN));

            return;
        }
        if (Request::METHOD_OPTIONS === $request->getMethod()) {
            $event->setResponse(new Response(null, Response::HTTP_NO_CONTENT, [
                'Access-Control-Allow-Methods' => 'GET, POST',
                'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
                'Access-Control-Max-Age' => (string) self::PREFLIGHT_MAX_AGE_SECONDS,
            ]));

            return;
        }
        if (self::isBodyOverTheCap($request)) {
            $event->setResponse(self::refusal(self::BODY_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE));
        }
    }

    /**
     * @throws \LogicException when a request reaches here without a token, which the api_token firewall never lets
     *                         through
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::LIMIT_PRIORITY)]
    public function spendCallLimits(RequestEvent $event): void
    {
        if (!self::isMainApiRequest($event) || $event->hasResponse()) {
            return;
        }
        $access = $this->currentApiToken->access()
            ?? throw new \LogicException('A request to /api/v1 passed the api_token firewall without a personal access token.');
        try {
            $this->toolCallLimiter->consume($access);
        } catch (ToolCallsLimited $refusal) {
            $event->setResponse(self::refusal($refusal->getMessage(), Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $refusal->retryAfterSeconds]));
        }
    }

    /**
     * Every answer to an allowed origin, refusals and the firewall's 401 included, so the extension can read why.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function addCorsHeaders(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || 1 !== preg_match(self::API_PATH, $event->getRequest()->getPathInfo())) {
            return;
        }
        $response = $event->getResponse();
        $response->setVary('Origin', false);
        $origins = $event->getRequest()->headers->all('Origin');
        if (1 !== \count($origins) || !$this->isAllowedOrigin((string) $origins[0])) {
            return;
        }
        $response->headers->set('Access-Control-Allow-Origin', (string) $origins[0]);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        foreach (explode(',', $this->extensionOrigins ?? '') as $allowed) {
            $allowed = trim($allowed);
            if ('' !== $allowed && $allowed === $origin) {
                return true;
            }
        }

        return false;
    }

    private static function isMainApiRequest(RequestEvent $event): bool
    {
        return $event->isMainRequest() && 1 === preg_match(self::API_PATH, $event->getRequest()->getPathInfo());
    }

    /**
     * As McpRequestGuard reads it: a declared Content-Length decides alone; without one, one byte past the cap is read.
     *
     * @throws \RuntimeException when the body cannot be read
     */
    private static function isBodyOverTheCap(Request $request): bool
    {
        $declaredLength = $request->headers->get('Content-Length');
        if (null !== $declaredLength && 1 === preg_match('/\A\d+\z/', $declaredLength)) {
            return (int) $declaredLength > McpRequestGuard::MAX_BODY_BYTES;
        }
        $head = stream_get_contents($request->getContent(true), McpRequestGuard::MAX_BODY_BYTES + 1);
        if (false === $head) {
            throw new \RuntimeException('The request body could not be read.');
        }

        return \strlen($head) > McpRequestGuard::MAX_BODY_BYTES;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function refusal(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, $headers);
    }
}
