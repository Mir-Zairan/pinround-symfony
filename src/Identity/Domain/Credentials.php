<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

interface Credentials
{
    public function byUserId(UserId $userId): ?Credential;

    /**
     * Saves the credential at once.
     */
    public function add(Credential $credential): void;
}
