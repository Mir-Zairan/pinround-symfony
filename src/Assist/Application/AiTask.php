<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;
use App\SharedKernel\Domain\TicketId;

/**
 * One request to the server-side AI: the feature, what it is about and what the person typed (none for a summary). A
 * summary or an explanation is about a ticket, which the gateway loads itself as the person may see it; an intake check
 * is about a draft report, which has no ticket yet.
 */
final readonly class AiTask
{
    /**
     * @throws \InvalidArgumentException when an intake task has no draft or a ticket, or another task has no ticket or a
     *                                   draft
     */
    public function __construct(
        public AiFeature $feature,
        public ?TicketId $ticketId = null,
        public ?string $userInput = null,
        public ?AiDraft $draft = null,
    ) {
        if (AiFeature::Intake === $feature && (null === $draft || null !== $ticketId)) {
            throw new \InvalidArgumentException('An intake task needs a draft report and no ticket.');
        }
        if (AiFeature::Intake !== $feature && (null === $ticketId || null !== $draft)) {
            throw new \InvalidArgumentException(\sprintf('The %s task needs a ticket and no draft report.', $feature->value));
        }
    }
}
