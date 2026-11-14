<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The person pinged is not a member of the organization or may not see the ticket, so no ping was sent.
 */
final class PingRecipientCannotView extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This person cannot see this ticket.', 0, $previous);
    }
}
