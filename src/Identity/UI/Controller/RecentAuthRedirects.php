<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Api\TwoStepProofPages;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Where a person goes when an action needs a recent proof (RequiresRecentAuth), and the way back. A return path comes
 * from the request, so it is used only when it is a path of this app: never a URL that leaves the site. Other modules
 * reach the same pages through TwoStepProofPages. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final readonly class RecentAuthRedirects implements TwoStepProofPages
{
    public const string AFTER_TWO_STEP_SETUP_KEY = 'identity.after_two_step_setup';
    public const string TURN_ON_TWO_STEP_LOGIN_FIRST = 'To do this, turn on two-step login first.';

    private const int LONGEST_RETURN_PATH = 2000;

    public function __construct(private RouterInterface $router)
    {
    }

    /**
     * @return string|null the path with its query when it is safe to redirect to; null otherwise
     */
    public function safeReturnPath(?string $candidate): ?string
    {
        // Browsers read "\" as "/", so "/\host" leaves the site like "//host"; spaces and control characters have no
        // place in a path this app makes.
        if (null === $candidate || \strlen($candidate) > self::LONGEST_RETURN_PATH || !str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//') || str_contains($candidate, '\\') || 1 === preg_match('/[\s\x00-\x1f\x7f]/', $candidate)) {
            return null;
        }
        $parts = parse_url($candidate);
        if (false === $parts || isset($parts['scheme']) || isset($parts['host']) || !isset($parts['path'])) {
            return null;
        }

        try {
            $this->router->match($parts['path']);
        } catch (MethodNotAllowedException) {
            // A route of this app, for other methods than the request's: on a POST, that is any page.
        } catch (ResourceNotFoundException) {
            return null;
        }

        return $candidate;
    }

    /**
     * For a GET, the page asked for. For another method, the page the form was on, when the Referer names a page of
     * this site; else Home.
     */
    public function returnPathOf(Request $request): string
    {
        if ($request->isMethod('GET')) {
            return $this->safeReturnPath($request->getRequestUri()) ?? $this->homePath();
        }

        $referer = $request->headers->get('referer') ?? '';
        $site = $request->getSchemeAndHttpHost();
        if (!str_starts_with($referer, $site.'/')) {
            return $this->homePath();
        }

        return $this->safeReturnPath(substr($referer, \strlen($site))) ?? $this->homePath();
    }

    /**
     * To "Confirm it's you", which sends the person back to the return path once the password and code are right.
     */
    public function toConfirm(string $returnPath): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('identity_confirm', ['return' => $returnPath]));
    }

    /**
     * To the setup page with a message, keeping the return path for the "Continue" link after setup.
     */
    public function toTwoStepSetup(Request $request, string $returnPath, string $notice = self::TURN_ON_TWO_STEP_LOGIN_FIRST): RedirectResponse
    {
        $session = $request->getSession();
        $session->set(self::AFTER_TWO_STEP_SETUP_KEY, $returnPath);
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('notice', $notice);
        }

        return new RedirectResponse($this->router->generate('identity_two_step'));
    }

    public function confirmItsYouPathFor(mixed $request): string
    {
        return $this->toConfirm($this->returnPathOf(self::requestOf($request)))->getTargetUrl();
    }

    public function sendToTwoStepSetup(mixed $request, string $notice): string
    {
        $request = self::requestOf($request);

        return $this->toTwoStepSetup($request, $this->returnPathOf($request), $notice)->getTargetUrl();
    }

    /**
     * Removes the path toTwoStepSetup() kept, whether or not it is still safe.
     *
     * @return string|null null when none was kept or it is not safe
     */
    public function takeAfterTwoStepSetup(Request $request): ?string
    {
        $kept = $request->getSession()->remove(self::AFTER_TWO_STEP_SETUP_KEY);

        return $this->safeReturnPath(\is_string($kept) ? $kept : null);
    }

    public function homePath(): string
    {
        return $this->router->generate('web_home');
    }

    private static function requestOf(mixed $request): Request
    {
        return $request instanceof Request ? $request : throw new \InvalidArgumentException('A TwoStepProofPages path needs the HttpFoundation Request.');
    }
}
