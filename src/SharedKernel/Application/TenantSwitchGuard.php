<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * Runs before TenantContext moves from one organization to another one or to none, and refuses the move while
 * state of the current organization (an open transaction, loaded entities) could carry over to the next one.
 */
interface TenantSwitchGuard
{
    /**
     * Must only read: a refused switch leaves everything as it was.
     *
     * @throws TenantSwitchRefused when state of the current organization is still open; TenantContext then keeps it
     */
    public function prepareSwitch(): void;
}
