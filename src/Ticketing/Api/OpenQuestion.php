<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The open question of a ticket that waits on one person's answer, for a card that quotes it. Its body is ticket text:
 * never log it (ADR-0027).
 */
final readonly class OpenQuestion
{
    /**
     * @param string $body the question as written, at least one character, line breaks included
     */
    public function __construct(public UserId $askerId, public string $body)
    {
    }
}
