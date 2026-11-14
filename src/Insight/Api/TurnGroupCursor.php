<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Where a next page of the Whose turn view starts: right after the group with this oldest wait and person or label, in
 * HomeQueries::whoseTurn's order. A forged one only moves where the page starts; the page still holds only what the
 * viewer may see.
 */
final readonly class TurnGroupCursor
{
    /**
     * @throws \InvalidArgumentException when not exactly one of $turnUserId and $turnLabel is set
     */
    public function __construct(
        public \DateTimeImmutable $oldestWaitingSince,
        public ?UserId $turnUserId,
        public ?string $turnLabel,
    ) {
        if ((null === $turnUserId) === (null === $turnLabel)) {
            throw new \InvalidArgumentException('A whose-turn cursor is after either a person\'s group or a label\'s.');
        }
    }

    public static function after(TurnGroup $group): self
    {
        return new self($group->oldestWaitingSince, $group->turnUserId, $group->turnLabel);
    }
}
