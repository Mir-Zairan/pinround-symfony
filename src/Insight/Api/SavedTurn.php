<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One ticket's turn as its row in ins_ticket_turn holds it, the one Home and Whose turn show.
 */
final readonly class SavedTurn
{
    /**
     * @param UserId|null $userId null when the turn is a label's
     * @param string|null $label  who holds the turn when it is not a user, for example "Customer"
     * @param string      $kind   why it is their turn: answer, check, review, test, assign, input, decide or build
     */
    public function __construct(public ?UserId $userId, public ?string $label, public string $kind)
    {
    }
}
