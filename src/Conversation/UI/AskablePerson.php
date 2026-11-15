<?php

declare(strict_types=1);

namespace App\Conversation\UI;

/**
 * One person the composer offers to ask, as a chip with their first name.
 */
final readonly class AskablePerson
{
    /**
     * @param string $userId the user id string the question form sends as ThreadController::ASKED_USER_FIELD
     */
    public function __construct(
        public string $userId,
        public string $firstName,
    ) {
    }
}
