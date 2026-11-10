<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Whose turn it is: a person, or a label such as Customer when the ticket waits on someone outside the team, or nobody.
 */
final readonly class Turn
{
    /**
     * @param UserId|null       $userId       null when the turn is a label or nobody's
     * @param string|null       $label        set only when $userId is null and somebody still holds the turn
     * @param NeedsYouKind|null $kind         null when nobody holds the turn (Done)
     * @param string|null       $overrideNote set when a fallback chose the person, for the caller to write to the
     *                                        ticket's activity; for example "Lena reviews her own code: no other
     *                                        developer in the team"
     */
    public function __construct(
        public ?UserId $userId,
        public ?string $label,
        public ?NeedsYouKind $kind,
        public ?string $overrideNote = null,
    ) {
    }

    public static function nobody(): self
    {
        return new self(null, null, null);
    }
}
