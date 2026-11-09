<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\CurrentSession;
use App\Identity\Application\RecentAuth;
use App\Identity\Application\ShowTwoStepSetup;
use App\SharedKernel\UI\RequiresRecentAuth;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Enforces RequiresRecentAuth on a controller class or action (ADR-0012): without a recent password and code proof
 * (RecentAuth), the action does not run and the person goes to "Confirm it's you", or, while their two-step login is
 * off, to its setup page. It acts on sub-requests too, so no path reaches a marked action without the proof. A visitor
 * who is not logged in is left to the firewall and the action's own access check. Holds no state, so it is safe in
 * worker mode (ADR-0008).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class AskToConfirmItsYou
{
    public function __construct(
        private Security $security,
        private CurrentSession $currentSession,
        private RecentAuth $recentAuth,
        private ShowTwoStepSetup $showTwoStepSetup,
        private RecentAuthRedirects $recentAuthRedirects,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if ([] === $event->getAttributes(RequiresRecentAuth::class) || !$this->security->getUser() instanceof UserInterface) {
            return;
        }

        $userId = $this->currentSession->userId();
        if ($this->recentAuth->isRecentFor($userId, $this->clock->now())) {
            return;
        }

        $request = $event->getRequest();
        $returnPath = $this->recentAuthRedirects->returnPathOf($request);
        $redirect = $this->showTwoStepSetup->stateOf($userId)->isOn()
            ? $this->recentAuthRedirects->toConfirm($returnPath)
            : $this->recentAuthRedirects->toTwoStepSetup($request, $returnPath);
        $event->setController(static fn (): RedirectResponse => $redirect);
    }
}
