<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Whose a ticket's turn is and since when, as its row in ins_ticket_turn holds it.
 */
final readonly class TurnWait
{
    /**
     * @param UserId|null $userId null when the turn is a label's
     * @param string|null $label  who holds the turn when it is not a user, for example "Customer"
     */
    public function __construct(public ?UserId $userId, public ?string $label, public \DateTimeImmutable $since)
    {
    }
}
