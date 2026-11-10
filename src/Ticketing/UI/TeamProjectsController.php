<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\ProjectPermission;
use App\Ticketing\Application\CreateProject\CreateProject;
use App\Ticketing\Application\CreateProject\ProjectKeyNotValid;
use App\Ticketing\Application\CreateProject\ProjectKeyTaken;
use App\Ticketing\Application\CreateProject\ProjectNameMissing;
use App\Ticketing\Application\LinkProject\LinkProject;
use App\Ticketing\Application\LinkProject\ProjectStillLinked;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\ProjectTeamNotFound;
use App\Ticketing\Application\TicketingTransactions;
use App\Ticketing\Application\UnlinkProject\ProjectHasOpenTickets;
use App\Ticketing\Application\UnlinkProject\ProjectNotOfTeam;
use App\Ticketing\Application\UnlinkProject\UnlinkProject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The project actions of the team page (FR-ORG-06, P02.T8): unlink a project from the team, link projects that have no
 * team to it, and create a project it owns. Each answers with a redirect to the team page and a toast.
 *
 * Without the request transaction: a refused command, such as CreateProject when another request took the key first,
 * has written inside the transaction, and only rolling back the whole of it undoes that. TicketingTransactions gives
 * each action a transaction of its own, which also covers the team lookup and the access checks. In that order: a slug
 * of no team of the organization is not found, a person the voter refuses is denied, and a form token that is not
 * valid changes nothing.
 */
final class TeamProjectsController extends AbstractController
{
    public const string UNLINK_ROUTE = 'ticketing_team_projects_unlink';
    public const string LINK_ROUTE = 'ticketing_team_projects_link';
    public const string CREATE_ROUTE = 'ticketing_team_projects_create';

    /** The CSRF token ids, one for each action, for csrf_token() in the template. */
    public const string UNLINK_CSRF_ID = 'ticketing_team_projects_unlink';
    public const string LINK_CSRF_ID = 'ticketing_team_projects_link';
    public const string CREATE_CSRF_ID = 'ticketing_team_projects_create';

    /** The form fields the actions read. PROJECT_IDS_FIELD is a list: name the inputs project_ids[]. */
    public const string CSRF_FIELD = '_csrf_token';
    public const string PROJECT_ID_FIELD = 'project_id';
    public const string PROJECT_IDS_FIELD = 'project_ids';
    public const string KEY_FIELD = 'key';
    public const string NAME_FIELD = 'name';

    /**
     * Copies of TeamController::ROUTE and TeamController::SLUG_REQUIREMENT, which Ticketing_UI may not import;
     * TeamProjectsControllerTest keeps them the same.
     */
    public const string TEAM_PAGE_ROUTE = 'team_show';
    public const string SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** With the project's key. */
    public const string UNLINKED = '%s removed from the team';
    /** With the keys of the projects, joined by ", ", or the key of the new project. */
    public const string ADDED = 'Added %s';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string NO_PROJECT_CHOSEN = 'Choose a project to add.';
    public const string ALREADY_LINKED = 'One of those projects is already in a team, so none was added.';
    public const string KEY_NOT_VALID = 'A project key is 2 to 10 capital letters or digits and starts with a letter.';
    public const string NAME_MISSING = 'Give the project a name.';
    /** With the key after trimming and capitals, which the key format has accepted by then. */
    public const string KEY_TAKEN = 'Another project already uses the key %s.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string NOT_FOUND = 'No project of this organization has that id.';

    public function __construct(
        private readonly TicketingTransactions $ticketingTransactions,
        private readonly TeamQueries $teamQueries,
        private readonly UnlinkProject $unlinkProject,
        private readonly LinkProject $linkProject,
        private readonly CreateProject $createProject,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/teams/{slug}/projects/unlink', name: self::UNLINK_ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function unlink(string $slug, Request $request): Response
    {
        $organizationId = $this->requestTenant();
        $projectId = strtolower($request->request->getString(self::PROJECT_ID_FIELD));
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $toast = $this->ticketingTransactions->run(function () use ($organizationId, $slug, $projectId, $csrfToken): string {
                $team = $this->teamOf($organizationId, $slug);
                $this->denyAccessUnlessGranted(ProjectPermission::UNLINK, $team->id());
                if (!$this->isCsrfTokenValid(self::UNLINK_CSRF_ID, $csrfToken)) {
                    return self::FORM_EXPIRED;
                }

                return \sprintf(self::UNLINKED, ($this->unlinkProject)($organizationId, $team->id(), $projectId));
            });
        } catch (ProjectNotFound|ProjectNotOfTeam) {
            throw $this->createNotFoundException('No project of this team has that id.');
        } catch (ProjectHasOpenTickets $openTickets) {
            $toast = $openTickets->getMessage();
        }

        return $this->redirectWithToast($slug, $toast);
    }

