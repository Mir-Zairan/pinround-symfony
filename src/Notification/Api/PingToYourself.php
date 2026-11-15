<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The sender tried to ping themselves, so no ping was sent.
 */
final class PingToYourself extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('You cannot ping yourself.', 0, $previous);
    }
}
