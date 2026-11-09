<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PendingLogin;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Domain\UserId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * The pending login as three session attributes: the user id, the user's session stamp, and the Unix second of the
 * right password. The stamp is what ends a user's sessions (SecurityUser::isEqualTo): ChangePassword, ResetPassword
 * and LogOutEverywhere rotate it, so they end a pending login too. Holds no state of its own, so it is safe in worker
 * mode (ADR-0008). Every method throws SessionNotFoundException when there is no request with a session.
 */
final readonly class SymfonyPendingLogin implements PendingLogin
{
    public const string USER_ID_KEY = 'identity.pending_login_user_id';
    public const string SESSION_STAMP_KEY = 'identity.pending_login_session_stamp';
    public const string STARTED_AT_KEY = 'identity.pending_login_at';

    private const string FIREWALL = 'main';

    public function __construct(
        private RequestStack $requestStack,
        private SecurityUserProvider $userProvider,
        private TwoStepLogins $twoStepLogins,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function begin(UserId $userId, #[\SensitiveParameter] string $sessionStamp, \DateTimeImmutable $now): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::USER_ID_KEY, $userId->toString());
        $session->set(self::SESSION_STAMP_KEY, $sessionStamp);
        $session->set(self::STARTED_AT_KEY, $now->getTimestamp());
    }

    /**
     * Reads the user and their two-step login on every call: one indexed read each.
     */
    public function userIdAt(\DateTimeImmutable $now): ?UserId
    {
        $startedAt = $this->requestStack->getSession()->get(self::STARTED_AT_KEY);
        if (!\is_int($startedAt) || $now->getTimestamp() - $startedAt > self::PENDING_LOGIN_SECONDS) {
            return null;
        }

        $user = $this->pendingUser();
        if (null === $user) {
            return null;
        }
        $userId = new UserId($user->id());

        return true === $this->twoStepLogins->byUserId($userId)?->isOn() ? $userId : null;
    }

    public function forget(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::USER_ID_KEY);
        $session->remove(self::SESSION_STAMP_KEY);
        $session->remove(self::STARTED_AT_KEY);
    }

    /**
     * Through the firewall's form_login authenticator, so the token is exactly the UsernamePasswordToken of a password
     * login (SymfonyLoginAssurance compares the class) and SessionStrategyListener gives the session a new id. The
     * passport attribute tells AskForTwoStepCode that this login already had its code. Security::login() runs only the
     * user checker's pre-auth check, which refuses nobody, so pendingUser() is what keeps out a blocked user and one
     * whose sessions were ended while the code was typed.
     */
    public function finish(UserId $userId): ?string
    {
        $user = $this->pendingUser();
        $this->forget();
        if (null === $user || $user->id() !== $userId->toString()) {
            return null;
        }

        $response = $this->security->login($user, 'form_login', self::FIREWALL, [], [AskForTwoStepCode::CODE_ACCEPTED_ATTRIBUTE => true]);

        return $response instanceof RedirectResponse ? $response->getTargetUrl() : $this->urlGenerator->generate('web_home');
    }

    /**
     * @return SecurityUser|null the user as saved now; null without a pending login, when they may no longer log in, or
     *                           when their session stamp changed since the password
     */
    private function pendingUser(): ?SecurityUser
    {
        $session = $this->requestStack->getSession();
        $userId = $session->get(self::USER_ID_KEY);
        $sessionStamp = $session->get(self::SESSION_STAMP_KEY);
        if (!\is_string($userId) || !\is_string($sessionStamp)) {
            return null;
        }

        try {
            $user = $this->userProvider->loadUserById(new UserId($userId));
        } catch (UserNotFoundException) {
            return null;
        }

        return hash_equals($user->sessionStamp(), $sessionStamp) ? $user : null;
    }
}
