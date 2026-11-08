<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Questions about people, for other modules.
 */
interface UserQueries
{
    /**
     * Reads with plain SQL and holds no entity, so it may run while another module's tenant is being switched.
     *
     * @return UserId|null null when no one has this address; the address is compared in lowercase, trimmed
     */
    public function idByEmail(string $email): ?UserId;
}
