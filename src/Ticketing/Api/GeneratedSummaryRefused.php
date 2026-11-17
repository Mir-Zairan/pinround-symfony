<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Ticketing did not store an AI summary (TicketSummaryCommands::storeAiSummary): its text, model or prompt version
 * breaks a summary rule (blank, too long, a control character). Nothing was stored, and the same answer would be
 * refused again, so the job that asked records a failure instead of retrying. The message names the rule, never the text.
 */
final class GeneratedSummaryRefused extends \DomainException
{
    public function __construct(string $reason, \Throwable $previous)
    {
        parent::__construct($reason, 0, $previous);
    }
}
