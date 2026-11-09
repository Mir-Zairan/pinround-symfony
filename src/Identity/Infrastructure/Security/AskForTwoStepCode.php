<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PendingLogin;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Login step one (ADR-0012). For a user whose two-step login is on, the right password logs nobody in: the token is
 * cleared, so the session never stores it, the pending login starts and the response becomes a redirect to the code
 * page. For a user without two-step login the login stands, and its time is recorded as the session's recent login.
 * Either way the times and the pending login of an earlier login in the same session are removed first.
 *
 * It runs on every login of the main firewall, not only on the password form's route, so a login method added later
 * asks for the code too unless it says it had one. Only the passport attribute that SymfonyPendingLogin::finish sets
 * says so: a passport attribute comes from server code for that one login, never from the request.
 */
#[AsEventListener(priority: self::PRIORITY)]
final readonly class AskForTwoStepCode
{
    use TargetPathTrait;

    public const string CODE_ACCEPTED_ATTRIBUTE = 'identity.two_step_code_accepted';

    private const string FIREWALL = 'main';
    // After SessionStrategyListener, LoginThrottlingListener, PasswordMigratingListener and LoginAttemptRecorder (all 0),
    // which still treat step one as the login it is for them: the session gets a new id, the right password gives its
    // throttle tries back, and the try is recorded as a success. The remember-me listeners are not registered: the main
    // firewall has no remember_me.
    private const int PRIORITY = -64;

    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private PendingLogin $pendingLogin,
        private SessionConfirmations $sessionConfirmations,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if (self::FIREWALL !== $event->getFirewallName() || true === $event->getPassport()->getAttribute(self::CODE_ACCEPTED_ATTRIBUTE)) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof SecurityUser) {
            throw new \LogicException(\sprintf('The main firewall logs in only a %s.', SecurityUser::class));
        }
        $userId = new UserId($user->id());
        $now = $this->clock->now();
        // The session keeps its attributes through the new id, also when another person logs in with it.
        $this->sessionConfirmations->forget();
        $this->pendingLogin->forget();
        if (true !== $this->twoStepLogins->byUserId($userId)?->isOn()) {
            $this->sessionConfirmations->recordRecentAuth($userId, $now);

            return;
        }

        $this->tokenStorage->setToken(null);
        $this->pendingLogin->begin($userId, $user->sessionStamp(), $now);
        // The success handler took the saved target path out of the session to build its redirect; put it back, so
        // the login with the code still lands on the page the person asked for.
        $response = $event->getResponse();
        if ($response instanceof RedirectResponse) {
            $this->saveTargetPath($event->getRequest()->getSession(), self::FIREWALL, $response->getTargetUrl());
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('identity_login_code')));
    }
}
