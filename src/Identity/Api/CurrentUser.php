<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The person logged in for the current request, for other modules.
 */
interface CurrentUser
{
    /**
     * @return UserId|null null when nobody is logged in, as on a public page or before the firewall has run
     */
    public function id(): ?UserId;
}
