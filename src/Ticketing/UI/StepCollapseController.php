<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Remembers in the person's session that they collapsed or expanded a step of the tickets list (P03.T3). Anyone who may
 * open the list (OrganizationPermission::VIEW, ADR-0013) may save it, for their own session only; the step id is
 * stored, not looked up, so it reveals nothing about any organization's steps. The list's script asks with
 * X-Requested-With and takes only 204 as saved.
 */
final class StepCollapseController extends AbstractController
{
    public const string ROUTE = 'ticketing_step_collapse';
    public const string CSRF_ID = 'ticketing_step_collapse';
    public const string COLLAPSED_FIELD = 'collapsed';

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    #[Route('/tickets/steps/{stepId}/collapse', name: self::ROUTE, requirements: ['stepId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function __invoke(Request $request, string $stepId): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return new Response('The page was open too long. Try again.', Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $collapsed = $request->request->getString(self::COLLAPSED_FIELD);
        if (!\in_array($collapsed, ['0', '1'], true)) {
            return new Response('Say whether the step is collapsed.', Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        CollapsedSteps::set($request->getSession(), $stepId, '1' === $collapsed);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
