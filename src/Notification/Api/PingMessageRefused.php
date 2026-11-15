<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The ping's message is empty or too long once trimmed, so no ping was sent.
 */
final class PingMessageRefused extends \DomainException
{
    /**
     * @param int $maxLength the most characters a ping's trimmed message may have
     */
    public function __construct(
        public readonly int $maxLength,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Write a message of 1 to %d characters.', $maxLength), 0, $previous);
    }
}
