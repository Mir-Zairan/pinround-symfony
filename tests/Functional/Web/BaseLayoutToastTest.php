<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The toast an action flashes before its redirect, in base.html.twig: a live update's fetch of a page
 * (live-updates_controller.js) can come between the redirect and the redirected page, and must leave it waiting.
 */
final class BaseLayoutToastTest extends KernelTestCase
{
    private const string TOAST = 'Comment added.';

    #[Test]
    public function aLiveRefreshShowsNoToastAndLeavesItForTheNextPage(): void
    {
        $session = $this->sessionWithAToast();

        $refreshedPage = $this->renderBaseFor(Request::create('/t/SHOP-1', server: ['HTTP_X_LIVE_REFRESH' => '1']), $session);

        self::assertCount(1, $refreshedPage->filter('#toast-region'));
        self::assertCount(0, $refreshedPage->filter('#toast-region [data-toast-message-value]'));
        self::assertSame([self::TOAST], $session->getFlashBag()->peek('toast'));

        $redirectedPage = $this->renderBaseFor(Request::create('/t/SHOP-1/thread'), $session);

        self::assertSame([self::TOAST], $redirectedPage->filter('#toast-region [data-toast-message-value]')->each(static fn (Crawler $toast): string => (string) $toast->attr('data-toast-message-value')));
        self::assertSame([], $session->getFlashBag()->peek('toast'));
    }

    #[Test]
    public function aPageOtherThanALiveRefreshShowsTheToastOnce(): void
    {
        $session = $this->sessionWithAToast();

        $firstPage = $this->renderBaseFor(Request::create('/t/SHOP-1/thread'), $session);
        $nextPage = $this->renderBaseFor(Request::create('/t/SHOP-1/thread'), $session);

        self::assertCount(1, $firstPage->filter('#toast-region [data-toast-message-value]'));
        self::assertCount(0, $nextPage->filter('#toast-region [data-toast-message-value]'));
    }

    private function sessionWithAToast(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('toast', self::TOAST);

        return $session;
    }

    private function renderBaseFor(Request $request, Session $session): Crawler
    {
        $request->setSession($session);
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        try {
            return new Crawler(self::getContainer()->get('twig')->render('@Web/base.html.twig'));
        } finally {
            $requestStack->pop();
        }
    }
}
