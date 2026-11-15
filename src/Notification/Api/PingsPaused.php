<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The store that keeps the ping limit cannot be reached, so no ping was sent: without it the limit could not hold.
 */
final class PingsPaused extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Pings are paused for a moment. Try again soon.', 0, $previous);
    }
}