    /**
     * All or nothing: when one of the projects is refused, the transaction is rolled back and none is linked.
     */
    #[Route('/teams/{slug}/projects/link', name: self::LINK_ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function link(string $slug, Request $request): Response
    {
        $organizationId = $this->requestTenant();
        $projectIds = $this->projectIdsOf($request);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $toast = $this->ticketingTransactions->run(function () use ($organizationId, $slug, $projectIds, $csrfToken): string {
                $team = $this->teamOf($organizationId, $slug);
                $this->denyAccessUnlessGranted(ProjectPermission::LINK, $team->id());
                if (!$this->isCsrfTokenValid(self::LINK_CSRF_ID, $csrfToken)) {
                    return self::FORM_EXPIRED;
                }
                if ([] === $projectIds) {
                    return self::NO_PROJECT_CHOSEN;
                }
                $keysById = $this->linkProject->linkAll($organizationId, $team->id(), $projectIds);

                return \sprintf(self::ADDED, implode(', ', array_map(static fn (string $projectId): string => $keysById[$projectId], $projectIds)));
            });
        } catch (ProjectNotFound|ProjectTeamNotFound) {
            throw $this->createNotFoundException(self::NOT_FOUND);
        } catch (ProjectStillLinked) {
            $toast = self::ALREADY_LINKED;
        }

        return $this->redirectWithToast($slug, $toast);
    }

    #[Route('/teams/{slug}/projects', name: self::CREATE_ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function create(string $slug, Request $request): Response
    {
        $organizationId = $this->requestTenant();
        $key = strtoupper(trim($request->request->getString(self::KEY_FIELD)));
        $name = $request->request->getString(self::NAME_FIELD);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $toast = $this->ticketingTransactions->run(function () use ($organizationId, $slug, $key, $name, $csrfToken): string {
                $team = $this->teamOf($organizationId, $slug);
                $this->denyAccessUnlessGranted(ProjectPermission::CREATE, $team->id());
                if (!$this->isCsrfTokenValid(self::CREATE_CSRF_ID, $csrfToken)) {
                    return self::FORM_EXPIRED;
                }
                ($this->createProject)($organizationId, $team->id(), $key, $name);

                return \sprintf(self::ADDED, $key);
            });
        } catch (ProjectTeamNotFound) {
            throw $this->createNotFoundException('No team of this organization has that slug.');
        } catch (ProjectKeyNotValid) {
            $toast = self::KEY_NOT_VALID;
        } catch (ProjectNameMissing) {
            $toast = self::NAME_MISSING;
        } catch (ProjectKeyTaken) {
            // Rolled back as a whole, also when another request saved the key after CreateProject checked it.
            $toast = \sprintf(self::KEY_TAKEN, $key);
        }

        return $this->redirectWithToast($slug, $toast);
    }

    /**
     * Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
     */
    private function requestTenant(): OrganizationId
    {
        return $this->tenantContext->get() ?? throw $this->createAccessDeniedException();
    }

    /**
     * Reads under row-level security, so it needs the transaction run opens.
     */
    private function teamOf(OrganizationId $organizationId, string $slug): TeamSummary
    {
        return $this->teamQueries->bySlug($organizationId, $slug) ?? throw $this->createNotFoundException('No team of this organization has that slug.');
    }

    /**
     * @return list<string> the ids in the order sent, in lowercase as ProjectSummary holds them, each once
     *
     * @throws BadRequestHttpException when the field is not a list of strings
     */
    private function projectIdsOf(Request $request): array
    {
        if (!$request->request->has(self::PROJECT_IDS_FIELD)) {
            return [];
        }
        $sent = $request->request->all(self::PROJECT_IDS_FIELD);
        $projectIds = [];
        foreach ($sent as $projectId) {
            if (!\is_string($projectId)) {
                throw new BadRequestHttpException('The projects to add are not valid.');
            }
            $projectIds[] = strtolower($projectId);
        }

        return array_values(array_unique($projectIds));
    }

    private function redirectWithToast(string $slug, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);

        return $this->redirectToRoute(self::TEAM_PAGE_ROUTE, ['slug' => $slug]);
    }
}
