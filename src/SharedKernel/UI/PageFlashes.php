<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The session flashes a page shows, in place of Twig's `app.flashes`. An action flashes its toast or notice before
 * a 303, and a fetch the person did not ask for can reach the server before the redirected page: a live update's
 * fetch (live-updates_controller.js sends X-Live-Refresh). Such a fetch gets none and leaves them in the session for
 * the redirected page. Holds no state, so it is safe in worker mode.
 */
final readonly class PageFlashes
{
    private const string LIVE_REFRESH_HEADER = 'X-Live-Refresh';

    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * Takes the type's flashes out of the session, as `app.flashes(type)` does.
     *
     * @return list<mixed> in the order they were flashed; none outside a request, without a session, or for a live
     *                     refresh, which leaves them in the session
     */
    #[AsTwigFunction('page_flashes')]
    public function take(string $type): array
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession() || $request->headers->has(self::LIVE_REFRESH_HEADER)) {
            return [];
        }
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return [];
        }

        return array_values($session->getFlashBag()->get($type));
    }
}
