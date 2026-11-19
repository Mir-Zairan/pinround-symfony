<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * McpRequestGuard on a real HttpKernel, its two listeners registered at the priorities of their AsEventListener
 * attributes, with a controller that counts the requests it handles and ToolCallLimiter as rate_limiter.yaml defines it
 * on an in-memory counter. The clock is frozen 20 seconds into a minute of 2099, so the wait of a refusal (40) differs
 * from the window's length (60).
 */
final class McpRequestGuardTest extends TestCase
{
    private const string PUBLIC_BASE_URL = 'https://pinround.example';
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000d601';
    private const string OMARS_TOKEN = '019a5f00-0000-7000-8000-00000000d611';
    private const string NOW = '2099-03-20 09:00:20 UTC';
    /** The minute of NOW since the epoch: (4077680420 - 20) / 60. */
    private const int MINUTE_OF_NOW = 67961340;
    private const string HANDLED = 'handled by the MCP server';

    private InMemoryStorage $storage;
    private ToolCallLimiter $toolCallLimiter;
    private ?ApiTokenAccess $currentAccess;
    private int $handledRequests = 0;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->toolCallLimiter = ConfiguredToolCallLimiter::on($this->storage, new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->currentAccess = new ApiTokenAccess(self::OMARS_TOKEN, new OrganizationId(self::ACME_FOODS), ['read'], new \DateTimeImmutable('2099-06-18 09:00:20 UTC'));
    }

    #[Test]
    public function aRequestForThePublicHostFromItsOwnOriginReachesTheServerAndSpendsOneCall(): void
    {
        $response = $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', ['HTTP_ORIGIN' => self::PUBLIC_BASE_URL]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::HANDLED, $response->getContent());
        self::assertSame(1, $this->handledRequests);
        self::assertSame(1, $this->hitsOf('mcp_calls_per_token_minute', self::OMARS_TOKEN));
        self::assertSame(1, $this->hitsOf('mcp_calls_per_organization_minute', self::ACME_FOODS));
    }

