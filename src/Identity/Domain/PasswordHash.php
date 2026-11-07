<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * An encoded password hash in crypt form ("$argon2id$..."), never the plain password. It has no __toString on
 * purpose, so it cannot slip into a string, a message or a log by accident.
 */
final readonly class PasswordHash
{
    private string $encoded;

    /**
     * @throws \InvalidArgumentException when the value is not in crypt form (it must start with "$")
     */
    public function __construct(#[\SensitiveParameter] string $encoded)
    {
        // Refuses a plain password passed here by mistake, unless it happens to start with "$".
        if (!str_starts_with($encoded, '$')) {
            throw new \InvalidArgumentException('A password hash must be an encoded hash in crypt form.');
        }

        $this->encoded = $encoded;
    }

    public function encoded(): string
    {
        return $this->encoded;
    }
}
