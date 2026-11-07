<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\EndExpiredSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class EndExpiredSessionTest extends TestCase
{
    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const int IDLE_SECONDS = 8 * 3600;
    private const int ABSOLUTE_DAYS = 30;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expiredSessions(): iterable
    {
        yield 'last used 8 h and 1 s ago' => ['-1 day', '-8 hours -1 second'];
        yield 'created 30 days and 1 s ago, used a minute ago' => ['-30 days -1 second', '-1 minute'];
    }

    #[Test]
    #[DataProvider('expiredSessions')]
    public function itEndsASessionPastALimit(string $createdAgo, string $lastUsedAgo): void
    {
        $session = self::sessionOf($createdAgo, $lastUsedAgo);
        $session->start();
        $idBefore = $session->getId();

        self::endExpiredSession(self::requestWithCookieFor($session));

        self::assertFalse($session->has('_security_main'));
        self::assertNotSame($idBefore, $session->getId());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function liveSessions(): iterable
    {
        yield 'last used 7 h 59 min ago' => ['-1 day', '-7 hours -59 minutes'];
        yield 'last used exactly 8 h ago' => ['-1 day', '-8 hours'];
        yield 'created exactly 30 days ago, used a minute ago' => ['-30 days', '-1 minute'];
    }

    #[Test]
    #[DataProvider('liveSessions')]
    public function itKeepsASessionWithinBothLimits(string $createdAgo, string $lastUsedAgo): void
    {
        $session = self::sessionOf($createdAgo, $lastUsedAgo);
        $session->start();
        $idBefore = $session->getId();

        self::endExpiredSession(self::requestWithCookieFor($session));

        self::assertSame('the token', $session->get('_security_main'));
        self::assertSame($idBefore, $session->getId());
    }

    #[Test]
    public function itLeavesARequestWithoutASessionCookieAlone(): void
    {
        $session = self::sessionOf('-31 days', '-9 hours');
        $request = new Request();
        $request->setSession($session);

        self::endExpiredSession($request);

        self::assertFalse($session->isStarted());
    }

    #[Test]
    public function itLeavesASubRequestAlone(): void
    {
        $session = self::sessionOf('-31 days', '-9 hours');

        self::endExpiredSession(self::requestWithCookieFor($session), HttpKernelInterface::SUB_REQUEST);

        self::assertSame('the token', $session->get('_security_main'));
    }

    #[Test]
    public function itLeavesARequestUnderAFirewallWithoutSecurityAlone(): void
    {
        $session = self::sessionOf('-31 days', '-9 hours');

        self::endExpiredSession(self::requestWithCookieFor($session, '/health/live'));

        self::assertFalse($session->isStarted());
        self::assertSame('the token', $session->get('_security_main'));
    }

    #[Test]
    public function itLeavesARequestNoFirewallMatchesAlone(): void
    {
        $session = self::sessionOf('-31 days', '-9 hours');
        $onlyHealth = new FirewallMap(
            new ServiceLocator(['health' => static fn (): FirewallContext => new FirewallContext([], config: new FirewallConfig('health', 'user_checker', securityEnabled: false))]),
            ['health' => new PathRequestMatcher('^/health/')],
        );

        self::endExpiredSession(self::requestWithCookieFor($session), firewallMap: $onlyHealth);

        self::assertFalse($session->isStarted());
    }

    /**
     * The firewalls of security.yaml: health without security, then main for every other path.
     */
    private static function firewallMap(): FirewallMap
    {
        return new FirewallMap(
            new ServiceLocator([
                'health' => static fn (): FirewallContext => new FirewallContext([], config: new FirewallConfig('health', 'user_checker', securityEnabled: false)),
                'main' => static fn (): FirewallContext => new FirewallContext([], config: new FirewallConfig('main', 'user_checker')),
            ]),
            ['health' => new PathRequestMatcher('^/health/'), 'main' => null],
        );
    }

    private static function endExpiredSession(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST, ?FirewallMap $firewallMap = null): void
    {
        $listener = new EndExpiredSession(new MockClock(self::NOW), $firewallMap ?? self::firewallMap(), self::IDLE_SECONDS, self::ABSOLUTE_DAYS);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new \LogicException('Not called.');
            }
        };

        $listener(new RequestEvent($kernel, $request, $requestType));
    }

    private static function sessionOf(string $createdAgo, string $lastUsedAgo): Session
    {
        $now = new \DateTimeImmutable(self::NOW);
        $storage = new MockArraySessionStorage();
        $storage->setSessionData([
            '_sf2_meta' => ['c' => $now->modify($createdAgo)->getTimestamp(), 'u' => $now->modify($lastUsedAgo)->getTimestamp(), 'l' => 0],
            '_sf2_attributes' => ['_security_main' => 'the token'],
        ]);

        return new Session($storage);
    }

    private static function requestWithCookieFor(Session $session, string $path = '/'): Request
    {
        $request = Request::create($path, cookies: [$session->getName() => 'the session id']);
        $request->setSession($session);

        return $request;
    }
}
