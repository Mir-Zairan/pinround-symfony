<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\Web\Application\HomeFocusChoices;
use App\Web\Application\ShowHomeFocus;
use App\Web\UI\Home\HomeLayout;
use App\Web\UI\Navigation\MainMenu;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home: the work page for anyone who leads or joins a team (and the fallback), "Teams at a glance" for a Manager with
 * no such role, and "Your organization" for an Admin with no team role. The sections hold empty states until P07.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly ShowHomeFocus $showHomeFocus,
        private readonly TeamQueries $teamQueries,
        private readonly MainMenu $mainMenu,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/', name: 'web_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();

        return match (HomeLayout::of($actor->roles())) {
            HomeLayout::Work => $this->workHome($actor),
            HomeLayout::Manager => $this->managerHome($actor),
            HomeLayout::Admin => $this->adminHome($actor),
        };
    }

    private function workHome(ActorContext $actor): Response
    {
        $roles = $actor->roles();

        return $this->render('@Web/home/work.html.twig', [
            'today' => $this->clock->now(),
            'choices' => HomeFocusChoices::offeredTo($roles),
            'focus' => ($this->showHomeFocus)($actor),
            'isLead' => array_any($roles->teamIds(), $roles->isLeadOf(...)),
            'isManager' => $roles->isManager(),
            'isAdmin' => $roles->isAdmin(),
        ]);
    }

    private function managerHome(ActorContext $actor): Response
    {
        $organizationId = $actor->organizationId();

        return $this->render('@Web/home/manager.html.twig', [
            'today' => $this->clock->now(),
            'teams' => $this->teamQueries->summariesOf($organizationId, $this->teamQueries->teamsOf($organizationId)),
        ]);
    }

    private function adminHome(ActorContext $actor): Response
    {
        return $this->render('@Web/home/admin.html.twig', [
            'teamCount' => \count($this->teamQueries->teamsOf($actor->organizationId())),
            'adminSettingsUrl' => $this->adminSettingsUrl(),
        ]);
    }

    /**
     * The menu's own link to the admin home, so the button and the menu item become links together; null while no
     * phase has built the page.
     */
    private function adminSettingsUrl(): ?string
    {
        foreach ($this->mainMenu->items() as $item) {
            if (MainMenu::ADMIN_ROUTE === $item->routeName()) {
                return $item->url();
            }
        }

        return null;
    }
}