    #[Test]
    public function theHostIsComparedWithoutCaseAndWithoutTheDefaultPort(): void
    {
        foreach (['https://PinRound.Example/mcp', 'https://pinround.example:443/mcp', 'https://PINROUND.EXAMPLE:443/mcp/'] as $uri) {
            self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost($uri))->getStatusCode(), $uri);
        }

        self::assertSame(3, $this->handledRequests);
    }

    #[Test]
    public function anUnknownHostIsRefusedWith400BeforeAnyCallIsSpent(): void
    {
        foreach (['https://other.example/mcp', 'https://api.pinround.example/mcp', 'https://pinround.example.other.example/mcp', 'https://other.example/mcp/session'] as $uri) {
            $this->assertRefused($this->send(self::mcpPost($uri)), Response::HTTP_BAD_REQUEST, McpRequestGuard::UNKNOWN_HOST, $uri);
        }

        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function anOriginOfThePublicBaseUrlIsLetInWithOrWithoutItsDefaultPort(): void
    {
        foreach (['https://pinround.example', 'https://pinround.example:443', 'HTTPS://PinRound.Example'] as $origin) {
            self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', ['HTTP_ORIGIN' => $origin]))->getStatusCode(), $origin);
        }

        self::assertSame(3, $this->handledRequests);
    }

    #[Test]
    public function aForeignOriginIsRefusedWith403BeforeAnyCallIsSpent(): void
    {
        $foreignOrigins = [
            'https://other.example',
            'http://pinround.example',
            'https://pinround.example:8443',
            'https://pinround.example:80',
            'https://pinround.example/',
            'https://api.pinround.example',
            'null',
            '',
        ];
        foreach ($foreignOrigins as $origin) {
            $this->assertRefused($this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', ['HTTP_ORIGIN' => $origin])), Response::HTTP_FORBIDDEN, McpRequestGuard::FOREIGN_ORIGIN, $origin);
        }

        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function aRequestWithoutAnOriginHeaderIsLetInBecauseOnlyBrowsersSendOne(): void
    {
        $request = self::mcpPost(self::PUBLIC_BASE_URL.'/mcp');
        self::assertFalse($request->headers->has('Origin'));

        self::assertSame(Response::HTTP_OK, $this->send($request)->getStatusCode());
        self::assertSame(1, $this->handledRequests);
    }

    #[Test]
    public function aPublicBaseUrlWithItsOwnPortAllowsThatPortOnlyInTheOrigin(): void
    {
        $url = 'http://localhost:8210';

        self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost($url.'/mcp', ['HTTP_ORIGIN' => $url]), publicBaseUrl: $url)->getStatusCode());
        $this->assertRefused($this->send(self::mcpPost($url.'/mcp', ['HTTP_ORIGIN' => 'http://localhost']), publicBaseUrl: $url), Response::HTTP_FORBIDDEN, McpRequestGuard::FOREIGN_ORIGIN);
        self::assertSame(1, $this->handledRequests);
    }

    #[Test]
    public function requestsOutsideMcpAreLeftAloneEvenFromAnUnknownHostAndOriginWithoutAToken(): void
    {
        $this->currentAccess = null;

        foreach (['/mcpx', '/mcp-tools', '/api/v1/rounds', '/', '/api/v1/mcp'] as $path) {
            $response = $this->send(self::mcpPost('https://other.example'.$path, ['HTTP_ORIGIN' => 'null', 'CONTENT_LENGTH' => '2000000']));
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);
        }

        self::assertSame(5, $this->handledRequests);
        self::assertSame([], $this->spentCounters());
    }

    #[Test]
    public function aSubRequestToMcpIsLeftAlone(): void
    {
        $this->currentAccess = null;

        $response = $this->send(self::mcpPost('https://other.example/mcp', ['HTTP_ORIGIN' => 'null']), HttpKernelInterface::SUB_REQUEST);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $this->handledRequests);
        self::assertSame([], $this->spentCounters());
    }

    #[Test]
    public function aDeclaredLengthOverOneMegabyteIsRefusedWith413WithoutReadingTheBody(): void
    {
        $response = $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', ['CONTENT_LENGTH' => (string) (McpRequestGuard::MAX_BODY_BYTES + 1)], ''));

        $this->assertRefused($response, Response::HTTP_REQUEST_ENTITY_TOO_LARGE, McpRequestGuard::BODY_TOO_LARGE);
        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function aDeclaredLengthPastTheLargestIntegerIsRefusedWith413(): void
    {
        $response = $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', ['CONTENT_LENGTH' => '99999999999999999999999'], ''));

        $this->assertRefused($response, Response::HTTP_REQUEST_ENTITY_TOO_LARGE, McpRequestGuard::BODY_TOO_LARGE);
        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function aBodyOfExactlyOneMegabyteReachesTheServerWholeWithOrWithoutADeclaredLength(): void
    {
        $body = str_repeat('a', McpRequestGuard::MAX_BODY_BYTES);
        $declared = self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', ['CONTENT_LENGTH' => (string) McpRequestGuard::MAX_BODY_BYTES], $body);
        $undeclared = self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', [], $body);
        self::assertFalse($undeclared->headers->has('Content-Length'));

        self::assertSame(Response::HTTP_OK, $this->send($declared)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->send($undeclared)->getStatusCode());
        self::assertSame(2, $this->handledRequests);
        self::assertSame($body, $undeclared->getContent());
    }

    #[Test]
    public function aBodyOverOneMegabyteWithoutAUsableDeclaredLengthIsRefusedWith413(): void
    {
        $body = str_repeat('a', McpRequestGuard::MAX_BODY_BYTES + 1);

        foreach ([[], ['CONTENT_LENGTH' => '-1'], ['CONTENT_LENGTH' => '12 ']] as $server) {
            $request = self::mcpPost(self::PUBLIC_BASE_URL.'/mcp', $server, $body);
            $this->assertRefused($this->send($request), Response::HTTP_REQUEST_ENTITY_TOO_LARGE, McpRequestGuard::BODY_TOO_LARGE, (string) $request->headers->get('Content-Length'));
        }

        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function aPublicBaseUrlWithoutSchemeOrHostFailsEveryMcpRequest(): void
    {
        foreach (['', 'pinround.example', 'https://', '/mcp', 'https:///mcp'] as $publicBaseUrl) {
            try {
                $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp'), publicBaseUrl: $publicBaseUrl);
                self::fail(\sprintf('The request was let through with DEFAULT_URI "%s".', $publicBaseUrl));
            } catch (\LogicException $failure) {
                self::assertSame('DEFAULT_URI must be the public base URL, with a scheme and a host.', $failure->getMessage(), $publicBaseUrl);
            }
        }

        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function anMcpRequestWithoutATokenAtTheLimitStepFailsInsteadOfRunningUnlimited(): void
    {
        $this->currentAccess = null;

        try {
            $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp'));
            self::fail('The request was let through without a token.');
        } catch (\LogicException $failure) {
            self::assertSame('A request to /mcp passed the api_token firewall without a personal access token.', $failure->getMessage());
        }

        $this->assertNothingSpentAndNothingHandled();
    }

    #[Test]
    public function aSpentLimitIsRefusedWith429AndTheSecondsToWaitWithoutReachingTheServer(): void
    {
        for ($call = 1; $call <= 60; ++$call) {
            $this->toolCallLimiter->consume($this->tokenAccess());
        }

        $response = $this->send(self::mcpPost(self::PUBLIC_BASE_URL.'/mcp'));

        $this->assertRefused($response, Response::HTTP_TOO_MANY_REQUESTS, ToolCallLimiter::TOKEN_TOO_FAST);
        self::assertSame('40', $response->headers->get('Retry-After'));
        self::assertSame(0, $this->handledRequests);
        self::assertSame(60, $this->hitsOf('mcp_calls_per_token_minute', self::OMARS_TOKEN));
        self::assertSame(60, $this->hitsOf('mcp_calls_per_organization_minute', self::ACME_FOODS));
    }

    private function send(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST, string $publicBaseUrl = self::PUBLIC_BASE_URL): Response
    {
        $guard = new McpRequestGuard(
            $this->toolCallLimiter,
            new readonly class($this->currentAccess(...)) implements CurrentApiToken {
                /**
                 * @param \Closure(): ?ApiTokenAccess $currentAccess
                 */
                public function __construct(private \Closure $currentAccess)
                {
                }

                public function access(): ?ApiTokenAccess
                {
                    return ($this->currentAccess)();
                }
            },
            $publicBaseUrl,
        );
        $dispatcher = new EventDispatcher();
        $listeners = 0;
        foreach (new \ReflectionClass($guard)->getMethods() as $method) {
            foreach ($method->getAttributes(AsEventListener::class) as $attribute) {
                $listener = $attribute->newInstance();
                self::assertNotNull($listener->event);
                $dispatcher->addListener($listener->event, $method->getClosure($guard), $listener->priority);
                ++$listeners;
            }
        }
        self::assertSame(2, $listeners);

        $kernel = new HttpKernel($dispatcher, new readonly class($this->handle(...)) implements ControllerResolverInterface {
            /**
             * @param \Closure(): Response $controller
             */
            public function __construct(private \Closure $controller)
            {
            }

            public function getController(Request $request): callable
            {
                return $this->controller;
            }
        });

        return $kernel->handle($request, $requestType, false);
    }

    private function handle(): Response
    {
        ++$this->handledRequests;

        return new Response(self::HANDLED);
    }

    private function currentAccess(): ?ApiTokenAccess
    {
        return $this->currentAccess;
    }

    private function tokenAccess(): ApiTokenAccess
    {
        self::assertNotNull($this->currentAccess);

        return $this->currentAccess;
    }

    private function assertRefused(Response $response, int $status, string $message, string $case = ''): void
    {
        self::assertSame($status, $response->getStatusCode(), $case);
        self::assertSame(['error' => $message], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR), $case);
    }

    private function assertNothingSpentAndNothingHandled(): void
    {
        self::assertSame(0, $this->handledRequests);
        self::assertSame([], $this->spentCounters());
    }

    /**
     * @return list<string> the limiter counters that hold a window for the frozen minute
     */
    private function spentCounters(): array
    {
        $spent = [];
        foreach (['mcp_calls_per_token_minute' => self::OMARS_TOKEN, 'mcp_calls_per_organization_minute' => self::ACME_FOODS] as $limiterId => $subject) {
            if (null !== $this->storage->fetch($limiterId.'-'.$subject.':'.self::MINUTE_OF_NOW)) {
                $spent[] = $limiterId;
            }
        }

        return $spent;
    }

    private function hitsOf(string $limiterId, string $subject): int
    {
        $window = $this->storage->fetch($limiterId.'-'.$subject.':'.self::MINUTE_OF_NOW);
        self::assertInstanceOf(Window::class, $window);

        return $window->getHitCount();
    }

    /**
     * @param array<string, string> $server
     */
    private static function mcpPost(string $uri, array $server = [], string $body = '{"jsonrpc":"2.0","id":1,"method":"ping"}'): Request
    {
        return Request::create($uri, 'POST', server: ['CONTENT_TYPE' => 'application/json', ...$server], content: $body);
    }
}
