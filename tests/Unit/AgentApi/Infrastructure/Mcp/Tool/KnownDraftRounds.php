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
 * One organization's rounds by id, as DbalRoundQueries reads them: accessFactsOf finds any round, whoever owns it, by an
 * id in any case and null for a malformed one; draftOf only the owner's own draft, shared or not. The queries
 * add_screenshot never uses throw.
 */
final class KnownDraftRounds implements RoundQueries
{
    /** Version nibble 7 and variant bits 10xx; \z, as $ also takes a trailing newline. */
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** @var array<string, array{TicketId, RoundView}> by round id */
    private array $rounds = [];

    /** @var list<string> the round id of each draftOf, as asked */
    public array $draftsRead = [];

    public function __construct(private readonly OrganizationId $organizationId)
    {
    }

    public function add(TicketId $ticketId, RoundView $round): void
    {
        $this->rounds[$round->id] = [$ticketId, $round];
    }

    public function accessFactsOf(string $roundId): ?RoundAccessFacts
    {
        $round = $this->roundOf($roundId);
        if (null === $round) {
            return null;
        }
        [$ticketId, $view] = $round;

        return new RoundAccessFacts($this->organizationId, $view->id, $ticketId, $view->ownerId, $view->isUnsharedDraft(), false);
    }

    public function draftOf(string $roundId, UserId $owner): ?RoundView
    {
        $this->draftsRead[] = $roundId;
        $view = $this->roundOf($roundId)[1] ?? null;

        return null !== $view && RoundView::DRAFT === $view->kind && $view->ownerId->equals($owner) ? $view : null;
    }

    public function forTicket(TicketId $ticketId, UserId $viewer): TicketRounds
    {
        throw new \LogicException('add_screenshot never reads every round of a ticket.');
    }

    public function draftScreenshotOf(TicketId $ticketId, string $screenshotId): ?RoundAccessFacts
    {
        throw new \LogicException('add_screenshot finds a round by its upload, not by a picture in it.');
    }

    public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes
    {
        throw new \LogicException('add_screenshot never reads round notes.');
    }

    public function sharedRoundNotes(TicketId $ticketId): ?SharedRoundNotes
    {
        throw new \LogicException('add_screenshot never reads round notes.');
    }

    /**
     * @return array{TicketId, RoundView}|null
     */
    private function roundOf(string $roundId): ?array
    {
        $wantedId = strtolower($roundId);

        return 1 === preg_match(self::CANONICAL_UUID_V7, $wantedId) ? $this->rounds[$wantedId] ?? null : null;
    }
}
