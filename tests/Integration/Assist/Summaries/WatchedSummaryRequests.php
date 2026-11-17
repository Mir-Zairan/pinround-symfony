<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Summaries;

use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\Summaries\SummaryRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The real request states, with a look at what another database connection sees each time a request ends, so a test
 * tells an end written after the job's commit from one written inside its transaction. An armed failure is thrown
 * instead of ending the request, as the real state throws when Valkey cannot be reached, and leaves the state as it was.
 */
final class WatchedSummaryRequests implements SummaryRequests
{
    /** @var list<array{string, mixed}> */
    private array $endings = [];

    private ?\RuntimeException $endFailure = null;

    /**
     * @param \Closure(): mixed $whatAnotherConnectionSees
     */
    public function __construct(private readonly SummaryRequests $realRequests, private readonly \Closure $whatAnotherConnectionSees)
    {
    }

    public function failEveryEndWith(\RuntimeException $endFailure): void
    {
        $this->endFailure = $endFailure;
    }

    /**
     * @return list<array{string, mixed}> each end's method and what another connection saw as it began, in call order
     */
    public function endings(): array
    {
        return $this->endings;
    }

    public function claim(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): bool
    {
        return $this->realRequests->claim($organizationId, $ticketId, $requesterId);
    }

    public function recordFailure(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId, string $sentence): void
    {
        $this->endings[] = ['recordFailure', ($this->whatAnotherConnectionSees)()];
        if (null !== $this->endFailure) {
            throw $this->endFailure;
        }
        $this->realRequests->recordFailure($organizationId, $ticketId, $requesterId, $sentence);
    }

    public function release(OrganizationId $organizationId, TicketId $ticketId): void
    {
        $this->endings[] = ['release', ($this->whatAnotherConnectionSees)()];
        if (null !== $this->endFailure) {
            throw $this->endFailure;
        }
        $this->realRequests->release($organizationId, $ticketId);
    }

    public function latestOf(OrganizationId $organizationId, TicketId $ticketId): ?SummaryRequestView
    {
        return $this->realRequests->latestOf($organizationId, $ticketId);
    }
}
