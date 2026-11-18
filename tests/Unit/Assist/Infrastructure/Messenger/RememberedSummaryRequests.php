<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Messenger;

use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\Summaries\SummaryRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Request states in memory, one per organization and ticket as ValkeySummaryRequests keys them, a failure replacing
 * whatever was there. An armed failure is thrown instead of writing, as the real state throws when Valkey cannot be
 * reached, and leaves the states as they were. Only recordFailure is kept; the rest refuse, as no test here needs them.
 */
final class RememberedSummaryRequests implements SummaryRequests
{
    /** @var array<string, array{string, string, ?string}> status, requester and sentence by "organization:ticket" */
    public array $states = [];

    public ?\RuntimeException $failure = null;

    public function claim(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): bool
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function markAiMayBeAsked(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): bool
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function recordFailure(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId, string $sentence): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $this->states[$organizationId->toString().':'.$ticketId->toString()] = ['failed', $requesterId->toString(), $sentence];
    }

    public function release(OrganizationId $organizationId, TicketId $ticketId): void
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function latestOf(OrganizationId $organizationId, TicketId $ticketId): ?SummaryRequestView
    {
        throw new \LogicException('Not kept by this double.');
    }
}
