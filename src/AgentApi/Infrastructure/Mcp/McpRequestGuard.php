<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Identity\Api\CurrentApiToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The request checks of ADR-0021 on every request under /mcp, the path the api_token firewall guards there.
 *
 * Before the firewall (8), so no token is looked up for them: a Host other than the public base URL's (DEFAULT_URI)
 * gets 400, an Origin header other than that URL's origin 403 (a page of another site, DNS rebinding), and a body over
 * 1 MB 413. A Content-Length over the cap is refused without reading the body; a body without one is read only to one
 * byte past the cap. After EnterChosenOrganization (7) and the request transaction's BEGIN (6), so the token's user is
 * still a member: ToolCallLimiter spends the call, and a spent limit gets 429 with Retry-After. Nothing of the MCP
 * handler runs for a refused request. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final readonly class McpRequestGuard
{
    public const int MAX_BODY_BYTES = 1_048_576;
    public const string UNKNOWN_HOST = 'This server does not answer for that host name.';
    public const string FOREIGN_ORIGIN = 'Requests from other websites are not allowed here.';
    public const string BODY_TOO_LARGE = 'The request is over 1 MB. Upload screenshots with create_upload, not inside a tool call.';

    private const string MCP_PATH = '#\A/mcp(?:/|\z)#';
    private const int CHECK_PRIORITY = 16;
    private const int LIMIT_PRIORITY = 5;
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(
        private ToolCallLimiter $toolCallLimiter,
        private CurrentApiToken $currentApiToken,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $publicBaseUrl,
    ) {
    }

    /**
     * @throws \LogicException when DEFAULT_URI has no scheme or host, so every request fails instead of being let in
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::CHECK_PRIORITY)]
    public function refuseUnsafeRequest(RequestEvent $event): void
    {
        if (!self::isMainMcpRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        [$allowedHost, $allowedOrigin] = $this->allowedHostAndOrigin();
        if ($request->getHost() !== $allowedHost) {
            $event->setResponse(self::refusal(self::UNKNOWN_HOST, Response::HTTP_BAD_REQUEST));

            return;
        }
        foreach ($request->headers->all('Origin') as $origin) {
            if (self::normalizedOrigin((string) $origin) !== $allowedOrigin) {
                $event->setResponse(self::refusal(self::FOREIGN_ORIGIN, Response::HTTP_FORBIDDEN));

                return;
            }
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
        if (!self::isMainMcpRequest($event)) {
            return;
        }

        $access = $this->currentApiToken->access()
            ?? throw new \LogicException('A request to /mcp passed the api_token firewall without a personal access token.');
        try {
            $this->toolCallLimiter->consume($access);
        } catch (ToolCallsLimited $refusal) {
            $event->setResponse(self::refusal($refusal->getMessage(), Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $refusal->retryAfterSeconds]));
        }
    }

    private static function isMainMcpRequest(RequestEvent $event): bool
    {
        return $event->isMainRequest() && 1 === preg_match(self::MCP_PATH, $event->getRequest()->getPathInfo());
    }

    /**
     * @return array{string, string} the host name, lowercase and without port, and the origin of DEFAULT_URI
     */
    private function allowedHostAndOrigin(): array
    {
        $scheme = parse_url($this->publicBaseUrl, \PHP_URL_SCHEME);
        $host = parse_url($this->publicBaseUrl, \PHP_URL_HOST);
        if (!\is_string($scheme) || !\is_string($host) || '' === $host) {
            throw new \LogicException('DEFAULT_URI must be the public base URL, with a scheme and a host.');
        }
        $port = parse_url($this->publicBaseUrl, \PHP_URL_PORT);

        return [strtolower($host), self::normalizedOrigin($scheme.'://'.$host.(\is_int($port) ? ':'.$port : ''))];
    }

    /**
     * Browsers send an origin lowercase, without a trailing slash and without the scheme's default port; anything
     * else, "null" included, never equals the allowed origin.
     */
    private static function normalizedOrigin(string $origin): string
    {
        $origin = strtolower($origin);
        foreach (self::DEFAULT_PORTS as $scheme => $port) {
            $suffix = ':'.$port;
            if (str_starts_with($origin, $scheme.'://') && str_ends_with($origin, $suffix)) {
                return substr($origin, 0, -\strlen($suffix));
            }
        }

        return $origin;
    }

    /**
     * A declared Content-Length decides alone (a digit string past PHP_INT_MAX casts to PHP_INT_MAX); the server never
     * passes on more than it declares. Without one, at most one byte past the cap is read. getContent(true) hands each
     * caller the body from its start, so the MCP handler still reads all of it.
     *
     * @throws \RuntimeException when the body cannot be read
     */
    private static function isBodyOverTheCap(Request $request): bool
    {
        $declaredLength = $request->headers->get('Content-Length');
        if (null !== $declaredLength && 1 === preg_match('/\A\d+\z/', $declaredLength)) {
            return (int) $declaredLength > self::MAX_BODY_BYTES;
        }

        $head = stream_get_contents($request->getContent(true), self::MAX_BODY_BYTES + 1);
        if (false === $head) {
            throw new \RuntimeException('The request body could not be read.');
        }

        return \strlen($head) > self::MAX_BODY_BYTES;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function refusal(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, $headers);
    }
}
