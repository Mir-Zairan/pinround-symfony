<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI;

use App\SharedKernel\UI\PageFlashes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PageFlashesTest extends TestCase
{
    #[Test]
    public function aPageTakesItsTypesFlashesInOrderAndLeavesOtherTypes(): void
    {
        $session = $this->sessionWithFlashes();
        $requestStack = $this->requestStackWith(Request::create('/me/profile'), $session);

        self::assertSame(['Your profile is saved.', 'Log in again.'], new PageFlashes($requestStack)->take('notice'));
        self::assertSame([], $session->getFlashBag()->peek('notice'));
        self::assertSame(['Comment added.'], $session->getFlashBag()->peek('toast'));
    }

    #[Test]
    public function aLiveRefreshGetsNoneAndLeavesThemWaiting(): void
    {
        $session = $this->sessionWithFlashes();
        $requestStack = $this->requestStackWith(Request::create('/me/profile', server: ['HTTP_X_LIVE_REFRESH' => '1']), $session);

        self::assertSame([], new PageFlashes($requestStack)->take('notice'));
        self::assertSame(['Your profile is saved.', 'Log in again.'], $session->getFlashBag()->peek('notice'));
    }

    #[Test]
    public function aSubRequestOfALiveRefreshGetsNoneEither(): void
    {
        $session = $this->sessionWithFlashes();
        $requestStack = $this->requestStackWith(Request::create('/t/SHOP-1', server: ['HTTP_X_LIVE_REFRESH' => '1']), $session);
        $subRequest = Request::create('/_fragment');
        $subRequest->setSession($session);
        $requestStack->push($subRequest);

        self::assertSame([], new PageFlashes($requestStack)->take('toast'));
        self::assertSame(['Comment added.'], $session->getFlashBag()->peek('toast'));
    }

    #[Test]
    public function withoutARequestOrASessionThereAreNone(): void
    {
        self::assertSame([], new PageFlashes(new RequestStack())->take('notice'));

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/me/profile'));
        self::assertSame([], new PageFlashes($requestStack)->take('notice'));
    }

    private function sessionWithFlashes(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('notice', 'Your profile is saved.');
        $session->getFlashBag()->add('toast', 'Comment added.');
        $session->getFlashBag()->add('notice', 'Log in again.');

        return $session;
    }

    private function requestStackWith(Request $request, Session $session): RequestStack
    {
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
