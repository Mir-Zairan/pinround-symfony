<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * One line of the ticket page's What happened, from {@see TicketHistorySentences}.
 */
final readonly class ActivityLine
{
    /**
     * @param string $time     when it happened, as the viewer reads it: "now", "5 min ago", "3 h ago", "2 Nov"
     * @param string $sentence for example "Omar moved it from In progress to Code review"
     */
    public function __construct(public string $time, public string $sentence)
    {
    }
}
