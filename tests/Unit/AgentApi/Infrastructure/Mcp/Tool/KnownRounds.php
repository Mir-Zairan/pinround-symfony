<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundView;
use App\Review\Api\TicketRounds;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The rounds of one organization's tickets, read as DbalRoundQueries reads them: another person's unshared draft is
 * left out of forTicket, an id is matched in any case, and draftOf finds only the owner's own draft, shared or not.
 * It does not check ticket visibility: a ticket the viewer may not see is one this fake has no rounds of.
 */
final class KnownRounds implements RoundQueries
{
    /** @var list<array{TicketId, RoundView}> */
    private array $rounds = [];

    /** @var list<string> the ids of the rounds forTicket was asked for */
    public array $ticketsRead = [];

    public function __construct(private readonly OrganizationId $organizationId)
    {
    }

    public function add(TicketId $ticketId, RoundView $round): void
    {
        $this->rounds[] = [$ticketId, $round];
    }

    public function forTicket(TicketId $ticketId, UserId $viewer): TicketRounds
    {
        $this->ticketsRead[] = $ticketId->toString();
        $shown = [];
        foreach ($this->rounds as [$roundTicketId, $round]) {
            if ($roundTicketId->equals($ticketId) && (!$round->isUnsharedDraft() || $round->ownerId->equals($viewer))) {
                $shown[] = $round;
            }
        }

        return new TicketRounds($shown);
    }

    public function accessFactsOf(string $roundId): ?RoundAccessFacts
    {
        foreach ($this->rounds as [$ticketId, $round]) {
            if ($round->id === strtolower($roundId)) {
                return new RoundAccessFacts($this->organizationId, $round->id, $ticketId, $round->ownerId, $round->isUnsharedDraft(), false);
            }
        }

        return null;
    }

    public function draftOf(string $roundId, UserId $owner): ?RoundView
    {
        foreach ($this->rounds as [, $round]) {
            if ($round->id === strtolower($roundId) && RoundView::DRAFT === $round->kind && $round->ownerId->equals($owner)) {
                return $round;
            }
        }

        return null;
    }

    public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes
    {
        throw new \LogicException('set_verdict never reads round notes.');
    }
}
