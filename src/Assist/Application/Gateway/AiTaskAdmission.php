<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiTask;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettingsQueries;

/**
 * The organization's policy for one server AI task, in the order of ai-architecture.md: AI on and the viewer allowed,
 * the ticket as the viewer may see it, then where it runs. GuardedAiGateway asks it before a provider call, and a page
 * that queues a task asks it first, so the person is refused up front for the same reason the gateway would give.
 *
 * The server never sends to a laptop, so the policy is decided with the laptop not ready: "laptop first" goes to the
 * cloud here, and "laptop" only refuses with the setup sheet's line. Nothing here calls a provider, meters or counts
 * against the person's limit.
 */
final readonly class AiTaskAdmission
{
    public function __construct(
        private AiSettingsQueries $aiSettingsQueries,
        private PolicyEvaluator $policyEvaluator,
        private AskedTicketQueries $askedTicketQueries,
    ) {
    }

    /**
     * Reads under row-level security, so it needs an open transaction whose tenant is the viewer's organization.
     *
     * @throws AiNotAllowed              when the policy forbids the task for this person or ticket
     * @throws \InvalidArgumentException when the task names no ticket, before anything is read
     */
    public function admit(AiTask $task, ActorContext $viewer): AdmittedAiTask
    {
        // TODO(P08.T6): a draft report's word check decides whether it may go to cloud AI; until intake brings it, a
        // task without a ticket is refused instead of sent.
        $ticketId = $task->ticketId ?? throw new \InvalidArgumentException('A server AI task needs a ticket.');

        $settings = $this->aiSettingsQueries->aiSettingsOf($viewer->organizationId());
        $viewerRefusal = $this->policyEvaluator->refusalForViewer($settings, $viewer);
        if (null !== $viewerRefusal) {
            throw new AiNotAllowed($viewerRefusal);
        }
        $ticket = $this->askedTicketQueries->visibleTicketOf($ticketId, $viewer);
        if (null === $ticket) {
            throw new AiNotAllowed(PolicyRefusal::CannotSeeTicket);
        }
        $decision = $this->policyEvaluator->decide($settings, $viewer, canSeeTicket: true, isTicketRestricted: $ticket->isTeamOnly, isLaptopReady: false);
        if (null !== $decision->refusal) {
            throw new AiNotAllowed($decision->refusal);
        }

        return new AdmittedAiTask($settings, $ticket, $decision);
    }

    /**
     * The same decision as admit(), as a value: null when the task may be sent. Reads as admit() does.
     *
     * @throws \InvalidArgumentException when the task names no ticket, before anything is read
     */
    public function refusalFor(AiTask $task, ActorContext $viewer): ?PolicyRefusal
    {
        try {
            $this->admit($task, $viewer);
        } catch (AiNotAllowed $refusal) {
            return $refusal->refusal;
        }

        return null;
    }
}
