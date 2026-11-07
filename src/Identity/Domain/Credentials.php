<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface Credentials
{
    public function byUserId(UserId $userId): ?Credential;

    /**
     * Saves the credential at once.
     */
    public function add(Credential $credential): void;
}
