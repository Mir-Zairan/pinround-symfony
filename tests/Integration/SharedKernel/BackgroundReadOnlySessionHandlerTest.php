<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel;

use App\SharedKernel\Infrastructure\Http\BackgroundReadOnlySessionHandler;
use App\SharedKernel\Infrastructure\Http\BackgroundRequests;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The session handler dev and prod use, on the tests' state Valkey, with requests on the stack as the kernel puts them.
 */
final class BackgroundReadOnlySessionHandlerTest extends KernelTestCase
{
    private const string WITH_FLASH = '_sf2_attributes|a:0:{}_sf2_flashes|a:1:{s:5:"toast";a:1:{i:0;s:14:"Question sent.";}}';
    private const string OLDER_COPY = '_sf2_attributes|a:0:{}_sf2_flashes|a:0:{}';
    private const int IDLE_SECONDS = 28800;

    private \Redis $connection;
    private BackgroundReadOnlySessionHandler $handler;
    private RequestStack $requestStack;
    private string $sessionId;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get('state_valkey.connection');
        $handler = self::getContainer()->get('test.session_handler');
        self::assertInstanceOf(BackgroundReadOnlySessionHandler::class, $handler);
        $this->handler = $handler;
        $this->requestStack = self::getContainer()->get('request_stack');
        $this->sessionId = bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        $this->connection->del('session:'.$this->sessionId);
        parent::tearDown();
    }

    #[Test]
    public function aLiveRefreshAfterAPostThatFlashedLeavesTheStoredSessionAsThePostWroteIt(): void
    {
        $this->inRequest(Request::create('https://localhost/t/SHOP-1/thread', 'POST'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::WITH_FLASH));
        });
        self::assertSame(self::WITH_FLASH, $this->storedSession());

        $liveRefresh = Request::create('https://localhost/t/SHOP-1');
        $liveRefresh->headers->set(BackgroundRequests::LIVE_REFRESH_HEADER, '1');
        $this->inRequest($liveRefresh, function (): void {
            self::assertSame(self::WITH_FLASH, $this->handler->read($this->sessionId));
            self::assertTrue($this->handler->write($this->sessionId, self::OLDER_COPY));
        });

        self::assertSame(self::WITH_FLASH, $this->storedSession());
    }

    #[Test]
    public function aBackgroundRequestNeitherWritesNorRenewsTheSession(): void
    {
        $this->connection->set('session:'.$this->sessionId, self::WITH_FLASH, ['EX' => 60]);
        $request = Request::create('https://localhost/realtime/token', 'POST');
        $request->attributes->set('_route', self::getContainer()->get('router')->matchRequest($request)['_route'] ?? null);

        $this->inRequest($request, function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::OLDER_COPY));
            self::assertTrue($this->handler->updateTimestamp($this->sessionId, self::WITH_FLASH));
        });

        self::assertSame(self::WITH_FLASH, $this->storedSession());
        self::assertLessThanOrEqual(60, $this->connection->ttl('session:'.$this->sessionId));
    }

    #[Test]
    public function aNormalRequestWritesAndRenewsTheSession(): void
    {
        $this->connection->set('session:'.$this->sessionId, self::OLDER_COPY, ['EX' => 60]);

        $this->inRequest(Request::create('https://localhost/bell'), function (): void {
            self::assertTrue($this->handler->updateTimestamp($this->sessionId, self::OLDER_COPY));
        });
        self::assertSame(self::IDLE_SECONDS, $this->connection->ttl('session:'.$this->sessionId));

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/thread', 'POST'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::WITH_FLASH));
        });
        self::assertSame(self::WITH_FLASH, $this->storedSession());
    }

    #[Test]
    public function aWriteOutsideAnyRequestIsSaved(): void
    {
        self::assertNull($this->requestStack->getMainRequest());

        self::assertTrue($this->handler->write($this->sessionId, self::WITH_FLASH));

        self::assertSame(self::WITH_FLASH, $this->storedSession());
    }

    #[Test]
    public function theRequestAfterABackgroundOneInTheSameWorkerWritesAgain(): void
    {
        $liveRefresh = Request::create('https://localhost/t/SHOP-1');
        $liveRefresh->headers->set(BackgroundRequests::LIVE_REFRESH_HEADER, '1');
        $this->inRequest($liveRefresh, function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::OLDER_COPY));
        });
        self::assertFalse($this->storedSession());
        self::getContainer()->get('services_resetter')->reset();

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/thread', 'POST'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::WITH_FLASH));
        });

        self::assertSame(self::WITH_FLASH, $this->storedSession());
    }

    /**
     * @param \Closure(): void $whileHandled
     */
    private function inRequest(Request $request, \Closure $whileHandled): void
    {
        $this->requestStack->push($request);
        try {
            $whileHandled();
        } finally {
            $this->requestStack->pop();
        }
    }

    private function storedSession(): string|false
    {
        $stored = $this->connection->get('session:'.$this->sessionId);
        self::assertTrue(\is_string($stored) || false === $stored);

        return $stored;
    }
}
