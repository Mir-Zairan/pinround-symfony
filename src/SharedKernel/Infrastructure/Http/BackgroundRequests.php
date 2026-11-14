<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tells the requests a page sends without the person asking for them, which must never write the session
 * (BackgroundReadOnlySessionHandler): they can run beside the person's own POST and write back an older copy of it.
 *
 * A Turbo prefetch and a lazy frame are not background: the page they fetch is the one the person then sees, and the
 * CSRF tokens it makes must be saved for its forms to post.
 */
final readonly class BackgroundRequests
{
    /** Sent by live-updates_controller.js on every GET of a live refresh. */
    public const string LIVE_REFRESH_HEADER = 'X-Live-Refresh';

    /**
     * @param list<string> $backgroundRouteNames routes only the page's own scripts call, matched case-sensitively
     */
    public function __construct(private array $backgroundRouteNames)
    {
    }

    /**
     * A GET or HEAD with the live refresh header, whatever its value, or a request to a listed route.
     */
    public function isBackground(Request $request): bool
    {
        if ($request->isMethodSafe() && $request->headers->has(self::LIVE_REFRESH_HEADER)) {
            return true;
        }

        return \in_array($request->attributes->get('_route'), $this->backgroundRouteNames, true);
    }
}
