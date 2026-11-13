<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Who the ticket should wait on in Needs input is not one person or one label of one line and at most 100 characters,
 * so it was not moved. Only the waiting-on fields the caller sent give this; any other \InvalidArgumentException of a
 * move is a fault in stored data or code.
 */
final class WaitingOnRefused extends \InvalidArgumentException
{
}
