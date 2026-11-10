<?php

declare(strict_types=1);

namespace App\Workflow\UI;

use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Workflow\Api\WorkflowPermission;
use App\Workflow\Application\AddStep;
use App\Workflow\Application\CoreStepNotRemovable;
use App\Workflow\Application\RemoveStep;
use App\Workflow\Application\RuleTeamNotFound;
use App\Workflow\Application\RuleUnknown;
use App\Workflow\Application\SetRule;
use App\Workflow\Application\StepGroupUnknown;
use App\Workflow\Application\StepHasOpenTickets;
use App\Workflow\Application\StepInDoneRefused;
use App\Workflow\Application\StepNameMissing;
use App\Workflow\Application\StepTeamNotFound;
use App\Workflow\Application\TeamRulesNeedTeamPolicy;
use App\Workflow\Application\TeamStepsLocked;
use App\Workflow\Application\UnknownStep;
use App\Workflow\Application\WorkflowTransactions;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The step and rule actions of the team page (FR-WF-04, P02.T8): add a team step, remove one, and switch one of the
 * team's own rules. Each answers with a redirect to the team page and a toast. Only team steps and team rules: company
 * steps and the organization's rules are the Admin's, elsewhere.
 *
 * Without the request transaction, as TeamProjectsController: WorkflowTransactions gives each action a transaction of
 * its own, which also covers the team lookup and the access checks, so the toast is set only once the change is
 * committed. In that order: a slug of no team of the organization is not found, a person the voter refuses is denied
 * (also every lead under a step policy that does not let her), and a form token that is not valid changes nothing.
 */
final class TeamWorkflowController extends AbstractController
{
    public const string ADD_STEP_ROUTE = 'workflow_team_steps_add';
    public const string REMOVE_STEP_ROUTE = 'workflow_team_steps_remove';
    public const string SET_RULE_ROUTE = 'workflow_team_rules_set';

    /** The CSRF token ids, one for each action, for csrf_token() in the template. */
    public const string ADD_STEP_CSRF_ID = 'workflow_team_steps_add';
    public const string REMOVE_STEP_CSRF_ID = 'workflow_team_steps_remove';
    public const string SET_RULE_CSRF_ID = 'workflow_team_rules_set';

    /** The form fields the actions read. IS_ON_FIELD is IS_ON or IS_OFF; anything else is a bad request. */
    public const string CSRF_FIELD = '_csrf_token';
    public const string NAME_FIELD = 'name';
    public const string GROUP_FIELD = 'group';
    public const string STEP_ID_FIELD = 'step_id';
    public const string RULE_FIELD = 'rule';
    public const string IS_ON_FIELD = 'is_on';
    public const string IS_ON = '1';
    public const string IS_OFF = '0';

    /**
     * The groups a team step may be added to, by group code, with the label people read, in the order the form shows
     * them; Done takes no extra steps. Copies of the Domain's Group labels, which Workflow_UI may not import;
     * TeamWorkflowControllerTest keeps them the same.
     */
    public const array STEP_GROUP_LABELS = ['todo' => 'To do', 'doing' => 'Doing', 'check' => 'Checking', 'wait' => 'Waiting'];

    /** The team rules by rule code, with the line the team page shows; TeamWorkflowControllerTest keeps the codes. */
    public const array RULE_LABELS = [
        'tester_moves_done' => 'Only the tester can move a ticket to Done',
        'no_self_review' => 'Nobody approves their own code review',
        'review_before_test' => 'Code review must happen before testing',
    ];

    /**
     * Copies of TeamController::ROUTE and TeamController::SLUG_REQUIREMENT, which Workflow_UI may not import;
     * TeamWorkflowControllerTest keeps them the same.
     */
    public const string TEAM_PAGE_ROUTE = 'team_show';
    public const string SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** With the step's name after trimming, then the group's label twice. */
    public const string STEP_ADDED = 'Added “%s” to %s. Managers still see it as %s.';
    public const string STEP_REMOVED = 'Removed the team step.';
    /** With the rule's line from RULE_LABELS. */
    public const string RULE_ON = 'Turned on: %s';
    /** With the rule's line from RULE_LABELS. */
    public const string RULE_OFF = 'Turned off: %s';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string NAME_MISSING = 'Give the step a name.';
    public const string GROUP_UNKNOWN = 'Choose To do, Doing, Checking or Waiting for the step.';
    public const string DONE_REFUSED = 'Done cannot get extra steps.';
    public const string STEP_NOT_OF_TEAM = 'That step is not one of this team’s own steps.';
    public const string RULE_UNKNOWN = 'There is no such rule.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string TEAM_NOT_FOUND = 'No team of this organization has that slug.';

