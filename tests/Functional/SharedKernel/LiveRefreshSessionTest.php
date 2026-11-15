<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\SharedKernel\Infrastructure\Http\BackgroundReadOnlySessionHandler;
use App\SharedKernel\Infrastructure\Http\BackgroundRequests;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Zenstruck\Foundry\Test\Factories;

/**
 * Live refreshes through http_kernel with the native session on the tests' state Valkey, as dev and prod run them (the
 * test env's mock_file storage never reaches the session handler). Each test runs in its own process: PHPUnit has sent
 * output, so PHP would refuse to start a native session.
 */
final class LiveRefreshSessionTest extends KernelTestCase
{
    use Factories;
    use LogsInUsers {
        tearDown as tearDownUsers;
    }

    private const string SESSION_COOKIE = '__Host-pinround';

    private string $sessionId;

    #[Test]
    #[RunInSeparateProcess]
    public function aCsrfTokenFirstMadeByALiveRefreshIsSavedAndAccepted(): void
    {
        $this->startLoggedInSession();

        $refresh = $this->handle($this->liveRefresh('/'));
        self::assertSame(200, $refresh->getStatusCode());
        $logoutToken = new Crawler((string) $refresh->getContent())
            ->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($logoutToken);

        $logout = $this->handle($this->request('/logout', 'POST', ['_csrf_token' => $logoutToken]));

        self::assertSame(302, $logout->getStatusCode());
        self::assertSame('https://localhost/login', $logout->headers->get('Location'));
        self::assertSame(0, $this->connection()->exists($this->sessionKey()));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function aLiveRefreshThatMakesNoNewTokenLeavesTheStoredSessionAndItsTtlAlone(): void
    {
        $this->startLoggedInSession();
        self::assertSame(200, $this->handle($this->request('/'))->getStatusCode());
        $savedByThePage = $this->connection()->get($this->sessionKey());
        self::assertIsString($savedByThePage);
        self::assertStringContainsString('_csrf/https-logout', $savedByThePage);
        $this->connection()->expire($this->sessionKey(), 60);

        self::assertSame(200, $this->handle($this->liveRefresh('/'))->getStatusCode());

        self::assertSame($savedByThePage, $this->connection()->get($this->sessionKey()));
        $ttlSeconds = $this->connection()->ttl($this->sessionKey());
        self::assertGreaterThan(0, $ttlSeconds);
        self::assertLessThanOrEqual(60, $ttlSeconds);
    }

    /**
     * Stores the session as KernelBrowser::loginUser would, with no CSRF token yet.
     */
    private function startLoggedInSession(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        $securityUser = self::getContainer()->get(SecurityUserProvider::class)->loadUserByIdentifier($user->email()->toString());
        $securityToken = new TestBrowserToken($securityUser->getRoles(), $securityUser, 'main');
        $this->sessionId = bin2hex(random_bytes(16));
        $this->connection()->set(
            $this->sessionKey(),
            '_sf2_attributes|'.serialize(['_security_main' => serialize($securityToken)]),
            ['EX' => 28800],
        );
    }

    private function liveRefresh(string $path): Request
    {
        $request = $this->request($path);
        $request->headers->set(BackgroundRequests::LIVE_REFRESH_HEADER, '1');

        return $request;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function request(string $path, string $method = 'GET', array $parameters = []): Request
    {
        $request = Request::create('https://localhost'.$path, $method, $parameters, [self::SESSION_COOKIE => $this->sessionId]);
        $sessionHandler = self::getContainer()->get('test.session_handler');
        self::assertInstanceOf(BackgroundReadOnlySessionHandler::class, $sessionHandler);
        $session = new Session(new NativeSessionStorage(
            ['use_cookies' => false, 'name' => self::SESSION_COOKIE],
            $sessionHandler,
            new MetadataBag('_sf2_meta', 120),
        ));
        $session->setId($this->sessionId);
        $request->setSession($session);

        return $request;
    }

    /**
     * One request of a worker: the services are reset before it, as the runtime does between requests. PHP closes a
     * session left open at the end, when the request has left the stack; that write must find nothing left to save.
     */
    private function handle(Request $request): Response
    {
        self::getContainer()->get('services_resetter')->reset();
        $response = self::getContainer()->get('http_kernel')->handle($request);
        session_write_close();

        return $response;
    }

    private function connection(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }

    private function sessionKey(): string
    {
        return 'session:'.$this->sessionId;
    }

    protected function tearDown(): void
    {
        if (isset($this->sessionId)) {
            $this->connection()->del($this->sessionKey());
        }
        $this->tearDownUsers();
    }
}
