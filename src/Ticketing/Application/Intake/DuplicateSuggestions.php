<?php

declare(strict_types=1);

namespace App\Ticketing\Application\Intake;

use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Domain\Intake\DuplicateWords;

/**
 * The possible duplicates Report a problem shows (P06.T2, FR-INT-03, FR-INT-15): the search's best open tickets the
 * reporter may see, then the word rule, then the three with the most shared words. The live preview and the create ask
 * the same, so a ticket is checked for "dupe" exactly when the page showed one.
 */
final readonly class DuplicateSuggestions
{
    public const int MAX_SHOWN = 3;

    public function __construct(private IntakeCandidateQueries $intakeCandidateQueries)
    {
    }

    /**
     * One query, none for a title under DuplicateWords::MIN_TITLE_LENGTH characters.
     *
     * @param list<string> $pinNotes
     *
     * @return list<IntakeCandidate> at most MAX_SHOWN, the most shared words first, the search's order on a tie
     */
    public function for(TicketVisibility $reporter, string $title, string $detail, array $pinNotes): array
    {
        if (mb_strlen(trim($title)) < DuplicateWords::MIN_TITLE_LENGTH) {
            return [];
        }
        $reportWords = DuplicateWords::of($title, $detail, ...$pinNotes);
        $matches = [];
        foreach ($this->intakeCandidateQueries->similarOpenTickets($reporter, $title) as $position => $candidate) {
            $shared = DuplicateWords::sharedWith($reportWords, $candidate->title);
            if (null !== $shared) {
                $matches[] = [$shared, $position, $candidate];
            }
        }
        usort($matches, static fn (array $first, array $second): int => [$second[0], $first[1]] <=> [$first[0], $second[1]]);

        return array_map(static fn (array $match): IntakeCandidate => $match[2], \array_slice($matches, 0, self::MAX_SHOWN));
    }
}
