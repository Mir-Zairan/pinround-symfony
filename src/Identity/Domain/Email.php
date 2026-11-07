<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * An email address, trimmed and in lowercase, so one person has one address however it was typed. The column is
 * citext as well, so a row written another way is still found and still unique.
 */
final readonly class Email
{
    private string $value;

    /**
     * @throws InvalidEmail when the trimmed value is not a valid address
     */
    public function __construct(string $value)
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
            throw new InvalidEmail('This is not a valid email address.');
        }

        $this->value = $normalized;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
