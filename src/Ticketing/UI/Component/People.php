<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Ticketing\UI\TicketPeople;
use App\Ticketing\UI\TicketPerson;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's People section (ticket-page.md, People): it only shows the rows TicketPeople worked out. Ping comes
 * with P04.
 */
#[AsTwigComponent(name: 'People', template: '@Ticketing/components/People.html.twig')]
final class People
{
    /** @var list<TicketPerson> from {@see TicketPeople::of} */
    public array $people = [];
}
