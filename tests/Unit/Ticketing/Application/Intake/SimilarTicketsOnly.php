<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application\Intake;

use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\TicketVisibility;

/**
 * Answers similarOpenTickets() with fixed tickets in the search's order, as the real query hands them over, and counts
 * the asks; the other reads fail, as duplicate suggestions never use them.
 */
final class SimilarTicketsOnly implements IntakeCandidateQueries
{
    public int $asked = 0;

    /**
     * @param list<IntakeCandidate> $similar best first
     */
    public function __construct(private readonly array $similar)
    {
    }

    public function openCandidatesIn(string $projectId, TicketVisibility $reporter, bool $includeTeamOnly): array
    {
        throw new \LogicException('Duplicate suggestions read only similar tickets.');
    }

    public function openCandidatesByKeys(TicketVisibility $reporter, array $keys): array
    {
        throw new \LogicException('Duplicate suggestions read only similar tickets.');
    }

    public function similarOpenTickets(TicketVisibility $reporter, string $text): array
    {
        ++$this->asked;

        return \array_slice($this->similar, 0, self::SIMILAR_LIMIT);
    }
}
