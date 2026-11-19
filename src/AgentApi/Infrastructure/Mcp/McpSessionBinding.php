<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use Mcp\Schema\JsonRpc\Error;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Binds each MCP session to the personal access token that started it (ADR-0021): the SDK finds a session by its
 * Mcp-Session-Id alone, so without this a session started with one token would answer another.
 *
 * The response to a request without Mcp-Session-Id that carries one (initialize) binds the new session to the
 * request's token id and organization id in cache.mcp_sessions, for TTL_SECONDS. A request with Mcp-Session-Id is
 * checked after the firewall (8), EnterChosenOrganization (7), the transaction's BEGIN (6) and the limits (5), and
 * before the MCP controller: a session bound to another token or organization, or to none (unknown, expired, no UUID,
 * the header sent twice), gets the SDK's own 404 "Session not found or has expired.", so a caller cannot tell another
 * token's session from a missing one, and no handler runs. The response to a passed request refreshes the binding's
 * TTL; one the SDK answered 404, or a DELETE it answered 2xx, forgets it. Holds no state between requests (the checked
 * session is a request attribute), so it is safe in worker mode (ADR-0008).
 */
final readonly class McpSessionBinding
{
    /** As long as the SDK keeps a session: session.ttl in mcp.yaml, which McpServerConfigTest pins to this. */
    public const int TTL_SECONDS = 3600;
    public const string SESSION_NOT_FOUND = 'Session not found or has expired.';

    private const string SESSION_HEADER = 'Mcp-Session-Id';
    private const string MCP_PATH = '#\A/mcp(?:/|\z)#';
    private const string CHECKED_SESSION_ATTRIBUTE = 'agent_api.mcp_checked_session_id';
    // Apart from the SDK's own "mcp-<uuid>" session keys in the same pool.
    private const string KEY_PREFIX = 'mcp_session_binding.';
    private const int CHECK_PRIORITY = 4;

    public function __construct(
        #[Autowire(service: 'cache.mcp_sessions')]
        private AdapterInterface $sessionBindings,
        private CurrentApiToken $currentApiToken,
    ) {
    }

    /**
     * @throws \LogicException when a request with a session reaches here without a token, which the api_token
     *                         firewall never lets through
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::CHECK_PRIORITY)]
    public function refuseSessionOfAnotherToken(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isMcpRequest($request) || !$request->headers->has(self::SESSION_HEADER)) {
            return;
        }

        $access = $this->currentApiToken->access()
            ?? throw new \LogicException('A request to /mcp passed the api_token firewall without a personal access token.');
        $presentedSessionIds = $request->headers->all(self::SESSION_HEADER);
        $sessionId = 1 === \count($presentedSessionIds) ? self::canonicalSessionId((string) $presentedSessionIds[0]) : null;
        if (null === $sessionId || !$this->isBoundTo($sessionId, $access)) {
            $event->setResponse(new JsonResponse(Error::forInvalidRequest(self::SESSION_NOT_FOUND), Response::HTTP_NOT_FOUND));

            return;
        }
        $request->attributes->set(self::CHECKED_SESSION_ATTRIBUTE, $sessionId);
    }

    /**
     * A refresh that cannot be written leaves the binding to run out at its earlier TTL.
     *
     * @throws \RuntimeException when a new session cannot be bound, so initialize fails (500) instead of answering a
     *                           session id that every later request would find missing
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function bindOrRefreshSession(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isMcpRequest($request)) {
            return;
        }
        $response = $event->getResponse();
        $access = $this->currentApiToken->access();

        $checkedSessionId = $request->attributes->get(self::CHECKED_SESSION_ATTRIBUTE);
        if (\is_string($checkedSessionId)) {
            $hasSessionEnded = Response::HTTP_NOT_FOUND === $response->getStatusCode()
                || ($request->isMethod('DELETE') && $response->isSuccessful());
            if ($hasSessionEnded) {
                $this->sessionBindings->deleteItem(self::bindingKey($checkedSessionId));
            } elseif (null !== $access) {
                $this->bind($checkedSessionId, $access);
            }

            return;
        }

        $newSessionId = self::canonicalSessionId((string) $response->headers->get(self::SESSION_HEADER));
        if ($request->headers->has(self::SESSION_HEADER) || null === $newSessionId || null === $access) {
            return;
        }
        if (!$this->bind($newSessionId, $access)) {
            throw new \RuntimeException('The new MCP session could not be bound to its token.');
        }
    }

    private function isBoundTo(string $sessionId, ApiTokenAccess $access): bool
    {
        $binding = $this->sessionBindings->getItem(self::bindingKey($sessionId));

        return $binding->isHit() && self::bindingOf($access) === $binding->get();
    }

    /**
     * @return bool whether the binding was written
     */
    private function bind(string $sessionId, ApiTokenAccess $access): bool
    {
        $binding = $this->sessionBindings->getItem(self::bindingKey($sessionId));
        $binding->set(self::bindingOf($access));
        $binding->expiresAfter(self::TTL_SECONDS);

        return $this->sessionBindings->save($binding);
    }

    /**
     * @return array{token_id: string, organization_id: string}
     */
    private static function bindingOf(ApiTokenAccess $access): array
    {
        return ['token_id' => $access->tokenId, 'organization_id' => $access->organizationId->toString()];
    }

    private static function bindingKey(string $sessionId): string
    {
        return self::KEY_PREFIX.$sessionId;
    }

    /**
     * The SDK reads the header with Uuid::fromString, which also takes the base58 and base32 forms and any case, so
     * the binding is keyed by the RFC 4122 form every spelling of one session maps to.
     */
    private static function canonicalSessionId(string $presentedSessionId): ?string
    {
        try {
            return Uuid::fromString($presentedSessionId)->toRfc4122();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function isMcpRequest(Request $request): bool
    {
        return 1 === preg_match(self::MCP_PATH, $request->getPathInfo());
    }
}
