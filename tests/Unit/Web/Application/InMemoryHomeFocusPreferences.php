<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Application;

use App\SharedKernel\Domain\UserId;
use App\Web\Application\HomeFocusPreferences;
use App\Web\Domain\HomeFocus;

/**
 * HomeFocusPreferences of one organization, in memory.
 */
final class InMemoryHomeFocusPreferences implements HomeFocusPreferences
{
    /** @var array<string, array{HomeFocus, \DateTimeImmutable}> the focus and its time, by user id */
    public array $saved = [];

    public function focusOf(UserId $userId): ?HomeFocus
    {
        return $this->saved[$userId->toString()][0] ?? null;
    }

    public function remember(UserId $userId, HomeFocus $focus, \DateTimeImmutable $at): void
    {
        $this->saved[$userId->toString()] = [$focus, $at];
    }
}
