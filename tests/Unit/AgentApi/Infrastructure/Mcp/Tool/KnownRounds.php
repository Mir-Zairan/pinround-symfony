<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundView;
use App\Review\Api\SharedRoundNotes;
use App\Review\Api\TicketRounds;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The rounds of one organization's tickets, read as DbalRoundQueries::draftScreenshotOf reads them: the draft of the
 * ticket that holds the picture, whoever owns it and shared or not, with the id matched in any case; null for a
 * malformed id, a picture of a report or of another ticket. The queries set_verdict never uses throw.
 */
final class KnownRounds implements RoundQueries
{
    /** Version nibble 7 and variant bits 10xx, as ScreenshotId accepts them; \z, as $ also takes a trailing newline. */
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** @var list<array{TicketId, RoundView}> */
    private array $rounds = [];

    /** @var list<array{string, string}> the ticket id and screenshot id of each draftScreenshotOf, as asked */
    public array $screenshotsLookedUp = [];

    public function __construct(private readonly OrganizationId $organizationId)
    {
    }

    public function add(TicketId $ticketId, RoundView $round): void
    {
        $this->rounds[] = [$ticketId, $round];
    }

    public function draftScreenshotOf(TicketId $ticketId, string $screenshotId): ?RoundAccessFacts
    {
        $this->screenshotsLookedUp[] = [$ticketId->toString(), $screenshotId];
        $wantedId = strtolower($screenshotId);
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $wantedId)) {
            return null;
        }
        foreach ($this->rounds as [$roundTicketId, $round]) {
            if (!$roundTicketId->equals($ticketId) || RoundView::DRAFT !== $round->kind) {
                continue;
            }
            foreach ($round->screenshots as $screenshot) {
                if ($screenshot->id === $wantedId) {
                    return new RoundAccessFacts($this->organizationId, $round->id, $roundTicketId, $round->ownerId, $round->isUnsharedDraft(), false);
                }
            }
        }

        return null;
    }

    public function forTicket(TicketId $ticketId, UserId $viewer): TicketRounds
    {
        throw new \LogicException('set_verdict never reads every round of a ticket.');
    }

    public function accessFactsOf(string $roundId): ?RoundAccessFacts
    {
        throw new \LogicException('set_verdict finds a round by its picture, not by its id.');
    }

    public function draftOf(string $roundId, UserId $owner): ?RoundView
    {
        throw new \LogicException('set_verdict never reads a draft with its pictures.');
    }

    public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes
    {
        throw new \LogicException('set_verdict never reads round notes.');
    }

    public function sharedRoundNotes(TicketId $ticketId): ?SharedRoundNotes
    {
        throw new \LogicException('set_verdict never reads round notes.');
    }
}
