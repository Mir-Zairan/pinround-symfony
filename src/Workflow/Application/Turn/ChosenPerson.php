<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;

/**
 * The person a reviewer or tester choice found, and the note to write to the ticket's activity when a fallback chose them.
 */
final readonly class ChosenPerson
{
    public function __construct(
        public UserId $userId,
        public ?string $overrideNote = null,
    ) {
    }
}
