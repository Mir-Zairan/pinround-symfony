<?php

declare(strict_types=1);

namespace App\Organization\UI\Controller;

use App\Organization\Api\TeamPermission;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The team page, /teams/{slug} (P02.T8). The slug is looked up in the request's organization only, so a slug of
 * another organization is not found; a team found there still needs TeamPermission::VIEW (ADR-0013).
 */
final class TeamController extends AbstractController
{
    public const string ROUTE = 'team_show';

    /**
     * SlugFormat::GROUPS, which this layer may not import; TeamControllerTest keeps the two the same. A slug longer
     * than a slug may be passes here and is not found.
     */
    public const string SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(private readonly TeamQueries $teamQueries, private readonly TenantContext $tenantContext)
    {
    }

    #[Route('/teams/{slug}', name: self::ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $organizationId = $this->tenantContext->get() ?? throw new \LogicException('EnterChosenOrganization gives every logged-in request a tenant.');
        $team = $this->teamQueries->bySlug($organizationId, $slug) ?? throw $this->createNotFoundException('No team of this organization has that slug.');
        $this->denyAccessUnlessGranted(TeamPermission::VIEW, $team->id());

        return $this->render('@Organization/team/show.html.twig', ['team' => $team]);
    }
}
