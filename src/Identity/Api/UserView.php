<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A person's id and name, for other modules to show.
 */
final readonly class UserView
{
    public function __construct(public UserId $id, public string $displayName)
    {
    }

    /**
     * The first word of the display name, as Home's greeting uses it; empty only for an empty name.
     */
    public function firstName(): string
    {
        return explode(' ', trim($this->displayName), 2)[0];
    }
}