    public function __construct(
        private readonly WorkflowTransactions $workflowTransactions,
        private readonly TeamQueries $teamQueries,
        private readonly AddStep $addStep,
        private readonly RemoveStep $removeStep,
        private readonly SetRule $setRule,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/teams/{slug}/steps', name: self::ADD_STEP_ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function addStep(string $slug, Request $request): Response
    {
        $organizationId = $this->requestTenant();
        $name = $request->request->getString(self::NAME_FIELD);
        $groupCode = $request->request->getString(self::GROUP_FIELD);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $toast = $this->workflowTransactions->run(function () use ($organizationId, $slug, $name, $groupCode, $csrfToken): string {
                $team = $this->teamOf($organizationId, $slug);
                $this->denyAccessUnlessGranted(WorkflowPermission::STEP_CHANGE_TEAM, $team->id());
                if (!$this->isCsrfTokenValid(self::ADD_STEP_CSRF_ID, $csrfToken)) {
                    return self::FORM_EXPIRED;
                }
                ($this->addStep)($organizationId, $team->id(), $name, $groupCode);
                // AddStep refuses every group code but these four, so the label is there.
                $groupLabel = self::STEP_GROUP_LABELS[$groupCode];

                return \sprintf(self::STEP_ADDED, trim($name), $groupLabel, $groupLabel);
            });
        } catch (StepTeamNotFound) {
            throw $this->createNotFoundException(self::TEAM_NOT_FOUND);
        } catch (TeamStepsLocked $locked) {
            // The policy changed to locked after the voter read it.
            $toast = $locked->getMessage();
        } catch (StepNameMissing) {
            $toast = self::NAME_MISSING;
        } catch (StepGroupUnknown) {
            $toast = self::GROUP_UNKNOWN;
        } catch (StepInDoneRefused) {
            $toast = self::DONE_REFUSED;
        }

        return $this->redirectWithToast($slug, $toast);
    }

    /**
     * Only a step of the team in the slug: a core or company step, or another team's, is refused by RemoveStep, as
     * the voter was asked about this team alone.
     */
    #[Route('/teams/{slug}/steps/remove', name: self::REMOVE_STEP_ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function removeStep(string $slug, Request $request): Response
    {
        $organizationId = $this->requestTenant();
        $stepId = strtolower($request->request->getString(self::STEP_ID_FIELD));
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $toast = $this->workflowTransactions->run(function () use ($organizationId, $slug, $stepId, $csrfToken): string {
                $team = $this->teamOf($organizationId, $slug);
                $this->denyAccessUnlessGranted(WorkflowPermission::STEP_CHANGE_TEAM, $team->id());
                if (!$this->isCsrfTokenValid(self::REMOVE_STEP_CSRF_ID, $csrfToken)) {
                    return self::FORM_EXPIRED;
                }
                ($this->removeStep)($organizationId, $team->id(), $stepId, $this->clock->now());

                return self::STEP_REMOVED;
            });
        } catch (UnknownStep) {
            $toast = self::STEP_NOT_OF_TEAM;
        } catch (CoreStepNotRemovable|StepHasOpenTickets $refused) {
            $toast = $refused->getMessage();
        }

        return $this->redirectWithToast($slug, $toast);
    }

    #[Route('/teams/{slug}/rules', name: self::SET_RULE_ROUTE, requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function setRule(string $slug, Request $request): Response
    {
        $organizationId = $this->requestTenant();
        $ruleCode = $request->request->getString(self::RULE_FIELD);
        $isOnValue = $request->request->getString(self::IS_ON_FIELD);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $toast = $this->workflowTransactions->run(function () use ($organizationId, $slug, $ruleCode, $isOnValue, $csrfToken): string {
                $team = $this->teamOf($organizationId, $slug);
                $this->denyAccessUnlessGranted(WorkflowPermission::RULES_CHANGE_TEAM, $team->id());
                if (!$this->isCsrfTokenValid(self::SET_RULE_CSRF_ID, $csrfToken)) {
                    return self::FORM_EXPIRED;
                }
                $isOn = match ($isOnValue) {
                    self::IS_ON => true,
                    self::IS_OFF => false,
                    default => throw new BadRequestHttpException('Say whether the rule is on or off.'),
                };
                ($this->setRule)($organizationId, $team->id(), $ruleCode, $isOn);

                // SetRule refuses every rule code but these three, so the label is there.
                return \sprintf($isOn ? self::RULE_ON : self::RULE_OFF, self::RULE_LABELS[$ruleCode]);
            });
        } catch (RuleTeamNotFound) {
            throw $this->createNotFoundException(self::TEAM_NOT_FOUND);
        } catch (TeamRulesNeedTeamPolicy $needsTeamPolicy) {
            // The policy changed from team after the voter read it.
            $toast = $needsTeamPolicy->getMessage();
        } catch (RuleUnknown) {
            $toast = self::RULE_UNKNOWN;
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
        return $this->teamQueries->bySlug($organizationId, $slug) ?? throw $this->createNotFoundException(self::TEAM_NOT_FOUND);
    }

    private function redirectWithToast(string $slug, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);

        return $this->redirectToRoute(self::TEAM_PAGE_ROUTE, ['slug' => $slug]);
    }
}
