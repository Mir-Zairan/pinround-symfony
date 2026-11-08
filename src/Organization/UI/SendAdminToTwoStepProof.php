<?php

declare(strict_types=1);

namespace App\Organization\UI;

use App\Identity\Api\LoginAssurance;
use App\Identity\Api\TwoStepProofPages;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\CurrentActor;
use App\Organization\UI\Voter\AdminAreaVoter;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * When AdminAreaVoter refused an Admin only for the missing code proof (ADR-0012, FR-ID-06), sends them to the setup
 * page while their two-step login is off, else to "Confirm it's you"; either one leads back to the admin page. Anyone
 * else keeps the 403. It runs before the firewall's exception listener (1), which would answer 403, and after
 * RequestTransaction rolled back, so CurrentActor gives the actor the voter read in this request. Holds no state, so
 * it is safe in worker mode (ADR-0008).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
final readonly class SendAdminToTwoStepProof
{
    public const string ADMIN_PAGES_NEED_TWO_STEP_LOGIN = 'Admin pages need two-step login. Turn it on to continue.';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private LoginAssurance $loginAssurance,
        private TwoStepProofPages $twoStepProofPages,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $refusal = $event->getThrowable();
        if (!$event->isMainRequest() || !$refusal instanceof AccessDeniedException || !\in_array(AdminPermission::AREA, $refusal->getAttributes(), true)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $actor = $this->currentActor->actorFor($token?->getUser());
        $organizationId = $refusal->getSubject();
        if (null === $actor || !$actor->roles()->isAdmin() || !$organizationId instanceof OrganizationId
            || !$actor->organizationId()->equals($organizationId) || !$this->loginAssurance->isPasswordSession($token)) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->loginAssurance->isTwoStepLoginOn($token)) {
            $event->setResponse(new RedirectResponse($this->twoStepProofPages->twoStepSetupPathFor($request, self::ADMIN_PAGES_NEED_TWO_STEP_LOGIN)));

            return;
        }
        if (!$this->loginAssurance->hasConfirmedTwoStepLoginWithin($token, AdminAreaVoter::ADMIN_STEP_UP_SECONDS)) {
            $event->setResponse(new RedirectResponse($this->twoStepProofPages->confirmItsYouPathFor($request)));
        }
    }
}
