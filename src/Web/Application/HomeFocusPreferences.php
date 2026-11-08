<?php

declare(strict_types=1);

namespace App\Web\Application;

use App\SharedKernel\Domain\UserId;
use App\Web\Domain\HomeFocus;

/**
 * Each user's saved Home focus chip in the current organization (the tenant). Both methods run in the caller's
 * transaction, begun under that tenant (RequestTransaction on the web).
 */
interface HomeFocusPreferences
{
    /**
     * @return HomeFocus|null null when the user saved none in this organization, or no tenant is set
     */
    public function focusOf(UserId $userId): ?HomeFocus;

    /**
     * Saves $focus as the user's choice in this organization, replacing an earlier one.
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set
     * @throws \LogicException                            when no transaction is open
     */
    public function remember(UserId $userId, HomeFocus $focus, \DateTimeImmutable $at): void;
}
