<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;
use App\SharedKernel\Domain\TicketId;

/**
 * One request to the server-side AI: the feature, the ticket it is about (none for a draft report) and what the person
 * typed (none for a summary). The gateway loads the ticket itself, through the voters.
 */
final readonly class AiTask
{
    public function __construct(
        public AiFeature $feature,
        public ?TicketId $ticketId = null,
        public ?string $userInput = null,
    ) {
    }
}
