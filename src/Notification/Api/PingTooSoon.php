<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The sender already pinged this person about this ticket within the organization's ping limit, so no ping was sent.
 */
final class PingTooSoon extends \DomainException
{
    /**
     * @param \DateTimeImmutable $againAt the first instant the sender may ping this person about this ticket again
     */
    public function __construct(
        public readonly \DateTimeImmutable $againAt,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('You pinged this person about this ticket recently.', 0, $previous);
    }
}
