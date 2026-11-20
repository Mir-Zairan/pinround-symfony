<?php

declare(strict_types=1);

namespace App\Organization\UI;

use App\Identity\Api\LoginAssurance;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Answers 404 instead of 403 when AdminAreaVoter refused a logged-in person who is no Admin of the organization the
 * request works in (P11.T1, P14.T3), so the admin pages do not tell them they exist. An Admin refused for the missing
 * code proof is SendAdminToTwoStepProof's; an Admin refused otherwise, such as by remember-me, keeps the 403, and a
 * visitor without an organization goes on to the firewall, which sends them to the login page. A personal access token
 * keeps the 403 of AdminNeedsBrowserSessionVoter, which says why. Runs before the firewall's exception listener (1),
 * after RequestTransaction rolled back, so CurrentActor gives the roles AdminAreaVoter read in this request; the token
 * case is left out also because there that voter never runs, and roles read after the rollback would be none. Holds no
 * state, so it is safe in worker mode (ADR-0008). It lives beside AdminAreaVoter rather than in Web's admin shell: it
 * answers for every refusal of ADMIN_AREA, whichever module's tab asked, and it needs Identity's LoginAssurance, which
 * Web may not read.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
final readonly class HideAdminAreaFromNonAdmins
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private LoginAssurance $loginAssurance,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $refusal = $event->getThrowable();
        if (!$event->isMainRequest() || !$refusal instanceof AccessDeniedException || !\in_array(AdminPermission::AREA, $refusal->getAttributes(), true)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($this->loginAssurance->isTokenAuthenticated($token)) {
            return;
        }
        $actor = $this->currentActor->actorFor($token?->getUser());
        $organizationId = $refusal->getSubject();
        if (null === $actor || !$organizationId instanceof OrganizationId) {
            return;
        }
        if ($actor->roles()->isAdmin() && $actor->organizationId()->equals($organizationId)) {
            return;
        }

        // Without the refusal as previous: the firewall's listener walks the chain and would answer 403 for it.
        $event->setThrowable(new NotFoundHttpException());
    }
}
