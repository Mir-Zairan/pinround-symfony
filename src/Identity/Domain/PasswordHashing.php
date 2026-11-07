<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface PasswordHashing
{
    /**
     * Hashes with the algorithm login verifies against (Argon2id, ADR-0012), with a fresh salt each time.
     */
    public function hash(NewPassword $password): PasswordHash;

    /**
     * Whether the typed password is the one the hash was made from, checked the way login checks it.
     */
    public function matches(PasswordHash $hash, #[\SensitiveParameter] string $typedPassword): bool;
}
