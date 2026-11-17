<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

/**
 * The AI wrote a summary a person asked for with "Summarize with AI"; Ticketing stores it as the ticket's current
 * summary. Dispatch it on command.bus inside the transaction of the job that made it, under that organization: it has
 * no route, so it is handled at once and rolls back with the job. Not a TicketEvent, so it never goes to the outbox.
 * A summary that breaks a summary rule is not stored: the dispatch throws a HandlerFailedException that wraps a
 * GeneratedSummaryRefused.
 */
final readonly class SummaryGenerated
{
    /**
     * @param string $requesterId   the person who asked for it
     * @param string $model         the model that wrote it, as the AI provider names it
     * @param string $promptVersion the prompt template that made it, such as "summary.v1"
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $text,
        public string $requesterId,
        public string $model,
        public string $promptVersion,
        public \DateTimeImmutable $generatedAt,
    ) {
    }
}
