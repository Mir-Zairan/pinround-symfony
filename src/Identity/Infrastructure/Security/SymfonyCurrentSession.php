<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\CurrentSession;
use App\SharedKernel\Domain\UserId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The session through Symfony Security. Holds no state of its own, so it is safe in worker mode (ADR-0008).
 */
final readonly class SymfonyCurrentSession implements CurrentSession
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private SecurityUserProvider $userProvider,
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    public function userId(): UserId
    {
        return new UserId($this->loggedInUser()->id());
    }

    /**
     * Replaces the token rather than logging in again through Security::login(), which would take a login try and
     * record a login attempt. The ContextListener saves the new token into the session on the response, and the new
     * id makes a copy of the old session cookie worthless. The new id also restarts the 30-day limit, as a login does.
     */
    public function keepAfterPasswordChange(): void
    {
        $refreshedUser = $this->userProvider->refreshUser($this->loggedInUser());
        $request = $this->requestStack->getMainRequest() ?? throw new \LogicException('A session needs a request.');
        $firewallName = $this->security->getFirewallConfig($request)?->getName() ?? throw new \LogicException('The request is not behind a firewall.');

        $this->tokenStorage->setToken(new UsernamePasswordToken($refreshedUser, $firewallName, $refreshedUser->getRoles()));
        $request->getSession()->migrate(true);
    }

    /**
     * Through the firewall's logout listeners, so the session is invalidated and the CSRF tokens cleared exactly as the
     * header's "Log out" does. The caller has checked its own CSRF token.
     */
    public function end(): void
    {
        $this->loggedInUser();
        $this->security->logout(false);
    }

    private function loggedInUser(): SecurityUser
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof SecurityUser) {
            throw new \LogicException('Nobody is logged in.');
        }

        return $user;
    }
}
