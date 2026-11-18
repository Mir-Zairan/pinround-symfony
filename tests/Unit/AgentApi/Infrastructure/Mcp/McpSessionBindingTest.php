<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\McpSessionBinding;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * McpSessionBinding on a real HttpKernel, its listeners registered at the priorities of their AsEventListener
 * attributes, with a controller that stands in for the SDK: it answers a request without Mcp-Session-Id with a new
 * session id, as initialize does, and counts the requests it handles. The bindings live in an ArrayAdapter on a frozen
 * clock, so a binding's lifetime is measured on it.
 */
final class McpSessionBindingTest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000d601';
    private const string BETA_BAKERY = '019a5f00-0000-7000-8000-00000000d602';
    private const string OMARS_TOKEN = '019a5f00-0000-7000-8000-00000000d611';
    private const string OMARS_OTHER_TOKEN = '019a5f00-0000-7000-8000-00000000d612';
    private const string NEW_SESSION = '019a5f00-0000-4000-8000-00000000d6a1';
    private const string NOW = '2099-03-20 09:00:20 UTC';
    private const string HANDLED = 'handled by the MCP server';
    private const string BINDING_KEY = 'mcp_session_binding.'.self::NEW_SESSION;

    private MockClock $clock;
    private ArrayAdapter $sessionBindings;
    private ?ApiTokenAccess $currentAccess;
    private int $handledRequests = 0;
    private int $sdkStatus = Response::HTTP_OK;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $this->sessionBindings = new ArrayAdapter(clock: $this->clock);
        $this->currentAccess = self::access(self::OMARS_TOKEN, self::ACME_FOODS);
    }

    #[Test]
    public function initializeBindsTheNewSessionToTheTokenAndItsOrganization(): void
    {
        $response = $this->send(self::mcpPost());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::NEW_SESSION, $response->headers->get('Mcp-Session-Id'));
        self::assertSame(['token_id' => self::OMARS_TOKEN, 'organization_id' => self::ACME_FOODS], $this->binding());
    }

    #[Test]
    public function theTokenThatStartedTheSessionReachesTheServerWithIt(): void
    {
        $this->send(self::mcpPost());

        $response = $this->send(self::mcpPost(self::NEW_SESSION));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::HANDLED, $response->getContent());
        self::assertSame(2, $this->handledRequests);
    }

    #[Test]
    public function everySpellingTheSdkReadsAsTheSameSessionIsTheSameBinding(): void
    {
        $this->send(self::mcpPost());

        foreach ([strtoupper(self::NEW_SESSION), Uuid::fromString(self::NEW_SESSION)->toBase58(), Uuid::fromString(self::NEW_SESSION)->toBase32()] as $spelling) {
            self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost($spelling))->getStatusCode(), $spelling);
        }
        self::assertSame(4, $this->handledRequests);
    }

    #[Test]
    public function anotherTokenOfTheSameUserAndOrganizationGetsTheSdks404AndReachesNothing(): void
    {
        $this->send(self::mcpPost());
        $this->currentAccess = self::access(self::OMARS_OTHER_TOKEN, self::ACME_FOODS);

        $this->assertSessionNotFound($this->send(self::mcpPost(self::NEW_SESSION)));
        self::assertSame(1, $this->handledRequests);
        self::assertSame(['token_id' => self::OMARS_TOKEN, 'organization_id' => self::ACME_FOODS], $this->binding(), 'the refusal changed the binding');
    }

    #[Test]
    public function theSameTokenIdInAnotherOrganizationGets404(): void
    {
        $this->send(self::mcpPost());
        $this->currentAccess = self::access(self::OMARS_TOKEN, self::BETA_BAKERY);

        $this->assertSessionNotFound($this->send(self::mcpPost(self::NEW_SESSION)));
        self::assertSame(1, $this->handledRequests);
    }

    #[Test]
    public function aSessionWithoutABindingGets404(): void
    {
        $refused = [
            'unknown' => [self::NEW_SESSION],
            'no UUID' => ['not-a-session'],
            'empty' => [''],
        ];
        foreach ($refused as $case => [$sessionId]) {
            $this->assertSessionNotFound($this->send(self::mcpPost($sessionId)), $case);
        }

        self::assertSame(0, $this->handledRequests);
        self::assertNull($this->binding(), 'a refused request bound a session');
    }

    #[Test]
    public function aSessionHeaderSentTwiceGets404EvenWhenBothAreBound(): void
    {
        $this->send(self::mcpPost());
        $request = self::mcpPost(self::NEW_SESSION);
        $request->headers->set('Mcp-Session-Id', [self::NEW_SESSION, self::NEW_SESSION]);

        $this->assertSessionNotFound($this->send($request));
        self::assertSame(1, $this->handledRequests);
    }

    #[Test]
    public function aBindingLastsAnHourFromItsLastRequest(): void
    {
        $this->send(self::mcpPost());
        $this->clock->sleep(McpSessionBinding::TTL_SECONDS - 60);

        self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost(self::NEW_SESSION))->getStatusCode());
        $this->clock->sleep(McpSessionBinding::TTL_SECONDS - 60);
        self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost(self::NEW_SESSION))->getStatusCode(), 'the request before did not refresh the binding');

        $this->clock->sleep(McpSessionBinding::TTL_SECONDS);
        $this->assertSessionNotFound($this->send(self::mcpPost(self::NEW_SESSION)));
        self::assertSame(3, $this->handledRequests);
    }

    #[Test]
    public function aSessionTheSdkNoLongerKnowsOrEndedLosesItsBinding(): void
    {
        foreach (['the SDK answers 404' => ['POST', Response::HTTP_NOT_FOUND], 'the SDK ends it on DELETE' => ['DELETE', Response::HTTP_OK]] as $case => [$method, $sdkStatus]) {
            $this->sdkStatus = Response::HTTP_OK;
            $this->send(self::mcpPost());
            self::assertNotNull($this->binding(), $case);
            $this->sdkStatus = $sdkStatus;
            $request = self::mcpPost(self::NEW_SESSION);
            $request->setMethod($method);

            self::assertSame($sdkStatus, $this->send($request)->getStatusCode(), $case);
            self::assertNull($this->binding(), $case);
        }
    }

    #[Test]
    public function aFailedDeleteKeepsTheBinding(): void
    {
        $this->send(self::mcpPost());
        $this->sdkStatus = Response::HTTP_BAD_REQUEST;
        $request = self::mcpPost(self::NEW_SESSION);
        $request->setMethod('DELETE');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->send($request)->getStatusCode());
        self::assertSame(['token_id' => self::OMARS_TOKEN, 'organization_id' => self::ACME_FOODS], $this->binding());
    }

    #[Test]
    public function requestsOutsideMcpAndSubRequestsAreLeftAlone(): void
    {
        foreach (['/mcpx', '/api/v1/rounds', '/'] as $path) {
            $response = $this->send(Request::create('https://pinround.example'.$path, 'POST', server: ['HTTP_MCP_SESSION_ID' => self::NEW_SESSION]));
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);
        }
        $response = $this->send(self::mcpPost(self::NEW_SESSION), HttpKernelInterface::SUB_REQUEST);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->send(Request::create('https://pinround.example/api/v1/rounds', 'POST'));
        self::assertSame(5, $this->handledRequests);
        self::assertNull($this->binding(), 'a request outside /mcp bound a session');
    }

    /**
     * As RedisAdapter answers a write Valkey refused: save() is false and nothing is stored.
     */
    #[Test]
    public function initializeFailsWhenTheNewSessionCannotBeBound(): void
    {
        $this->sessionBindings = new class(clock: $this->clock) extends ArrayAdapter {
            public function save(mixed $item): bool
            {
                return false;
            }
        };

        try {
            $this->send(self::mcpPost());
            self::fail('initialize answered a session that has no binding.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The new MCP session could not be bound to its token.', $failure->getMessage());
        }
        self::assertNull($this->binding());
    }

    /**
     * The tool has run by then, so the answer stands; the binding keeps its earlier expiry.
     */
    #[Test]
    public function aRefreshThatCannotBeWrittenStillAnswersAndKeepsTheEarlierExpiry(): void
    {
        $sessionBindings = new class(clock: $this->clock) extends ArrayAdapter {
            public bool $isRefusingWrites = false;

            public function save(mixed $item): bool
            {
                return !$this->isRefusingWrites && parent::save($item);
            }
        };
        $this->sessionBindings = $sessionBindings;
        $this->send(self::mcpPost());
        $sessionBindings->isRefusingWrites = true;
        $this->clock->sleep(McpSessionBinding::TTL_SECONDS - 60);

        self::assertSame(Response::HTTP_OK, $this->send(self::mcpPost(self::NEW_SESSION))->getStatusCode());
        $this->clock->sleep(120);
        $this->assertSessionNotFound($this->send(self::mcpPost(self::NEW_SESSION)));
        self::assertSame(2, $this->handledRequests);
    }

    #[Test]
    public function aSessionRequestWithoutATokenFailsInsteadOfBeingLetThrough(): void
    {
        $this->currentAccess = null;

        try {
            $this->send(self::mcpPost(self::NEW_SESSION));
            self::fail('A session request was let through without a token.');
        } catch (\LogicException $failure) {
            self::assertSame('A request to /mcp passed the api_token firewall without a personal access token.', $failure->getMessage());
        }
        self::assertSame(0, $this->handledRequests);
    }

    private function send(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $binding = new McpSessionBinding(
            $this->sessionBindings,
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
        );
        $dispatcher = new EventDispatcher();
        $listeners = 0;
        foreach (new \ReflectionClass($binding)->getMethods() as $method) {
            foreach ($method->getAttributes(AsEventListener::class) as $attribute) {
                $listener = $attribute->newInstance();
                self::assertNotNull($listener->event);
                $dispatcher->addListener($listener->event, $method->getClosure($binding), $listener->priority);
                ++$listeners;
            }
        }
        self::assertSame(2, $listeners);

        $kernel = new HttpKernel($dispatcher, new readonly class($this->handle(...)) implements ControllerResolverInterface {
            /**
             * @param \Closure(Request): Response $controller
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

    private function handle(Request $request): Response
    {
        ++$this->handledRequests;
        if (!$request->headers->has('Mcp-Session-Id')) {
            return new Response(self::HANDLED, Response::HTTP_OK, ['Mcp-Session-Id' => self::NEW_SESSION]);
        }

        return new Response(self::HANDLED, $this->sdkStatus);
    }

    private function currentAccess(): ?ApiTokenAccess
    {
        return $this->currentAccess;
    }

    private function binding(): mixed
    {
        $item = $this->sessionBindings->getItem(self::BINDING_KEY);

        return $item->isHit() ? $item->get() : null;
    }

    private function assertSessionNotFound(Response $response, string $case = ''): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $case);
        self::assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => McpSessionBinding::SESSION_NOT_FOUND]],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
            $case,
        );
    }

    private static function access(string $tokenId, string $organizationId): ApiTokenAccess
    {
        return new ApiTokenAccess($tokenId, new OrganizationId($organizationId), ['read'], new \DateTimeImmutable('2099-06-18 09:00:20 UTC'));
    }

    private static function mcpPost(?string $sessionId = null): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        return Request::create('https://pinround.example/mcp', 'POST', server: $server, content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    }
}
