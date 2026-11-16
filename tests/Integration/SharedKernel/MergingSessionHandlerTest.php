<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel;

use App\SharedKernel\Infrastructure\Http\BackgroundReadOnlySessionHandler;
use App\SharedKernel\Infrastructure\Http\MergingSessionHandler;
use App\SharedKernel\Infrastructure\Http\RecordingCsrfTokenStorage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The session handler dev and prod use, on the tests' state Valkey, with two requests of one session as a page's two
 * lazy frames send them: both read the copy the page wrote, each stores its own CSRF token, and they write one after
 * the other.
 */
final class MergingSessionHandlerTest extends KernelTestCase
{
    private const string LOGIN = 'O:8:"stdClass":1:{s:4:"user";s:4:"omar";}';
    private const string THREAD_TOKEN_KEY = '_csrf/https-conversation_thread:SHOP-1';
    private const string DRAFT_TOKEN_KEY = '_csrf/https-review_draft_round:SHOP-1';
    private const string LOGOUT_TOKEN_KEY = '_csrf/https-logout';

    private \Redis $connection;
    private \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $handler;
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
        $this->connection->del($this->sessionKey());
        parent::tearDown();
    }

    #[Test]
    public function twoRequestsThatEachStoredANewTokenLeaveBothTokensStored(): void
    {
        $page = self::encoded([
            '_security_main' => self::LOGIN,
            self::LOGOUT_TOKEN_KEY => 'logout-token',
        ]);
        $this->connection->set($this->sessionKey(), $page);

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/thread'), function () use ($page): void {
            self::assertSame($page, $this->handler->read($this->sessionId));
        });
        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($page): void {
            self::assertSame($page, $this->handler->read($this->sessionId));
        });
        $this->inRequest(Request::create('https://localhost/t/SHOP-1/thread'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded([
                '_security_main' => self::LOGIN,
                self::LOGOUT_TOKEN_KEY => 'logout-token',
                self::THREAD_TOKEN_KEY => 'thread-token',
            ])));
        });
        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded([
                '_security_main' => self::LOGIN,
                self::LOGOUT_TOKEN_KEY => 'logout-token',
                self::DRAFT_TOKEN_KEY => 'draft-token',
            ], ['toast' => ['Draft deleted.']])));
        });

        self::assertSame([
            '_security_main' => self::LOGIN,
            self::LOGOUT_TOKEN_KEY => 'logout-token',
            self::DRAFT_TOKEN_KEY => 'draft-token',
            self::THREAD_TOKEN_KEY => 'thread-token',
        ], $this->storedAttributes());
        self::assertSame(['toast' => ['Draft deleted.']], $this->storedFlashes());
    }

    #[Test]
    public function theLaterWriteKeepsItsOwnValueOfATokenBothStored(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'first']));

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'second'])));
        });

        self::assertSame(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'second'], $this->storedAttributes());
    }

    #[Test]
    public function aTokenThisRequestRemovedIsNotBroughtBack(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']));
        $request = Request::create('https://localhost/t/SHOP-1/thread', 'POST');
        $session = new Session(new MockArraySessionStorage());
        $session->set(self::THREAD_TOKEN_KEY, 'thread-token');
        $request->setSession($session);

        $this->inRequest($request, function (): void {
            self::assertSame('thread-token', $this->csrfTokenStorage()->removeToken('https-conversation_thread:SHOP-1'));
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN])));
        });

        self::assertSame(['_security_main' => self::LOGIN], $this->storedAttributes());
    }

    #[Test]
    public function noTokenComesBackAfterThisRequestClearedThemAll(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']));
        $request = Request::create('https://localhost/logout');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->inRequest($request, function (): void {
            $this->csrfTokenStorage()->clear();
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN])));
        });

        self::assertSame(['_security_main' => self::LOGIN], $this->storedAttributes());
    }

    #[Test]
    public function noTokenOfAnotherLoginIsTakenOver(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded([
            '_security_main' => 'O:8:"stdClass":1:{s:4:"user";s:4:"lena";}',
            self::THREAD_TOKEN_KEY => 'lena-token',
        ]));

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token'])));
        });

        self::assertSame(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token'], $this->storedAttributes());
    }

    #[Test]
    public function noTokenIsTakenOverIntoALoggedOutCopy(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']));

        $this->inRequest(Request::create('https://localhost/login'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_csrf/https-authenticate' => 'login-token'])));
        });

        self::assertSame(['_csrf/https-authenticate' => 'login-token'], $this->storedAttributes());
    }

    #[Test]
    public function noTokenIsAddedToAWrittenCopyWithReferences(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']));
        $object = new \stdClass();
        $written = self::encoded(['_security_main' => self::LOGIN], ['toast' => []]).'shared|'.serialize([$object, $object]);

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($written): void {
            self::assertTrue($this->handler->write($this->sessionId, $written));
        });

        self::assertSame($written, $this->connection->get($this->sessionKey()));
    }

    #[Test]
    public function anUnreadableStoredCopyIsWrittenOver(): void
    {
        $this->connection->set($this->sessionKey(), 'not a session');
        $written = self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token']);

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($written): void {
            self::assertTrue($this->handler->write($this->sessionId, $written));
        });

        self::assertSame($written, $this->connection->get($this->sessionKey()));
    }

    #[Test]
    public function onlyATokenThatIsAStringIsTakenOver(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded([
            '_security_main' => self::LOGIN,
            self::THREAD_TOKEN_KEY => ['not', 'a', 'token'],
            self::LOGOUT_TOKEN_KEY => 'logout-token',
        ]));

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN])));
        });

        self::assertSame(['_security_main' => self::LOGIN, self::LOGOUT_TOKEN_KEY => 'logout-token'], $this->storedAttributes());
    }

    #[Test]
    public function anAttributeThatIsNoTokenIsNotTakenOver(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN, '_csrf_note' => 'kept out', 'organization' => 'acme']));

        $this->inRequest(Request::create('https://localhost/org/choose'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN])));
        });

        self::assertSame(['_security_main' => self::LOGIN], $this->storedAttributes());
    }

    #[Test]
    public function aSessionDestroyedAfterThisRequestReadItIsNotWrittenBack(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN]));

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function (): void {
            self::assertNotSame('', $this->handler->read($this->sessionId));
            $this->connection->del($this->sessionKey());
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token'])));
        });

        self::assertSame(0, $this->connection->exists($this->sessionKey()));
    }

    #[Test]
    public function aNewSessionIsWritten(): void
    {
        $this->inRequest(Request::create('https://localhost/login'), function (): void {
            self::assertSame('', $this->handler->read($this->sessionId));
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_csrf/https-authenticate' => 'login-token'])));
        });

        self::assertSame(['_csrf/https-authenticate' => 'login-token'], $this->storedAttributes());
        self::assertSame(28800, $this->connection->ttl($this->sessionKey()));
    }

    #[Test]
    public function theNextRequestInTheSameWorkerMayWriteASessionAnEarlierOneRead(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN]));
        $this->inRequest(Request::create('https://localhost/t/SHOP-1'), function (): void {
            self::assertNotSame('', $this->handler->read($this->sessionId));
        });
        $this->connection->del($this->sessionKey());
        self::getContainer()->get('services_resetter')->reset();

        $this->inRequest(Request::create('https://localhost/login'), function (): void {
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_csrf/https-authenticate' => 'login-token'])));
        });

        self::assertSame(['_csrf/https-authenticate' => 'login-token'], $this->storedAttributes());
    }

    #[Test]
    public function aWriteThatRacedAnotherOneIsMadeAgainOverTheNewCopy(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN]));
        $racingCopy = self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']);
        $handler = $this->handlerRacedBy([$racingCopy]);

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($handler): void {
            self::assertTrue($handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token'])));
        });

        self::assertSame([
            '_security_main' => self::LOGIN,
            self::DRAFT_TOKEN_KEY => 'draft-token',
            self::THREAD_TOKEN_KEY => 'thread-token',
        ], $this->storedAttributes());
    }

    #[Test]
    public function aWriteRacedEveryTimeFailsAndLeavesTheOtherCopy(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN]));
        $racingCopy = self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']);
        $handler = $this->handlerRacedBy(array_fill(0, MergingSessionHandler::MAX_WRITE_ATTEMPTS, $racingCopy));

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($handler): void {
            self::assertFalse($handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token'])));
        });

        self::assertSame(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token'], $this->storedAttributes());
    }

    /**
     * The connection is the worker's, shared by every later request: one left inside the transaction would only queue
     * their commands.
     */
    #[Test]
    public function aWriteTheValkeyHandlerThrowsOnLeavesTheStoredCopyAndEndsTheTransaction(): void
    {
        $storedCopy = self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']);
        $this->connection->set($this->sessionKey(), $storedCopy);
        $lostConnection = new \RedisException('read error on connection');
        $handler = $this->handlerWritingThrough(static function (RedisSessionHandler $valkeyHandler, string $id, string $data) use ($lostConnection): bool {
            $valkeyHandler->write($id, $data);

            throw $lostConnection;
        });

        $thrown = null;
        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($handler, &$thrown): void {
            try {
                $handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token']));
            } catch (\RedisException $exception) {
                $thrown = $exception;
            }
        });

        self::assertSame($lostConnection, $thrown);
        self::assertSame($storedCopy, $this->connection->get($this->sessionKey()));
        self::assertTrue($this->connection->set($this->sessionKey(), 'later request'));
        self::assertSame('later request', $this->connection->get($this->sessionKey()));
    }

    #[Test]
    public function aWriteValkeyRefusesFailsAndLeavesTheStoredCopy(): void
    {
        $storedCopy = self::encoded(['_security_main' => self::LOGIN, self::THREAD_TOKEN_KEY => 'thread-token']);
        $this->connection->set($this->sessionKey(), $storedCopy);
        $handler = $this->handlerWritingThrough(
            static fn (RedisSessionHandler $valkeyHandler, string $id, string $data): bool => $valkeyHandler->write($id, $data),
            ttlSeconds: 0,
        );

        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function () use ($handler): void {
            self::assertFalse($handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN, self::DRAFT_TOKEN_KEY => 'draft-token'])));
        });

        self::assertSame($storedCopy, $this->connection->get($this->sessionKey()));
    }

    /**
     * A key left watched would make the next transaction on the worker's connection fail once that key changes.
     */
    #[Test]
    public function aSessionNotWrittenBackLeavesNoKeyWatched(): void
    {
        $this->connection->set($this->sessionKey(), self::encoded(['_security_main' => self::LOGIN]));
        $this->inRequest(Request::create('https://localhost/t/SHOP-1/rounds'), function (): void {
            self::assertNotSame('', $this->handler->read($this->sessionId));
            $this->connection->del($this->sessionKey());
            self::assertTrue($this->handler->write($this->sessionId, self::encoded(['_security_main' => self::LOGIN])));
        });
        self::assertSame(0, $this->connection->exists($this->sessionKey()));

        self::assertTrue($this->otherConnection()->set($this->sessionKey(), 'logged in again elsewhere'));
        $this->connection->multi();
        $this->connection->get($this->sessionKey());

        self::assertSame(['logged in again elsewhere'], $this->connection->exec());
    }

    /**
     * @param array<string, mixed>        $attributes
     * @param array<string, list<string>> $flashes
     */
    private static function encoded(array $attributes, array $flashes = []): string
    {
        return '_sf2_attributes|'.serialize($attributes).'_sf2_flashes|'.serialize($flashes).'_sf2_meta|'.serialize(['u' => 1, 'c' => 1, 'l' => 0]);
    }

    /**
     * The real Valkey handler, which a second request on its own connection writes over between this request's read of
     * the stored copy and its write, once for each copy given.
     *
     * @param list<string> $racingCopies
     */
    private function handlerRacedBy(array $racingCopies): MergingSessionHandler
    {
        $otherConnection = $this->otherConnection();
        $keyPrefix = $this->keyPrefix();

        return $this->handlerWritingThrough(static function (RedisSessionHandler $valkeyHandler, string $id, string $data) use ($otherConnection, $keyPrefix, &$racingCopies): bool {
            $racingCopy = array_shift($racingCopies);
            if (null !== $racingCopy) {
                $otherConnection->set($keyPrefix.$id, $racingCopy);
            }

            return $valkeyHandler->write($id, $data);
        });
    }

    /**
     * The handler over the real Valkey one, as the services wire it, with each write of the Valkey handler made by
     * $write instead.
     *
     * @param \Closure(RedisSessionHandler, string, string): bool $write
     */
    private function handlerWritingThrough(\Closure $write, ?int $ttlSeconds = null): MergingSessionHandler
    {
        $valkeyHandler = new RedisSessionHandler($this->connection, [
            'prefix' => $this->keyPrefix(),
            'ttl' => $ttlSeconds ?? self::getContainer()->getParameter('identity.session_idle_seconds'),
        ]);
        $innerHandler = new class($valkeyHandler, $write) implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface {
            /**
             * @param \Closure(RedisSessionHandler, string, string): bool $write
             */
            public function __construct(
                private readonly RedisSessionHandler $valkeyHandler,
                private readonly \Closure $write,
            ) {
            }

            public function open(string $path, string $name): bool
            {
                return $this->valkeyHandler->open($path, $name);
            }

            public function close(): bool
            {
                return $this->valkeyHandler->close();
            }

            public function read(string $id): string|false
            {
                return $this->valkeyHandler->read($id);
            }

            public function write(string $id, string $data): bool
            {
                return ($this->write)($this->valkeyHandler, $id, $data);
            }

            public function destroy(string $id): bool
            {
                return $this->valkeyHandler->destroy($id);
            }

            public function gc(int $max_lifetime): int|false
            {
                return $this->valkeyHandler->gc($max_lifetime);
            }

            public function validateId(string $id): bool
            {
                return $this->valkeyHandler->validateId($id);
            }

            public function updateTimestamp(string $id, string $data): bool
            {
                return $this->valkeyHandler->updateTimestamp($id, $data);
            }
        };

        return new MergingSessionHandler($innerHandler, $this->connection, $this->keyPrefix(), $this->csrfTokenStorage());
    }

    /**
     * A connection of its own, as another request in another worker has.
     */
    private function otherConnection(): \Redis
    {
        $dsn = $_SERVER['TEST_STATE_VALKEY_DSN'] ?? null;
        self::assertIsString($dsn);
        $otherConnection = RedisAdapter::createConnection($dsn);
        self::assertInstanceOf(\Redis::class, $otherConnection);

        return $otherConnection;
    }

    private function keyPrefix(): string
    {
        return self::getContainer()->getParameter('app.session_key_prefix');
    }

    private function sessionKey(): string
    {
        return $this->keyPrefix().$this->sessionId;
    }

    private function csrfTokenStorage(): RecordingCsrfTokenStorage
    {
        return self::getContainer()->get('security.csrf.token_storage');
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

    /**
     * @return array<mixed>
     */
    private function storedAttributes(): array
    {
        return $this->storedSegment('_sf2_attributes|', '_sf2_flashes|');
    }

    /**
     * @return array<mixed>
     */
    private function storedFlashes(): array
    {
        return $this->storedSegment('_sf2_flashes|', '_sf2_meta|');
    }

    /**
     * @return array<mixed>
     */
    private function storedSegment(string $name, string $nextName): array
    {
        $stored = $this->connection->get($this->sessionKey());
        self::assertIsString($stored);
        $start = strpos($stored, $name);
        $end = strpos($stored, $nextName);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $segment = unserialize(substr($stored, $start + \strlen($name), $end - $start - \strlen($name)), ['allowed_classes' => false]);
        self::assertIsArray($segment);

        return $segment;
    }
}
