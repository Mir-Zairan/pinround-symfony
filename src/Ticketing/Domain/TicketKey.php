<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The key people use for a ticket: its project's key, a hyphen and its number in the project ("SHOP-12"). Unique
 * within an organization.
 */
final readonly class TicketKey
{
    // \z, because $ would also accept a trailing newline; no leading zero, so one ticket has one key text.
    private const string FORMAT = '/^([A-Z][A-Z0-9]{1,9})-([1-9][0-9]*)\z/';

    /**
     * @throws InvalidTicketKey when the number is below 1
     */
    public function __construct(private ProjectKey $projectKey, private int $number)
    {
        if ($number < 1) {
            throw new InvalidTicketKey('A ticket number starts at 1.');
        }
    }

    /**
     * @throws InvalidTicketKey when the text is not a project key, a hyphen and a number from 1 up
     */
    public static function fromString(string $key): self
    {
        if (1 !== preg_match(self::FORMAT, $key, $parts)) {
            throw new InvalidTicketKey('A ticket key is a project key, a hyphen and a number, such as SHOP-12.');
        }

        return new self(new ProjectKey($parts[1]), (int) $parts[2]);
    }

    /**
     * Whether a ticket can have exactly this key text, so a lookup by a key no ticket can have costs no query: the
     * format, and the same text back, so a number past the integer range is refused too.
     */
    public static function canBe(string $key): bool
    {
        try {
            return self::fromString($key)->toString() === $key;
        } catch (InvalidTicketKey) {
            return false;
        }
    }

    public function projectKey(): ProjectKey
    {
        return $this->projectKey;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function toString(): string
    {
        return $this->projectKey->toString().'-'.$this->number;
    }

    public function equals(self $other): bool
    {
        return $this->projectKey->equals($other->projectKey) && $this->number === $other->number;
    }
}
