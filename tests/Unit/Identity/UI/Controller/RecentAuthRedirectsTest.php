<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Controller;

use App\Identity\UI\Controller\RecentAuthRedirects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

/**
 * With a router of three routes: Home, a GET page and a POST-only action.
 */
final class RecentAuthRedirectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function safePaths(): iterable
    {
        yield 'Home' => ['/'];
        yield 'a page' => ['/me/tokens'];
        yield 'a page with a query' => ['/me/tokens?tab=new&page=2'];
        yield 'a path of exactly 2000 characters' => ['/?'.str_repeat('a', 1998)];
        yield 'a POST-only action' => ['/me/tokens/create'];
    }

    #[Test]
    #[DataProvider('safePaths')]
    public function aPathOfARouteOfThisAppIsKept(string $path): void
    {
        self::assertSame($path, $this->redirects()->safeReturnPath($path));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'nothing' => [null];
        yield 'empty' => [''];
        yield 'a URL of another site' => ['https://evil.example/me/tokens'];
        yield 'a URL of another site without a scheme' => ['//evil.example/me/tokens'];
        yield 'a backslash a browser reads as a slash' => ['/\\evil.example/me/tokens'];
        yield 'a backslash later in the path' => ['/me\\tokens'];
        yield 'a relative path' => ['me/tokens'];
        yield 'a scheme only' => ['javascript:alert(1)'];
        yield 'a tab inside the slashes' => ["/\t/evil.example"];
        yield 'a line break' => ["/me/tokens\r\nLocation: https://evil.example"];
        yield 'a path no route has' => ['/no/such/page'];
        yield 'a path over 2000 characters' => ['/?'.str_repeat('a', 1999)];
    }

    #[Test]
    #[DataProvider('unsafePaths')]
    public function anythingElseIsRefused(?string $path): void
    {
        self::assertNull($this->redirects()->safeReturnPath($path));
    }

    #[Test]
    public function aGetReturnsToThePageAskedFor(): void
    {
        $request = Request::create('https://pinround.example/me/tokens?tab=new');

        self::assertSame('/me/tokens?tab=new', $this->redirects()->returnPathOf($request));
    }

    #[Test]
    public function aPostReturnsToThePageOfItsFormWhenTheRefererIsThisSite(): void
    {
        $request = Request::create('https://pinround.example/me/tokens/create', 'POST', server: ['HTTP_REFERER' => 'https://pinround.example/me/tokens?tab=new']);

        self::assertSame('/me/tokens?tab=new', $this->redirects()->returnPathOf($request));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function foreignReferers(): iterable
    {
        yield 'no Referer' => [null];
        yield 'another site' => ['https://evil.example/me/tokens'];
        yield 'a host that starts like this one' => ['https://pinround.example.evil.example/me/tokens'];
        yield 'this host over plain HTTP' => ['http://pinround.example/me/tokens'];
        yield 'a page no route has' => ['https://pinround.example/no/such/page'];
    }

    #[Test]
    #[DataProvider('foreignReferers')]
    public function aPostReturnsHomeWithoutARefererOfThisSite(?string $referer): void
    {
        $server = null === $referer ? [] : ['HTTP_REFERER' => $referer];
        $request = Request::create('https://pinround.example/me/tokens/create', 'POST', server: $server);

        self::assertSame('/', $this->redirects()->returnPathOf($request));
    }

    #[Test]
    public function theSetupPathIsKeptOnceAndTakenOnlyWhenSafe(): void
    {
        $redirects = $this->redirects();
        $request = Request::create('https://pinround.example/me/tokens');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $redirects->toTwoStepSetup($request, '/me/tokens');

        self::assertSame('/me/tokens', $redirects->takeAfterTwoStepSetup($request));
        self::assertNull($redirects->takeAfterTwoStepSetup($request), 'the path was taken twice');
        $request->getSession()->set(RecentAuthRedirects::AFTER_TWO_STEP_SETUP_KEY, '//evil.example');
        self::assertNull($redirects->takeAfterTwoStepSetup($request));
        self::assertFalse($request->getSession()->has(RecentAuthRedirects::AFTER_TWO_STEP_SETUP_KEY));
    }

    #[Test]
    public function anotherModuleGetsTheConfirmPathWithTheWayBack(): void
    {
        $redirects = $this->redirects();

        self::assertSame('/me/confirm?return=/me/tokens?tab%3Dnew', $redirects->confirmItsYouPathFor(Request::create('https://pinround.example/me/tokens?tab=new')));
        self::assertSame('/me/confirm?return=/', $redirects->confirmItsYouPathFor(Request::create('https://pinround.example/me/tokens/create', 'POST')));
    }

    #[Test]
    public function anotherModuleGetsTheSetupPathWithItsOwnNoticeAndTheWayBackKept(): void
    {
        $redirects = $this->redirects();
        $request = Request::create('https://pinround.example/me/tokens');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        self::assertSame('/me/security/two-step', $redirects->sendToTwoStepSetup($request, 'Admin pages need two-step login. Turn it on to continue.'));

        self::assertSame(['notice' => ['Admin pages need two-step login. Turn it on to continue.']], $session->getFlashBag()->peekAll());
        self::assertSame('/me/tokens', $redirects->takeAfterTwoStepSetup($request));
    }

    #[Test]
    public function anythingButARequestIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->redirects()->confirmItsYouPathFor('/me/tokens');
    }

    private function redirects(): RecentAuthRedirects
    {
        $routes = new RouteCollection();
        $routes->add('web_home', new Route('/', methods: ['GET']));
        $routes->add('identity_two_step', new Route('/me/security/two-step', methods: ['GET']));
        $routes->add('identity_confirm', new Route('/me/confirm', methods: ['GET', 'POST']));
        $routes->add('tokens', new Route('/me/tokens', methods: ['GET']));
        $routes->add('tokens_create', new Route('/me/tokens/create', methods: ['POST']));

        return new RecentAuthRedirects(new Router(new ClosureLoader(), static fn (): RouteCollection => $routes, [], new RequestContext()));
    }
}
