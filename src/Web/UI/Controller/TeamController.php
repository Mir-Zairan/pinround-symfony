<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\TeamPermission;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ProjectPermission;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ProjectSummary;
use App\Web\UI\Team\ProjectTicketsLink;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Api\WorkflowPermission;
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

    /**
     * The routes, CSRF token ids, field names and labels of Workflow's TeamWorkflowController, which Web_UI may not
     * import; TeamControllerTest keeps them the same. groupLabels are the groups a team step may be added to.
     */
    public const array WORKFLOW_FORMS = [
        'addStepRoute' => 'workflow_team_steps_add',
        'removeStepRoute' => 'workflow_team_steps_remove',
        'setRuleRoute' => 'workflow_team_rules_set',
        'addStepCsrfId' => 'workflow_team_steps_add',
        'removeStepCsrfId' => 'workflow_team_steps_remove',
        'setRuleCsrfId' => 'workflow_team_rules_set',
        'csrfField' => '_csrf_token',
        'nameField' => 'name',
        'groupField' => 'group',
        'stepIdField' => 'step_id',
        'ruleField' => 'rule',
        'isOnField' => 'is_on',
        'isOn' => '1',
        'isOff' => '0',
        'groupLabels' => ['todo' => 'To do', 'doing' => 'Doing', 'check' => 'Checking', 'wait' => 'Waiting'],
        'ruleLabels' => [
            'tester_moves_done' => 'Only the tester can move a ticket to Done',
            'no_self_review' => 'Nobody approves their own code review',
            'review_before_test' => 'Code review must happen before testing',
        ],
    ];

    /** The five groups in the order the page shows them, by group code. */
    private const array GROUP_ORDER = ['todo' => 'To do', 'doing' => 'Doing', 'check' => 'Checking', 'wait' => 'Waiting', 'done' => 'Done'];

    public function __construct(
        private readonly TeamQueries $teamQueries,
        private readonly ProjectQueries $projectQueries,
        private readonly ProjectTicketsLink $projectTicketsLink,
        private readonly StepQueries $stepQueries,
        private readonly RuleQueries $ruleQueries,
        private readonly SettingsQueries $settingsQueries,
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

        $rules = $this->ruleQueries->rulesFor($team->id());
        $ruleStates = [
            'tester_moves_done' => $rules->testerMovesDone,
            'no_self_review' => $rules->noSelfReview,
            'review_before_test' => $rules->reviewBeforeTest,
        ];

        return $this->render('@Web/team/show.html.twig', [
            'stepPolicy' => $this->settingsQueries->stepPolicyOf($organizationId)->value,
            'stepGroups' => $this->stepGroups($team->id()),
            'canChangeSteps' => $this->isGranted(WorkflowPermission::STEP_CHANGE_TEAM, $team->id()),
            'canChangeRules' => $this->isGranted(WorkflowPermission::RULES_CHANGE_TEAM, $team->id()),
            'ruleStates' => $ruleStates,
            'workflowForms' => self::WORKFLOW_FORMS,
            'team' => $team,
            'isLead' => $this->isGranted(TeamPermission::LEAD, $team->id()),
            'canUnlink' => $this->isGranted(ProjectPermission::UNLINK, $team->id()),
            'canLink' => $canLink,
            'canCreate' => $this->isGranted(ProjectPermission::CREATE, $team->id()),
            'unlinkedProjects' => $canLink ? $this->projectQueries->unlinkedProjectChoices(ProjectQueries::CHOICE_LIMIT) : [],
            'projectForms' => self::PROJECT_FORMS,
            'projects' => $projects,
            'ticketsUrls' => array_combine(
                array_map(static fn (ProjectSummary $project): string => $project->id(), $projects),
                array_map($this->projectTicketsLink->urlOf(...), $projects),
            ),
        ]);
    }

    /**
     * The steps the team works with now (moveTargetsFor: under the policy locked the team's own steps are left out,
     * as the page says teams cannot change the steps), in the five groups in page order.
     *
     * @return list<array{code: string, label: string, steps: list<StepView>}>
     */
    private function stepGroups(TeamId $teamId): array
    {
        $stepsByGroup = [];
        foreach ($this->stepQueries->moveTargetsFor($teamId) as $step) {
            $stepsByGroup[$step->groupCode()][] = $step;
        }

        $groups = [];
        foreach (self::GROUP_ORDER as $code => $label) {
            $groups[] = ['code' => $code, 'label' => $label, 'steps' => $stepsByGroup[$code] ?? []];
        }

        return $groups;
    }
}
