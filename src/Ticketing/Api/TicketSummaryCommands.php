<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes to the summaries of the current organization's tickets. Each command runs in the caller's transaction, begun
 * under the organization as tenant, so a rollback leaves nothing of it. Who may run it is the caller's question.
 */
interface TicketSummaryCommands
{
    /**
     * Stores an AI summary a person asked for with "Summarize with AI" as the ticket's current summary, and moves the
     * one before to history. It locks the ticket's row until the caller's transaction ends, so of two stores for one
     * ticket the second waits for the first and then replaces it. A ticket the organization does not have gets
     * nothing: it may have been deleted while the AI wrote. The text, model and prompt version are kept trimmed.
     *
     * @param UserId $requesterId   the person who asked for it, the summary's author
     * @param string $model         the model that wrote it, as the AI provider names it
     * @param string $promptVersion the prompt template that made it, such as "summary.v1"
     *
     * @throws GeneratedSummaryRefused                    when the text, model or prompt version breaks a summary rule;
     *                                                    nothing is stored, and the same summary would be refused again
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any query
     * @throws \LogicException                            when $organizationId is not the organization set, or no
     *                                                    transaction is open, before any query
     */
    public function storeAiSummary(
        OrganizationId $organizationId,
        TicketId $ticketId,
        string $text,
        UserId $requesterId,
        string $model,
        string $promptVersion,
        \DateTimeImmutable $generatedAt,
    ): void;
}
