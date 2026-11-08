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

    /**
     * For a voter, which must decide on the token it is given rather than on the request's.
     *
     * @param mixed $tokenUser what the security token's getUser() returned
     *
     * @return UserId|null null when it is not a user Identity logged in, such as the null of an anonymous token
     */
    public function idOf(mixed $tokenUser): ?UserId;
}
