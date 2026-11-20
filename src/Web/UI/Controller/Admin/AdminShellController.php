<?php

declare(strict_types=1);

namespace App\Web\UI\Controller\Admin;

use App\Organization\Api\AdminPermission;
use App\SharedKernel\Application\TenantContext;
use App\Web\UI\Admin\AdminTabRail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin area's address, which the menu's Admin item and Home's admin buttons link: it opens the first tab of
 * AdminTabRail the person may open. ADMIN_AREA guards it as it guards every tab, so Organization's
 * SendAdminToTwoStepProof sends an Admin without a fresh code to the proof first, and its HideAdminAreaFromNonAdmins
 * answers 404 to everyone else.
 */
final class AdminShellController extends AbstractController
{
    /** The name MainMenu::ADMIN_ROUTE links. */
    public const string ROUTE = 'web_admin';

    public function __construct(private readonly TenantContext $tenantContext, private readonly AdminTabRail $adminTabRail)
    {
    }

    #[Route('/admin', name: self::ROUTE, methods: ['GET'])]
    public function openFirstTab(): Response
    {
        $this->denyAccessUnlessGranted(AdminPermission::AREA, $this->tenantContext->get());
        $firstTab = $this->adminTabRail->firstTab() ?? throw $this->createNotFoundException('No admin tab is open to this person.');

        return $this->redirect($firstTab->url);
    }
}
