<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Http;

use App\SharedKernel\Infrastructure\Http\BackgroundRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BackgroundRequestsTest extends TestCase
{
    private const string TOKEN_ROUTE = 'notification_realtime_token';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function liveRefreshHeaderValues(): iterable
    {
        yield 'the value live-updates sends' => ['GET', '1'];
        yield 'an empty value' => ['GET', ''];
        yield 'a value with a CR LF' => ['GET', "1\r\n"];
        yield 'a HEAD' => ['HEAD', '1'];
    }

    #[Test]
    #[DataProvider('liveRefreshHeaderValues')]
    public function aLiveRefreshFetchIsBackgroundWhateverItsHeaderValue(string $method, string $headerValue): void
    {
        $request = Request::create('https://localhost/t/SHOP-1', $method);
        $request->headers->set('x-live-refresh', $headerValue);

        self::assertTrue(self::backgroundRequests()->isBackground($request));
    }

    #[Test]
    public function aFormPostCarryingTheLiveRefreshHeaderIsNotBackground(): void
    {
        $request = Request::create('https://localhost/t/SHOP-1/thread', 'POST');
        $request->headers->set('X-Live-Refresh', '1');

        self::assertFalse(self::backgroundRequests()->isBackground($request));
    }

    #[Test]
    public function aRequestToAListedRouteIsBackground(): void
    {
        $request = Request::create('https://localhost/realtime/token', 'POST');
        $request->attributes->set('_route', self::TOKEN_ROUTE);

        self::assertTrue(self::backgroundRequests()->isBackground($request));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unlistedRoutes(): iterable
    {
        yield 'another route' => ['notification_bell'];
        yield 'the listed name in another case' => ['Notification_Realtime_Token'];
        yield 'no route matched' => [null];
    }

    #[Test]
    #[DataProvider('unlistedRoutes')]
    public function aRequestToAnUnlistedRouteIsNotBackground(?string $routeName): void
    {
        $request = Request::create('https://localhost/bell');
        $request->attributes->set('_route', $routeName);

        self::assertFalse(self::backgroundRequests()->isBackground($request));
    }

    /**
     * A prefetched page is the page Turbo shows when the link is clicked, so the CSRF tokens it makes must be saved.
     *
     * @return iterable<string, array{string}>
     */
    public static function prefetchHeaders(): iterable
    {
        yield 'Turbo' => ['X-Sec-Purpose'];
        yield 'the browser' => ['Sec-Purpose'];
    }

    #[Test]
    #[DataProvider('prefetchHeaders')]
    public function aPrefetchIsNotBackground(string $headerName): void
    {
        $request = Request::create('https://localhost/tickets/new');
        $request->headers->set($headerName, 'prefetch');

        self::assertFalse(self::backgroundRequests()->isBackground($request));
    }

    #[Test]
    public function noRouteIsBackgroundWhenNoneIsListed(): void
    {
        $request = Request::create('https://localhost/realtime/token', 'POST');
        $request->attributes->set('_route', self::TOKEN_ROUTE);

        self::assertFalse(new BackgroundRequests([])->isBackground($request));
    }

    private static function backgroundRequests(): BackgroundRequests
    {
        return new BackgroundRequests([self::TOKEN_ROUTE, 'another_background_route']);
    }
}
