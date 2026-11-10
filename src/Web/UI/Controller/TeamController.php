<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Organization\Api\TeamPermission;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\ProjectPermission;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ProjectSummary;
use App\Web\UI\Team\ProjectTicketsLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The team page, /teams/{slug} (P02.T8). The slug is looked up in the request's organization only, so a slug of
 * another organization is not found; a team found there still needs TeamPermission::VIEW (ADR-0013). It only reads:
 * the changes it will offer are POST routes of Ticketing and Workflow that its templates post to.
 */
final class TeamController extends AbstractController
{
    public const string ROUTE = 'team_show';

    /**
     * SlugFormat::GROUPS, which Web_UI may not import; TeamControllerTest keeps the two the same. A slug longer
     * than a slug may be passes here and is not found.
     */
    public const string SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * The routes, CSRF token ids and field names of Ticketing's TeamProjectsController, which Web_UI may not import;
     * TeamControllerTest keeps them the same.
     */
    public const array PROJECT_FORMS = [
        'unlinkRoute' => 'ticketing_team_projects_unlink',
        'linkRoute' => 'ticketing_team_projects_link',
        'createRoute' => 'ticketing_team_projects_create',
        'unlinkCsrfId' => 'ticketing_team_projects_unlink',
        'linkCsrfId' => 'ticketing_team_projects_link',
        'createCsrfId' => 'ticketing_team_projects_create',
        'csrfField' => '_csrf_token',
        'projectIdField' => 'project_id',
        'projectIdsField' => 'project_ids',
        'keyField' => 'key',
        'nameField' => 'name',
    ];

    public function __construct(
        private readonly TeamQueries $teamQueries,
        private readonly ProjectQueries $projectQueries,
        private readonly ProjectTicketsLink $projectTicketsLink,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/teams/{slug}', name: self::ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $organizationId = $this->tenantContext->get() ?? throw new \LogicException('EnterChosenOrganization gives every logged-in request a tenant.');
        $team = $this->teamQueries->bySlug($organizationId, $slug) ?? throw $this->createNotFoundException('No team of this organization has that slug.');
        $this->denyAccessUnlessGranted(TeamPermission::VIEW, $team->id());

        $projects = $this->projectQueries->projectsOf($team->id());
        $canLink = $this->isGranted(ProjectPermission::LINK, $team->id());

        return $this->render('@Web/team/show.html.twig', [
            'team' => $team,
            'isLead' => $this->isGranted(TeamPermission::LEAD, $team->id()),
            'canUnlink' => $this->isGranted(ProjectPermission::UNLINK, $team->id()),
            'canLink' => $canLink,
            'canCreate' => $this->isGranted(ProjectPermission::CREATE, $team->id()),
            'unlinkedProjects' => $canLink ? $this->projectQueries->unlinkedProjects() : [],
            'projectForms' => self::PROJECT_FORMS,
            'projects' => $projects,
            'ticketsUrls' => array_combine(
                array_map(static fn (ProjectSummary $project): string => $project->id(), $projects),
                array_map($this->projectTicketsLink->urlOf(...), $projects),
            ),
        ]);
    }
}
