<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The person asked is not on the ticket as its locked row names them (assignee, reporter, reviewer or tester) nor its
 * team's lead, so the ticket cannot pause on them; nothing was saved.
 */
final class AskedPersonNotOnTicket extends \DomainException
{
}
