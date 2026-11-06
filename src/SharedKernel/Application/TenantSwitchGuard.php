<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * Runs before TenantContext moves from one organization to another one or to none, so state that belongs to the
 * old organization (such as loaded entities) does not carry over to the new one.
 */
interface TenantSwitchGuard
{
    /**
     * @throws TenantSwitchRefused when the switch would lose unsaved work; TenantContext then keeps its organization
     */
    public function prepareSwitch(): void;
}
