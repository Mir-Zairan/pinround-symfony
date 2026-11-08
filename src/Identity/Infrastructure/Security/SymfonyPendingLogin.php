<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PendingLogin;
use App\SharedKernel\Domain\UserId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * The pending login as two session attributes: the user id, and the Unix second of the right password. Holds no state
 * of its own, so it is safe in worker mode (ADR-0008). Every method throws SessionNotFoundException when there is no
 * request with a session.
 */
final readonly class SymfonyPendingLogin implements PendingLogin
{
    public const string USER_ID_KEY = 'identity.pending_login_user_id';
    public const string STARTED_AT_KEY = 'identity.pending_login_at';

    private const string FIREWALL = 'main';

    public function __construct(
        private RequestStack $requestStack,
        private SecurityUserProvider $userProvider,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function begin(UserId $userId, \DateTimeImmutable $now): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::USER_ID_KEY, $userId->toString());
        $session->set(self::STARTED_AT_KEY, $now->getTimestamp());
    }

    public function userIdAt(\DateTimeImmutable $now): ?UserId
    {
        $session = $this->requestStack->getSession();
        $userId = $session->get(self::USER_ID_KEY);
        $startedAt = $session->get(self::STARTED_AT_KEY);
        if (!\is_string($userId) || !\is_int($startedAt) || $now->getTimestamp() - $startedAt > self::PENDING_LOGIN_SECONDS) {
            return null;
        }

        return new UserId($userId);
    }

    public function forget(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::USER_ID_KEY);
        $session->remove(self::STARTED_AT_KEY);
    }

    /**
     * Through the firewall's form_login authenticator, so the token is exactly the UsernamePasswordToken of a password
     * login (SymfonyLoginAssurance compares the class) and SessionStrategyListener gives the session a new id. The
     * passport attribute tells AskForTwoStepCode that this login already had its code. Security::login() runs only the
     * user checker's pre-auth check, which refuses nobody, so loadUserById is what keeps a blocked user out.
     */
    public function finish(UserId $userId): ?string
    {
        $this->forget();
        try {
            $user = $this->userProvider->loadUserById($userId);
        } catch (UserNotFoundException) {
            return null;
        }

        $response = $this->security->login($user, 'form_login', self::FIREWALL, [], [AskForTwoStepCode::CODE_ACCEPTED_ATTRIBUTE => true]);

        return $response instanceof RedirectResponse ? $response->getTargetUrl() : $this->urlGenerator->generate('web_home');
    }
}
