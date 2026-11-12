<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * Where the next page of one step of the tickets list starts: after the last row shown, by the list's order
 * (priority rank, then updated_at newest first, then id highest first; while a search is on, the exact key first,
 * then the match score highest first, then id highest first). The list page carries it unsigned
 * (TicketListCursorParameter), as a forged one only moves where the page starts; the list still reads only the
 * tickets the viewer may see.
 */
final readonly class Cursor
{
    /**
     * A match position: "1" when the row's key is the search text, else "0", then "_" and the row's match score as the
     * database writes it, a numeric of 12 decimals, so it reads back as the same value and the next page starts
     * exactly after the last row. Unanchored, for TicketListCursorParameter's pattern too.
     */
    public const string MATCH_POSITION = '[01]_(?:0|[1-9]\d{0,5})\.\d{12}';

    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param string             $stepId        the uuid of the step to continue
     * @param int                $priorityRank  the last shown row's rank, 0 (blocker) to 4 (minor)
     * @param \DateTimeImmutable $updatedAt     the last shown row's updated_at, to the second as stored
     * @param TicketId           $ticketId      the last shown row's id
     * @param string|null        $matchPosition the last shown row's place in the search order, in MATCH_POSITION
     *                                          form; null when the list has no search
     *
     * @throws \InvalidArgumentException when the step id is not a canonical uuid, the rank is out of range or the
     *                                   match position is not in MATCH_POSITION form
     */
    public function __construct(
        public string $stepId,
        public int $priorityRank,
        public \DateTimeImmutable $updatedAt,
        public TicketId $ticketId,
        public ?string $matchPosition = null,
    ) {
        if (1 !== preg_match(self::CANONICAL_UUID, $stepId)) {
            throw new \InvalidArgumentException('A cursor\'s step id must be a canonical lowercase uuid.');
        }
        if ($priorityRank < 0 || $priorityRank > 4) {
            throw new \InvalidArgumentException('A cursor\'s priority rank is 0 to 4.');
        }
        if (null !== $matchPosition && 1 !== preg_match('/^'.self::MATCH_POSITION.'\z/', $matchPosition)) {
            throw new \InvalidArgumentException('A cursor\'s match position is the exact-key flag and the match score as the database writes them.');
        }
    }

    /**
     * @param string $matchScore a non-negative numeric of 12 decimals as the database writes it
     */
    public static function matchPositionOf(bool $isExactKeyMatch, string $matchScore): string
    {
        return ($isExactKeyMatch ? '1' : '0').'_'.$matchScore;
    }

    /**
     * @return array{bool, string}|null whether the last shown row's key is the search text, and its match score as
     *                                  the database wrote it; null when the list has no search
     */
    public function searchOrderValues(): ?array
    {
        if (null === $this->matchPosition) {
            return null;
        }
        [$exactKeyFlag, $matchScore] = explode('_', $this->matchPosition, 2);

        return ['1' === $exactKeyFlag, $matchScore];
    }
}
