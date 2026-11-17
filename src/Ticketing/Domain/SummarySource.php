<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * What made a ticket summary: the AI on sync, the AI on a person's "Summarize with AI", a shared explanation made the
 * main summary, or a person writing it (ai-architecture.md › Summaries pipeline). The values are tkt_summary.source.
 */
enum SummarySource: string
{
    case Sync = 'sync';
    case Ai = 'ai';
    case Explanation = 'explanation';
    case Human = 'human';
}
