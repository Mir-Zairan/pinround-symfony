<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\AiDraft;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiTask;
use App\Assist\Application\Policy\AiRoute;
use App\Assist\Application\Policy\PolicyDecision;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Application\PromptTicket;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\DraftReportChecks;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\TicketVisibilities;

/**
 * The organization's policy for one server AI task, in the order of ai-architecture.md: AI on and the viewer allowed,
 * the ticket as the viewer may see it, then where it runs. GuardedAiGateway asks it before a provider call, and a page
 * that queues a task asks it first, so the person is refused up front for the same reason the gateway would give.
 *
 * The server never sends to a laptop, so the policy is decided with the laptop not ready: "laptop first" goes to the
 * cloud here, and "laptop" only refuses with the setup sheet's line. Nothing here calls a provider, meters or counts
 * against the person's limit.
 *
 * A draft report has no ticket yet: the reporter wrote it, so they may see it, and it is not team-only. On the cloud
 * route its words decide first: a draft that mentions security never goes to cloud AI, whatever the admin's opt-in for
 * team-only tickets says. Its duplicate candidates are the open tickets of its project the reporter may see, with the
 * team-only ones only when the admin lets them go to cloud AI.
 */
final readonly class AiTaskAdmission
{
    public function __construct(
        private AiSettingsQueries $aiSettingsQueries,
        private PolicyEvaluator $policyEvaluator,
        private AskedTicketQueries $askedTicketQueries,
        private DraftReportChecks $draftReportChecks,
        private IntakeCandidateQueries $intakeCandidateQueries,
        private TicketVisibilities $ticketVisibilities,
    ) {
    }

    /**
     * Reads under row-level security, so it needs an open transaction whose tenant is the viewer's organization.
     *
     * @throws AiNotAllowed when the policy forbids the task for this person, ticket or draft report
     */
    public function admit(AiTask $task, ActorContext $viewer): AdmittedAiTask
    {
        $settings = $this->settingsForAllowedViewer($viewer);
        if (null !== $task->draft) {
            return $this->admitDraft($task->draft, $settings, $this->draftDecision($task->draft, $settings, $viewer), $viewer);
        }

        // AiTask refuses a task with neither a ticket nor a draft; this only proves it to the type checker.
        $ticketId = $task->ticketId ?? throw new \LogicException('A server AI task names a ticket or a draft report.');

        return $this->admitTicket($ticketId, $settings, $viewer);
    }

    /**
     * The same decision as admit(), as a value: null when the task may be sent. Reads the ticket as admit() does, but
     * never a draft's candidates: nothing in them can refuse the draft.
     */
    public function refusalFor(AiTask $task, ActorContext $viewer): ?PolicyRefusal
    {
        try {
            if (null !== $task->draft) {
                $this->draftDecision($task->draft, $this->settingsForAllowedViewer($viewer), $viewer);
            } else {
                $this->admit($task, $viewer);
            }
        } catch (AiNotAllowed $refusal) {
            return $refusal->refusal;
        }

        return null;
    }

    /**
     * @throws AiNotAllowed when AI is off or not for this viewer
     */
    private function settingsForAllowedViewer(ActorContext $viewer): AiSettings
    {
        $settings = $this->aiSettingsQueries->aiSettingsOf($viewer->organizationId());
        $viewerRefusal = $this->policyEvaluator->refusalForViewer($settings, $viewer);
        if (null !== $viewerRefusal) {
            throw new AiNotAllowed($viewerRefusal);
        }

        return $settings;
    }

    private function admitTicket(TicketId $ticketId, AiSettings $settings, ActorContext $viewer): AdmittedAiTask
    {
        $ticket = $this->askedTicketQueries->visibleTicketOf($ticketId, $viewer);
        if (null === $ticket) {
            throw new AiNotAllowed(PolicyRefusal::CannotSeeTicket);
        }
        $decision = $this->policyEvaluator->decide($settings, $viewer, canSeeTicket: true, isTicketRestricted: $ticket->isTeamOnly, isLaptopReady: false);
        if (null !== $decision->refusal) {
            throw new AiNotAllowed($decision->refusal);
        }

        return new AdmittedAiTask($settings, $ticket->promptTicket, $decision);
    }

    /**
     * @throws AiNotAllowed when the policy or the draft's words keep it from AI
     */
    private function draftDecision(AiDraft $draft, AiSettings $settings, ActorContext $viewer): PolicyDecision
    {
        $decision = $this->policyEvaluator->decide($settings, $viewer, canSeeTicket: true, isTicketRestricted: false, isLaptopReady: false);
        if (null !== $decision->refusal) {
            throw new AiNotAllowed($decision->refusal);
        }
        if (AiRoute::Cloud === $decision->route && $this->draftReportChecks->isSecuritySensitive($draft->title, $draft->detail, $draft->pinNotes)) {
            throw new AiNotAllowed(PolicyRefusal::SecuritySensitiveDraftToCloud);
        }

        return $decision;
    }

    private function admitDraft(AiDraft $draft, AiSettings $settings, PolicyDecision $decision, ActorContext $viewer): AdmittedAiTask
    {
        $reporter = $this->ticketVisibilities->of($viewer->organizationId(), $viewer->userId());
        $duplicateCandidates = [];
        foreach ($this->intakeCandidateQueries->openCandidatesIn($draft->projectId, $reporter, includeTeamOnly: $settings->isRestrictedToCloudAllowed) as $candidate) {
            $duplicateCandidates[$candidate->key] = $candidate->title;
        }

        return new AdmittedAiTask(
            $settings,
            new PromptTicket($draft->title, $decision->isTitleOnly ? null : $draft->promptText()),
            $decision,
            $duplicateCandidates,
        );
    }
}
