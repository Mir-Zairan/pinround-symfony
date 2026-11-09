<?php

declare(strict_types=1);

namespace App\Identity\Api;

interface UserQueries
{
    public function idByEmail(string $email): ?string;
}
