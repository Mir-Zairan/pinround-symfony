<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Ticketing\UI\ActivityLine;
use App\Ticketing\UI\TicketPageParts;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's What happened section (ticket-page.md, What happened): it only shows the lines TicketPageParts
 * worded.
 */
#[AsTwigComponent(name: 'Activity', template: '@Ticketing/components/Activity.html.twig')]
final class Activity
{
    /** @var list<ActivityLine> from {@see TicketPageParts::of}, newest first, the created line last */
    public array $lines = [];
}
