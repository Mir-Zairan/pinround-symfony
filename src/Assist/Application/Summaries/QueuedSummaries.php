<?php

declare(strict_types=1);

namespace App\Assist\Application\Summaries;

use App\Assist\Api\Summaries;
use App\Assist\Api\SummaryRequestRefused;
use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiTask;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Domain\AiFeature;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;

/**
 * Asks AiTaskAdmission, the check the job's gateway call makes first, and the person's call limit without counting a
 * call, so a request the gateway would refuse queues nothing; then claims the ticket's request state and queues the
 * job. A person who is no member is refused as one who cannot see the ticket, so the refusal never tells whether it
 * exists.
 */
final readonly class QueuedSummaries implements Summaries
{
    public function __construct(
        private SummaryRequesters $summaryRequesters,
        private AiTaskAdmission $aiTaskAdmission,
        private AiCallLimit $aiCallLimit,
        private SummaryRequests $summaryRequests,
        private SummaryJobQueue $summaryJobQueue,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
    ) {
    }

    public function request(TicketId $ticketId, UserId $requesterId, OrganizationId $organizationId): void
    {
        $requester = $this->summaryRequesters->actorOf($requesterId, $organizationId) ?? throw self::refusedFor(PolicyRefusal::CannotSeeTicket);
        $refusal = $this->aiTaskAdmission->refusalFor(new AiTask(AiFeature::Summary, $ticketId), $requester);
        if (null !== $refusal) {
            throw self::refusedFor($refusal);
        }
        if (!$this->aiCallLimit->hasCallsLeft($requester)) {
            throw new SummaryRequestRefused(SummaryRequestRefused::TOO_MANY_AI_REQUESTS, AiRateLimited::PER_PERSON_MESSAGE);
        }

        if (!$this->summaryRequests->claim($organizationId, $ticketId, $requesterId)) {
            throw SummaryRequestRefused::alreadyQueued();
        }
        try {
            $this->summaryJobQueue->queue(new GenerateSummary($organizationId->toString(), $ticketId->toString(), $requesterId->toString()));
        } catch (\Throwable $queueFailure) {
            try {
                $this->summaryRequests->release($organizationId, $ticketId);
            } finally {
                // The queue's failure is the one the caller must see, also when the release fails too.
                throw $queueFailure;
            }
        }
    }

    public function latestRequestOf(TicketId $ticketId, UserId $viewerId, OrganizationId $organizationId): ?SummaryRequestView
    {
        $latest = $this->summaryRequests->latestOf($organizationId, $ticketId);
        if (null === $latest || null === $this->summaryRequesters->actorOf($viewerId, $organizationId)) {
            return null;
        }
        $visibility = $this->ticketVisibilities->of($organizationId, $viewerId);

        return [] === $this->ticketQueries->visibleTicketIdsOf($visibility, [$ticketId]) ? null : $latest;
    }

    private static function refusedFor(PolicyRefusal $refusal): SummaryRequestRefused
    {
        return new SummaryRequestRefused($refusal->value, $refusal->sentence());
    }
}
