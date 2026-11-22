<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * A reviewer saved the verdict on a screenshot of a shared draft, in place of any earlier one. It never moves the
 * ticket. It says whether the verdict has a note, never its text (ADR-0027). Notification refreshes the ticket's open
 * pages; What happened does not list it yet, so Ticketing lets it pass by.
 */
final readonly class ScreenshotVerdictSaved implements ReviewEvent
{
    /**
     * @param string $roundId      the shared draft of the screenshot
     * @param string $screenshotId the rvw_screenshot id
     * @param string $verdict      VerdictCommands::AREA_PASS, AREA_NEEDS_FIX or AREA_QUESTION
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $roundId,
        public string $screenshotId,
        public string $verdict,
        public bool $hasNote,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
