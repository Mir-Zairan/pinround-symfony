<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * A reviewer saved a screen area's verdict, in place of any earlier one. It never moves the ticket. It says whether
 * the area has a note, never its text (ADR-0027). No module consumes it yet; open round pages will use it to update the
 * area.
 */
final readonly class AreaVerdictSaved implements ReviewEvent
{
    /**
     * @param string $roundId the review round of the area
     * @param string $areaId  the rvw_area id
     * @param string $verdict VerdictCommands::AREA_PASS, AREA_NEEDS_FIX or AREA_QUESTION
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $roundId,
        public string $areaId,
        public string $verdict,
        public bool $hasNote,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
