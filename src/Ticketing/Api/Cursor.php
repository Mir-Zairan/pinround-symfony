<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * Where the next page of one step of the tickets list starts: after the last row shown, by the list's order
 * (priority rank, then updated_at newest first, then id highest first). The list page carries it unsigned
 * (TicketListCursorParameter), as a forged one only moves where the page starts; the list still reads only the
 * tickets the viewer may see.
 */
final readonly class Cursor
{
    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param string             $stepId       the uuid of the step to continue
     * @param int                $priorityRank the last shown row's rank, 0 (blocker) to 4 (minor)
     * @param \DateTimeImmutable $updatedAt    the last shown row's updated_at, to the second as stored
     * @param TicketId           $ticketId     the last shown row's id
     *
     * @throws \InvalidArgumentException when the step id is not a canonical uuid or the rank is out of range
     */
    public function __construct(
        public string $stepId,
        public int $priorityRank,
        public \DateTimeImmutable $updatedAt,
        public TicketId $ticketId,
    ) {
        if (1 !== preg_match(self::CANONICAL_UUID, $stepId)) {
            throw new \InvalidArgumentException('A cursor\'s step id must be a canonical lowercase uuid.');
        }
        if ($priorityRank < 0 || $priorityRank > 4) {
            throw new \InvalidArgumentException('A cursor\'s priority rank is 0 to 4.');
        }
    }
}
